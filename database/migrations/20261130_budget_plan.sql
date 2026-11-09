-- ============================================================================
-- ระบบงบประมาณ: แผนงาน → งานและโครงการ (ตัวคลุมหมวด) + รายละเอียดของรายการ — รันซ้ำได้
--   ใช้กับทะเบียนคุมเงินประจำงวดส่วนจังหวัด (แผนงาน · งานและโครงการ · หมวด)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261130_budget_plan.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- แผนงาน
CREATE TABLE IF NOT EXISTS bud_plans (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(255) NOT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bud_plan_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- งานและโครงการ (อยู่ภายใต้แผนงาน)
CREATE TABLE IF NOT EXISTS bud_works (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id      INT UNSIGNED NOT NULL,
  name         VARCHAR(255) NOT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bud_work_name (plan_id, name),
  CONSTRAINT fk_bud_work_plan FOREIGN KEY (plan_id) REFERENCES bud_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายการรับเข้า/เบิกจ่าย: งานและโครงการ + รายละเอียด
ALTER TABLE bud_txns
  ADD COLUMN IF NOT EXISTS work_id INT UNSIGNED NULL AFTER item_id,
  ADD COLUMN IF NOT EXISTS detail VARCHAR(1000) NOT NULL DEFAULT '' AFTER description;
ALTER TABLE bud_txns
  ADD CONSTRAINT fk_bud_txn_work FOREIGN KEY IF NOT EXISTS (work_id) REFERENCES bud_works(id) ON DELETE RESTRICT;
