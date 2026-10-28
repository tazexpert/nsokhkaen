-- ============================================================================
-- ปรับโครงสร้าง 2026-10-30: ผู้ใช้งานประจำของครุภัณฑ์ (คอลัมน์ "ผู้ยืม/ผู้ใช้งาน")
-- ใช้กับฐานข้อมูลที่รัน migration ก่อนหน้าไปแล้ว (รันซ้ำได้)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261030_inv_equipment_assigned_user.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE inv_equipment
  ADD COLUMN IF NOT EXISTS assigned_user_id INT UNSIGNED NULL AFTER storage_location;
ALTER TABLE inv_equipment
  ADD CONSTRAINT fk_inv_equipment_assigned FOREIGN KEY IF NOT EXISTS (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL;
