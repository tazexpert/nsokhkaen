-- ============================================================================
-- ผู้ใช้งาน: ชื่อเล่น (แสดงในหน้า บุคลากรทั้งหมด) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261107_core_user_nickname.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE core_users ADD COLUMN IF NOT EXISTS nickname VARCHAR(50) NULL AFTER phone;
