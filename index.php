<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';

$incomeCategories = ['Salary', 'Freelance', 'Business', 'Investment', 'Other'];
$expenseCategories = ['Food', 'Transport', 'Bills', 'Shopping', 'Health', 'Entertainment', 'Other'];
$categories = [
    'income' => $incomeCategories,
    'expense' => $expenseCategories,
];

$today = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $today;
if (!is_string($selectedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
}
$dateParts = explode('-', $selectedDate);
if (count($dateParts) !== 3 || !checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
    $selectedDate = $today;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$databaseError = false;
$transactions = [];
$totals = ['income' => '0.00', 'expense' => '0.00'];

try {
    $pdo = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(400);
            $flash = ['type' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'];
        } elseif (($_POST['action'] ?? '') === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id === null || $id < 1) {
                $flash = ['type' => 'error', 'message' => 'That transaction could not be found.'];
            } else {
                $delete = $pdo->prepare('DELETE FROM transactions WHERE id = :id');
                $delete->execute(['id' => $id]);
                $flash = $delete->rowCount() > 0
                    ? ['type' => 'success', 'message' => 'Transaction deleted.']
                    : ['type' => 'error', 'message' => 'That transaction could not be found.'];
            }
        } else {
            $type = $_POST['type'] ?? null;
            $category = $_POST['category'] ?? null;
            $amount = $_POST['amount'] ?? null;
            $descriptionInput = $_POST['description'] ?? '';
            $description = is_string($descriptionInput) ? trim($descriptionInput) : '';
            $transactionDate = $_POST['transaction_date'] ?? null;

            $validAmount = is_string($amount) && preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount);
            $validDate = is_string($transactionDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate);
            if ($validDate) {
                $parts = explode('-', $transactionDate);
                $validDate = checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
            }

            if (
                !is_string($type)
                || !isset($categories[$type])
                || !is_string($category)
                || !in_array($category, $categories[$type], true)
                || !$validAmount
                || (float) $amount <= 0
                || !$validDate
                || !is_string($descriptionInput)
                || strlen($description) > 255
            ) {
                $flash = ['type' => 'error', 'message' => 'Please check the transaction details and try again.'];
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO transactions (type, category, amount, description, transaction_date)
                     VALUES (:type, :category, :amount, :description, :transaction_date)'
                );
                $insert->execute([
                    'type' => $type,
                    'category' => $category,
                    'amount' => $amount,
                    'description' => $description !== '' ? $description : null,
                    'transaction_date' => $transactionDate,
                ]);
                $selectedDate = $transactionDate;
                $flash = ['type' => 'success', 'message' => 'Transaction added.'];
            }
        }

        if (!empty($_POST['transaction_date']) && is_string($_POST['transaction_date'])) {
            $postedDate = $_POST['transaction_date'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedDate)) {
                $dateParts = explode('-', $postedDate);
                if (checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
                    $selectedDate = $postedDate;
                }
            }
        }

        $_SESSION['flash'] = $flash;
        header('Location: ?date=' . urlencode($selectedDate));
        exit;
    }

    $summary = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense
         FROM transactions
         WHERE transaction_date = :transaction_date"
    );
    $summary->execute(['transaction_date' => $selectedDate]);
    $totals = $summary->fetch(PDO::FETCH_ASSOC) ?: $totals;

    $list = $pdo->prepare(
        'SELECT id, type, category, amount, description, transaction_date
         FROM transactions
         WHERE transaction_date = :transaction_date
         ORDER BY created_at DESC, id DESC'
    );
    $list->execute(['transaction_date' => $selectedDate]);
    $transactions = $list->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    $databaseError = true;
}

$balance = (float) $totals['income'] - (float) $totals['expense'];
$previousDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatMoney(string|float $amount): string
{
    return number_format((float) $amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7f5">
    <title>Daybook — Daily finances</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-shell">
        <header class="topbar">
            <a class="brand" href="index.php" aria-label="Daybook home">
                <span class="brand-mark" aria-hidden="true">D</span>
                <span>daybook<span class="brand-period">.</span></span>
            </a>
            <span class="topbar-note">Your money, in focus.</span>
        </header>

        <main>
            <section class="intro">
                <div>
                    <p class="eyebrow">PERSONAL FINANCE</p>
                    <h1>A clearer view<br class="mobile-break"> of your day.</h1>
                    <p class="intro-copy">Keep track of what comes in and what goes out.</p>
                </div>
                <form class="date-picker" method="get">
                    <label for="date">Viewing day</label>
                    <div class="date-controls">
                        <a class="date-arrow" href="?date=<?= escapeHtml($previousDate) ?>" aria-label="Previous day">&lsaquo;</a>
                        <input id="date" name="date" type="date" value="<?= escapeHtml($selectedDate) ?>" onchange="this.form.submit()">
                        <a class="date-arrow" href="?date=<?= escapeHtml($nextDate) ?>" aria-label="Next day">&rsaquo;</a>
                    </div>
                </form>
            </section>

            <?php if ($databaseError): ?>
                <div class="notice notice-error" role="alert">
                    <strong>Your database is unavailable or not set up.</strong>
                    <span>Start MySQL, import <code>database.sql</code>, and check the connection settings in <code>db.php</code>.</span>
                </div>
            <?php elseif ($flash): ?>
                <div class="notice notice-<?= escapeHtml($flash['type']) ?>" role="status"><?= escapeHtml($flash['message']) ?></div>
            <?php endif; ?>

            <section class="summary-grid" aria-label="Daily summary">
                <article class="summary-card balance-card">
                    <div class="card-topline">
                        <span class="summary-label">Net for the day</span>
                        <span class="summary-icon balance-icon" aria-hidden="true">=</span>
                    </div>
                    <p class="summary-amount">$<?= formatMoney($balance) ?></p>
                    <p class="summary-hint">Income minus expenses</p>
                </article>
                <article class="summary-card">
                    <div class="card-topline">
                        <span class="summary-label">Income</span>
                        <span class="summary-icon income-icon" aria-hidden="true">↗</span>
                    </div>
                    <p class="summary-amount">$<?= formatMoney($totals['income']) ?></p>
                    <p class="summary-hint">Money received today</p>
                </article>
                <article class="summary-card">
                    <div class="card-topline">
                        <span class="summary-label">Expenses</span>
                        <span class="summary-icon expense-icon" aria-hidden="true">↘</span>
                    </div>
                    <p class="summary-amount">$<?= formatMoney($totals['expense']) ?></p>
                    <p class="summary-hint">Money spent today</p>
                </article>
            </section>

            <div class="content-grid">
                <section class="panel transactions-panel">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">YOUR ACTIVITY</p>
                            <h2>Transactions</h2>
                        </div>
                        <span class="entry-count"><?= count($transactions) ?> <?= count($transactions) === 1 ? 'entry' : 'entries' ?></span>
                    </div>

                    <?php if (!$transactions): ?>
                        <div class="empty-state">
                            <span class="empty-icon" aria-hidden="true">—</span>
                            <h3>A fresh start.</h3>
                            <p>No transactions for this day yet. Add one to get going.</p>
                        </div>
                    <?php else: ?>
                        <div class="transaction-list">
                            <?php foreach ($transactions as $transaction): ?>
                                <article class="transaction-row">
                                    <span class="transaction-symbol <?= $transaction['type'] === 'income' ? 'symbol-income' : 'symbol-expense' ?>" aria-hidden="true">
                                        <?= $transaction['type'] === 'income' ? '↗' : '↘' ?>
                                    </span>
                                    <div class="transaction-info">
                                        <span class="transaction-category"><?= escapeHtml($transaction['category']) ?></span>
                                        <span class="transaction-description"><?= escapeHtml($transaction['description'] ?: $transaction['type']) ?></span>
                                    </div>
                                    <span class="transaction-amount <?= $transaction['type'] === 'income' ? 'amount-income' : 'amount-expense' ?>">
                                        <?= $transaction['type'] === 'income' ? '+' : '−' ?>$<?= formatMoney($transaction['amount']) ?>
                                    </span>
                                    <form method="post" class="delete-form" onsubmit="return confirm('Delete this transaction?');">
                                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $transaction['id'] ?>">
                                        <button class="delete-button" type="submit" aria-label="Delete <?= escapeHtml($transaction['category']) ?> transaction">&times;</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel form-panel">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">QUICK ENTRY</p>
                            <h2>Add a transaction</h2>
                        </div>
                    </div>
                    <form class="transaction-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">

                        <fieldset class="type-toggle">
                            <legend>Type</legend>
                            <label class="type-option">
                                <input type="radio" name="type" value="expense" checked>
                                <span>Expense</span>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="type" value="income">
                                <span>Income</span>
                            </label>
                        </fieldset>

                        <label class="field-label" for="amount">Amount</label>
                        <div class="amount-input">
                            <span aria-hidden="true">$</span>
                            <input id="amount" name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" placeholder="0.00" required>
                        </div>

                        <label class="field-label" for="category">Category</label>
                        <select id="category" name="category" required>
                            <?php foreach ($expenseCategories as $category): ?>
                                <option value="<?= escapeHtml($category) ?>" data-type="expense"><?= escapeHtml($category) ?></option>
                            <?php endforeach; ?>
                            <?php foreach ($incomeCategories as $category): ?>
                                <option value="<?= escapeHtml($category) ?>" data-type="income" hidden><?= escapeHtml($category) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="field-label" for="description">Note <span class="optional">(optional)</span></label>
                        <input class="text-input" id="description" name="description" type="text" maxlength="255" placeholder="What was it for?">

                        <label class="field-label" for="transaction_date">Date</label>
                        <input class="text-input" id="transaction_date" name="transaction_date" type="date" value="<?= escapeHtml($selectedDate) ?>" required>

                        <button class="submit-button" type="submit" <?= $databaseError ? 'disabled' : '' ?>>Add transaction <span aria-hidden="true">→</span></button>
                    </form>
                </section>
            </div>
        </main>

        <footer class="footer">Small steps make a big difference.</footer>
    </div>
    <script>
        const typeInputs = document.querySelectorAll('input[name="type"]');
        const categorySelect = document.getElementById('category');

        function updateCategories() {
            const selectedType = document.querySelector('input[name="type"]:checked').value;
            const options = Array.from(categorySelect.options);
            options.forEach((option) => {
                option.hidden = option.dataset.type !== selectedType;
            });
            categorySelect.value = options.find((option) => option.dataset.type === selectedType).value;
        }

        typeInputs.forEach((input) => input.addEventListener('change', updateCategories));
    </script>
</body>
</html>
