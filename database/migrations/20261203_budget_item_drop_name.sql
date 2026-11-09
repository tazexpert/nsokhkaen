-- ============================================================================
-- ระบบงบประมาณ: ลบคอลัมน์ bud_items.name (ไม่ใช้แล้ว) — ชื่อหมวดประกอบจาก งบรายจ่าย (รายการ) ค่าใช้จ่าย — รันซ้ำได้
--   ต้องรัน 20261202_budget_item_parts.sql ก่อน (แยก name เป็น budget_name / expense_name / activity_name)
--   ถ้ายังไม่มี 3 คอลัมน์ คำสั่งนี้จะ error ทั้งคำสั่งและไม่ลบ name
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261203_budget_item_drop_name.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE bud_items
  ADD UNIQUE KEY IF NOT EXISTS uq_bud_item_parts (budget_name, expense_name, activity_name),
  DROP INDEX IF EXISTS uq_bud_item_name,
  DROP COLUMN IF EXISTS name;
