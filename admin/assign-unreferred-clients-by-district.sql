-- ---------------------------------------------------------------------------
-- Assign clients WITHOUT a referrer (and their loans) to a loan officer by
-- district:
--   Mbarara district -> Ruth Musiimenta
--   every other district (or none recorded) -> Esther Kayondo
--
-- "Without a referrer" means customers.referred_by is empty, or names someone
-- who is not an active loan officer (role 2). Clients who named an active
-- loan officer are NOT touched, and neither are staff borrowers, startups or
-- organisations.
--
-- Mbarara means: the customer's district is "Mbarara"; or, when no district is
-- recorded, their business location mentions Mbarara.
--
-- Run the whole file in phpMyAdmin (SQL tab) or the mysql client. It changes
-- nothing if either officer is not found exactly once as an active loan
-- officer. Safe to run again.
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

-- 2. Mark the clients to update (no referrer, or referrer not an active officer).
DROP TEMPORARY TABLE IF EXISTS tmp_unreferred;
CREATE TEMPORARY TABLE tmp_unreferred (
    user_id INT PRIMARY KEY,
    officer_id INT NULL
);

INSERT INTO tmp_unreferred (user_id, officer_id)
SELECT c.user_id,
       IF(LOWER(TRIM(COALESCE(c.district, ''))) = 'mbarara'
          OR (TRIM(COALESCE(c.district, '')) = '' AND LOWER(COALESCE(c.location, '')) LIKE '%mbarara%'),
          @ruth, @esther)
FROM customers c
LEFT JOIN user r ON r.id = c.referred_by AND r.role = 2 AND COALESCE(r.status, 1) = 1
WHERE r.id IS NULL;

-- Preview: clients without a referrer, where they go, and how many change.
SELECT CONCAT(u.fname, ' ', u.lname) AS goes_to,
       COUNT(*) AS clients,
       SUM(COALESCE(c.assigned_officer_id, 0) <> t.officer_id) AS will_change
FROM tmp_unreferred t
JOIN customers c ON c.user_id = t.user_id
LEFT JOIN user u ON u.id = t.officer_id
GROUP BY t.officer_id, u.fname, u.lname;

-- 3. Clients.
UPDATE customers c
JOIN tmp_unreferred t ON t.user_id = c.user_id
SET c.assigned_officer_id = t.officer_id
WHERE @ok;

-- 4. Their loans follow (organisation loans belong to the organisation).
UPDATE loan l
JOIN tmp_unreferred t ON t.user_id = l.client_id
SET l.assigned_officer_id = t.officer_id
WHERE @ok AND l.organisation_id IS NULL;

-- 5. Result: clients and loans per officer.
SELECT u.id, CONCAT(u.fname, ' ', u.lname) AS officer,
       (SELECT COUNT(*) FROM customers WHERE assigned_officer_id = u.id) AS customers,
       (SELECT COUNT(*) FROM customers WHERE assigned_officer_id = u.id
           AND user_id IN (SELECT user_id FROM tmp_unreferred)) AS of_which_unreferred,
       (SELECT COUNT(*) FROM loan WHERE assigned_officer_id = u.id) AS loans
FROM user u
WHERE u.role = 2 AND COALESCE(u.status, 1) = 1
ORDER BY customers DESC;

DROP TEMPORARY TABLE IF EXISTS tmp_unreferred;
