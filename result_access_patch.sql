-- ============================================================
-- EduManage Pro — Result Access Override Patch
-- Run ONCE in phpMyAdmin SQL tab
-- ============================================================
-- Adds result_access_override to students table.
-- When 1: admin has granted this student access to results
--         even if they have unpaid fees.
-- When 0 (default): normal fee-gate applies.
-- ============================================================

ALTER TABLE `students`
    ADD COLUMN `result_access_override` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Admin override: 1 = allow result access despite unpaid fees'
        AFTER `medical_notes`;

-- Verify
SELECT student_id, full_name, result_access_override
FROM students LIMIT 5;
