-- ============================================================================
-- ระบบงบประมาณ: รายการรับเข้า/เบิกจ่ายระบุแผนงานได้โดยตรง (งานและโครงการ ไม่บังคับ) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261201_budget_txn_plan.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE bud_txns
  ADD COLUMN IF NOT EXISTS plan_id INT UNSIGNED NULL AFTER item_id;
ALTER TABLE bud_txns
  ADD CONSTRAINT fk_bud_txn_plan FOREIGN KEY IF NOT EXISTS (plan_id) REFERENCES bud_plans(id) ON DELETE RESTRICT;

-- รายการเดิม: แผนงานตามงานและโครงการที่เลือกไว้
UPDATE bud_txns t JOIN bud_works w ON w.id = t.work_id SET t.plan_id = w.plan_id WHERE t.plan_id IS NULL;
