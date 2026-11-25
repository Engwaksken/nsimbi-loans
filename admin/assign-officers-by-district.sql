-- ---------------------------------------------------------------------------
-- Assign every client, and their loans, to a loan officer by district:
--   Mbarara district -> Ruth Musiimenta
--   every other district (or none recorded) -> Esther Kayondo
-- This overrides referrals: clients who named a referring officer are moved
-- too (the Officer Referrals leaderboard is not changed).
--
-- Run after deploying the code (the assigned_officer_id columns are added by
-- the app on the first admin page load). Run the whole file in phpMyAdmin
-- (SQL tab) or the mysql client. It changes nothing if either officer is not
-- found exactly once as an active loan officer (role 2).
--
-- Mbarara means: the customer's district is "Mbarara"; or, when no district is
-- recorded, their business location mentions Mbarara. Staff borrowers,
-- startups and organisations: their address mentions Mbarara.
--
-- Afterwards, do not press "Assign referred clients to their officers" on
-- Officer Referrals: that moves referred clients back to their referrer.
-- New sign-ups are still assigned automatically (referrer, else district).
-- ---------------------------------------------------------------------------

-- 1. The two officers (each must match exactly one active loan officer).
SET @ruth = (
    SELECT IF(COUNT(*) = 1, MAX(id), NULL) FROM user
    WHERE role = 2 AND COALESCE(status, 1) = 1
      AND LOWER(CONCAT(TRIM(fname), ' ', TRIM(lname))) = 'ruth musiimenta'
);
SET @esther = (
    SELECT IF(COUNT(*) = 1, MAX(id), NULL) FROM user
    WHERE role = 2 AND COALESCE(status, 1) = 1
      AND LOWER(CONCAT(TRIM(fname), ' ', TRIM(lname))) = 'esther kayondo'
);
SET @ok = (@ruth IS NOT NULL AND @esther IS NOT NULL);

-- Check: both ids must be filled in, ok = 1. If not, fix the names in Users
-- (or set the ids by hand: SET @ruth = 12; SET @esther = 15; SET @ok = 1;).
SELECT @ruth AS ruth_musiimenta_id, @esther AS esther_kayondo_id, @ok AS ok;

-- 2. Preview: how many clients go to each officer.
SELECT 'customers' AS clients,
       SUM(LOWER(TRIM(COALESCE(district, ''))) = 'mbarara'
           OR (TRIM(COALESCE(district, '')) = '' AND LOWER(COALESCE(location, '')) LIKE '%mbarara%')) AS to_ruth,
       COUNT(*) - SUM(LOWER(TRIM(COALESCE(district, ''))) = 'mbarara'
           OR (TRIM(COALESCE(district, '')) = '' AND LOWER(COALESCE(location, '')) LIKE '%mbarara%')) AS to_esther
FROM customers
UNION ALL
SELECT 'staff borrowers', SUM(LOWER(COALESCE(home_address, '')) LIKE '%mbarara%'),
       COUNT(*) - SUM(LOWER(COALESCE(home_address, '')) LIKE '%mbarara%') FROM members
UNION ALL
SELECT 'startups', SUM(LOWER(COALESCE(company_location, '')) LIKE '%mbarara%'),
       COUNT(*) - SUM(LOWER(COALESCE(company_location, '')) LIKE '%mbarara%') FROM startups
UNION ALL
SELECT 'organisations', SUM(LOWER(COALESCE(company_location, '')) LIKE '%mbarara%'),
       COUNT(*) - SUM(LOWER(COALESCE(company_location, '')) LIKE '%mbarara%') FROM organisations;

-- 3. Clients.
UPDATE customers
SET assigned_officer_id = IF(
        LOWER(TRIM(COALESCE(district, ''))) = 'mbarara'
        OR (TRIM(COALESCE(district, '')) = '' AND LOWER(COALESCE(location, '')) LIKE '%mbarara%'),
        @ruth, @esther)
WHERE @ok;

UPDATE members
SET assigned_officer_id = IF(LOWER(COALESCE(home_address, '')) LIKE '%mbarara%', @ruth, @esther)
WHERE @ok;

UPDATE startups
SET assigned_officer_id = IF(LOWER(COALESCE(company_location, '')) LIKE '%mbarara%', @ruth, @esther)
WHERE @ok;

UPDATE organisations
SET assigned_officer_id = IF(LOWER(COALESCE(company_location, '')) LIKE '%mbarara%', @ruth, @esther)
WHERE @ok;

-- 4. Loans follow their client's officer.
--    Startups first, so that a customer or staff loan whose client id happens
--    to equal a startup id is corrected by the customer/staff updates after it.
UPDATE loan l
JOIN startups st ON st.id = l.client_id
SET l.assigned_officer_id = st.assigned_officer_id
WHERE @ok AND l.organisation_id IS NULL;

UPDATE loan l
JOIN customers c ON c.user_id = l.client_id
SET l.assigned_officer_id = c.assigned_officer_id
WHERE @ok AND l.organisation_id IS NULL;

UPDATE loan l
JOIN members m ON m.user_id = l.client_id
SET l.assigned_officer_id = m.assigned_officer_id
WHERE @ok AND l.organisation_id IS NULL;

UPDATE loan l
JOIN organisations o ON o.id = l.organisation_id
SET l.assigned_officer_id = o.assigned_officer_id
WHERE @ok;

-- Loans with no matching client: the default officer.
UPDATE loan SET assigned_officer_id = @esther
WHERE @ok AND (assigned_officer_id IS NULL OR assigned_officer_id = 0);

-- 5. Keep the app from undoing this: record that the one-time referral pass
--    has run, and set the district rules to match.
INSERT INTO app_settings (setting_key, setting_value)
SELECT 'officer_referral_assignment', CONCAT('skipped: assigned by district SQL ', NOW()) FROM DUAL WHERE @ok
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO app_settings (setting_key, setting_value)
SELECT 'officer_district_rules', CONCAT('{"districts":{"Mbarara":', @ruth, '},"default":', @esther, '}') FROM DUAL WHERE @ok
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO app_settings (setting_key, setting_value)
SELECT 'officer_assignment_bootstrap', CONCAT('assigned by district SQL ', NOW()) FROM DUAL WHERE @ok
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 6. Result: clients and loans per officer.
SELECT u.id, CONCAT(u.fname, ' ', u.lname) AS officer,
       (SELECT COUNT(*) FROM customers WHERE assigned_officer_id = u.id) AS customers,
       (SELECT COUNT(*) FROM members WHERE assigned_officer_id = u.id) AS staff_borrowers,
       (SELECT COUNT(*) FROM startups WHERE assigned_officer_id = u.id) AS startups,
       (SELECT COUNT(*) FROM organisations WHERE assigned_officer_id = u.id) AS organisations,
       (SELECT COUNT(*) FROM loan WHERE assigned_officer_id = u.id) AS loans
FROM user u
WHERE u.id IN (@ruth, @esther);
