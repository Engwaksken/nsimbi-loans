-- Run once against the application database before enabling this category.
ALTER TABLE customers
    ADD COLUMN client_category VARCHAR(40) NOT NULL DEFAULT 'business' AFTER business_type;

ALTER TABLE loan
    ADD COLUMN collateral_description TEXT NULL,
    ADD COLUMN collateral_document VARCHAR(255) NULL,
    ADD COLUMN loan_category VARCHAR(40) NOT NULL DEFAULT 'business';
