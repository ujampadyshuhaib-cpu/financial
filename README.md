# Daybook

A small PHP and MySQL app for tracking daily income and expenses.

## Setup

1. Start Apache and MySQL (for example, in XAMPP).
2. Import `database.sql` into MySQL using phpMyAdmin or the MySQL command line. The script creates the `daily_finance` database and `transactions` table.
3. If your MySQL connection differs from the defaults, set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the PHP environment. Defaults are `127.0.0.1:3306`, database `daily_finance`, user `root`, and an empty password.
4. Put the project in your web server's document root and open `http://localhost/money/`.

The app requires PHP with the PDO MySQL extension enabled.
