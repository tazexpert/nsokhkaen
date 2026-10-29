-- ============================================================================
-- รูปโปรไฟล์ผู้ใช้ (บัญชีของฉัน → รูปโปรไฟล์) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261105_core_user_photo.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE core_users ADD COLUMN IF NOT EXISTS photo VARCHAR(255) NULL AFTER phone;   -- uploads/avatars/...
