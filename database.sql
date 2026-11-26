CREATE DATABASE IF NOT EXISTS daily_finance
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE daily_finance;

CREATE TABLE IF NOT EXISTS transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type ENUM('income', 'expense') NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    description VARCHAR(255) NULL,
    transaction_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_transactions_date_type (transaction_date, type),
    CONSTRAINT chk_transactions_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB;
