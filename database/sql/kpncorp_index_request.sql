-- =============================================================================
-- Index request for the corporate database (kpncorp) — for the DBA
-- =============================================================================
--
-- The Talent Management app (kpn-tmp) reads kpncorp read-only. In the copy of
-- `employees` it was built against, the table has NO index except its primary
-- key `id`, so every lookup below is a full table scan. A typical page runs
-- 5–15 of them (who the user is, who their team is, who they may see, list
-- filters), so the cost grows with every employee added.
--
-- Nothing here changes data. All statements are online on MySQL 8
-- (ALGORITHM=INPLACE, LOCK=NONE): reads and writes continue while they run.
--
-- STEP 1 — check what production already has. Skip any index below whose
-- columns are already covered by an existing index (same leading column).
-- -----------------------------------------------------------------------------

SELECT table_name, index_name,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('employees', 'employee_movements')
GROUP BY table_name, index_name
ORDER BY table_name, index_name;

-- STEP 2 — the indexes.
-- -----------------------------------------------------------------------------

-- Every "this employee" lookup: the signed-in user's record, access checks for
-- one employee, approval chains, profile pages. Hit on every request.
-- (If employee_id is guaranteed unique in production, make this UNIQUE.)
ALTER TABLE employees
    ADD INDEX idx_employees_employee_id (employee_id),
    ALGORITHM = INPLACE, LOCK = NONE;

-- "Who reports to this manager": the team list, the Team menu check, the
-- default approval chain. Queried as manager_l1_id = ? OR manager_l2_id = ?,
-- which MySQL answers with an index merge of these two.
ALTER TABLE employees
    ADD INDEX idx_employees_manager_l1 (manager_l1_id),
    ADD INDEX idx_employees_manager_l2 (manager_l2_id),
    ALGORITHM = INPLACE, LOCK = NONE;

-- Role scopes (business unit / company / location) and the list filters. Also
-- the DISTINCT lists that fill the filter dropdowns.
ALTER TABLE employees
    ADD INDEX idx_employees_group_company (group_company),
    ADD INDEX idx_employees_company_name (company_name),
    ADD INDEX idx_employees_office_area (office_area),
    ALGORITHM = INPLACE, LOCK = NONE;

-- The profile's internal-movement history (employee_id + attribute). Only if
-- STEP 1 shows nothing on employee_id for this table.
ALTER TABLE employee_movements
    ADD INDEX idx_employee_movements_employee_attribute (employee_id, attribute),
    ALGORITHM = INPLACE, LOCK = NONE;

-- =============================================================================
-- Already fine in the copy we checked (no action unless production differs):
--   users(employee_id) unique, formal_educations / experience / certifications
--   (employee_id), performance_appraisals(employee_id).
--
-- Deliberately NOT requested: name search uses LIKE '%term%', which no ordinary
-- index can serve. If search over the full employee table proves slow, a
-- FULLTEXT index on employees(fullname) is the follow-up, but it needs a query
-- change on the app side too.
-- =============================================================================
