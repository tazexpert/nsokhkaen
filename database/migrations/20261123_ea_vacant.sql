-- ============================================================================
-- ระบบ EA: จำนวนบ้านว่าง (จากไฟล์ EaDetail.xlsx) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261123_ea_vacant.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE ea_areas
  ADD COLUMN IF NOT EXISTS vacant_houses INT UNSIGNED NOT NULL DEFAULT 0 AFTER households;   -- จำนวนบ้านว่าง
