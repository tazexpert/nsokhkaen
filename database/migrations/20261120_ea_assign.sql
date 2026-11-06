-- ============================================================================
-- ระบบ EA: บันทึกเวลา/ผู้มอบหมายผู้ปฏิบัติงาน (ใช้แจ้งเตือนผู้ได้รับมอบหมาย และหน้าสุ่มผู้ปฏิบัติงาน) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261120_ea_assign.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE ea_project_eas
  ADD COLUMN IF NOT EXISTS assigned_at DATETIME     NULL AFTER assigned_user_id,     -- เวลาที่มอบหมายผู้รับผิดชอบล่าสุด
  ADD COLUMN IF NOT EXISTS assigned_by INT UNSIGNED NULL AFTER assigned_at;

UPDATE ea_project_eas SET assigned_at = updated_at WHERE assigned_user_id IS NOT NULL AND assigned_at IS NULL;
