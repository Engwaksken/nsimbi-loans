-- Mobile bookkeeping schema (books_api.php).
-- Idempotent — safe to run more than once.
-- NOTE: `book_inputs.user_id` and `book_entries.book_input_id` are INT here;
-- if your `book_entries.user_id` is BIGINT, widen these to match before running.

-- 1) Voice/parse input table (draft storage for parse_book_input / confirm_book_entries).
CREATE TABLE IF NOT EXISTS book_inputs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    raw_text TEXT NOT NULL,
    language VARCHAR(10) NULL,
    suggested_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_book_inputs_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Add any missing book_entries columns. Each ALTER only runs if the column
--    is absent, so re-running this script is a no-op.
DELIMITER $$
DROP PROCEDURE IF EXISTS ensure_book_entries_columns$$
CREATE PROCEDURE ensure_book_entries_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'book_entries'
          AND COLUMN_NAME = 'book_input_id'
    ) THEN
        ALTER TABLE book_entries
            ADD COLUMN book_input_id INT UNSIGNED NULL AFTER user_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'book_entries'
          AND COLUMN_NAME = 'currency'
    ) THEN
        ALTER TABLE book_entries
            ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'UGX' AFTER amount;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'book_entries'
          AND COLUMN_NAME = 'language'
    ) THEN
        ALTER TABLE book_entries
            ADD COLUMN language VARCHAR(10) NULL AFTER currency;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'book_entries'
          AND COLUMN_NAME = 'created_at'
    ) THEN
        ALTER TABLE book_entries
            ADD COLUMN created_at DATETIME NULL DEFAULT NULL AFTER status;
    END IF;
END$$
DELIMITER ;

CALL ensure_book_entries_columns();
DROP PROCEDURE ensure_book_entries_columns;

-- 3) Backfill created_at for rows that existed before the column was added
--    (the web dashboard did not write it). Safe no-op on subsequent runs.
UPDATE book_entries SET created_at = entry_date WHERE created_at IS NULL;
