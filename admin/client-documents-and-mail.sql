-- Client documents, loan-limit reviews and the portal mailbox.
--
-- These tables are also created automatically the first time the features
-- are used (includes/client_documents.php, includes/portal_mail.php). Run this
-- once instead if the database user is not allowed to CREATE TABLE.

CREATE TABLE IF NOT EXISTS client_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    doc_type VARCHAR(40) NOT NULL,              -- id_front, id_back, business_photo, trading_license, other
    title VARCHAR(150) NOT NULL DEFAULT '',
    file_path VARCHAR(255) NOT NULL,            -- relative to /storage/client_documents/
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    mime_type VARCHAR(100) NOT NULL DEFAULT '',
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, approved, rejected
    review_note VARCHAR(255) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_client_documents_user (user_id),
    KEY idx_client_documents_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS limit_review_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    current_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
    requested_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
    approved_limit DECIMAL(15,2) NULL,
    reason TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, approved, declined
    staff_note VARCHAR(255) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_limit_review_user (user_id),
    KEY idx_limit_review_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_threads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(200) NOT NULL,
    created_by INT NOT NULL,
    last_message_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mail_threads_last (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    sender_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mail_messages_thread (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_participants (
    thread_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_starred TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    has_sent TINYINT(1) NOT NULL DEFAULT 0,      -- shows in this user's Sent
    has_received TINYINT(1) NOT NULL DEFAULT 0,  -- shows in this user's Inbox
    PRIMARY KEY (thread_id, user_id),
    KEY idx_mail_participants_user (user_id, is_archived, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
