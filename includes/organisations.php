<?php
declare(strict_types=1);

/**
 * Organisations that can be given loans (People > Organisations).
 *
 * A loan to an organisation stores the organisation in loan.organisation_id
 * (and loan.client_id = organisations.id, client_type = 'organisation' where
 * that column exists). Pages must use organisation_id to tell organisation
 * loans apart: organisation ids overlap user ids, so client_id alone could
 * match an unrelated customer or staff member.
 */

/**
 * Create the organisations table and loan.organisation_id on first use
 * (table as in admin/organisation-loans.sql).
 */
function organisations_ensure_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    organisations_ensure_loan_column($conn);
    $conn->query("
        CREATE TABLE IF NOT EXISTS organisations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(190) NOT NULL,
            ceo_name VARCHAR(190) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            email VARCHAR(190) NOT NULL,
            bank VARCHAR(190) NOT NULL,
            account_name VARCHAR(190) NOT NULL,
            account_number VARCHAR(100) NOT NULL,
            bank_branch VARCHAR(190) NOT NULL,
            company_location VARCHAR(255) NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_organisations_status_name (status, company_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * loan.organisation_id: added once, then filled for organisation loans made
 * before it existed (identified by loan.client_type when that column exists).
 */
function organisations_ensure_loan_column(mysqli $conn): void
{
    try {
        $res = $conn->query("SHOW COLUMNS FROM loan LIKE 'organisation_id'");
        if ($res && $res->num_rows > 0) {
            return;
        }
        $conn->query('ALTER TABLE loan ADD COLUMN organisation_id BIGINT UNSIGNED NULL, ADD KEY idx_loan_organisation (organisation_id)');
        $type = $conn->query("SHOW COLUMNS FROM loan LIKE 'client_type'");
        if ($type && $type->num_rows > 0) {
            $conn->query("
                UPDATE loan SET organisation_id = client_id
                WHERE LOWER(TRIM(COALESCE(client_type, ''))) = 'organisation' AND organisation_id IS NULL
            ");
        }
    } catch (Throwable $e) {
        error_log('Adding loan.organisation_id failed: ' . $e->getMessage());
    }
}

/** An organisation's row by id, or null. */
function organisation_find(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    organisations_ensure_table($conn);
    $stmt = $conn->prepare('SELECT * FROM organisations WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}
