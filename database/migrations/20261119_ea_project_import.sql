-- ============================================================================
-- ระบบ EA: ข้อมูลจากไฟล์ EA ของโครงการ (EA_Report.csv) — รันซ้ำได้
--   psu_no (เลข PSU), ประเภทการแจงนับ (Q1–Q4 / Month), เดือน/ปีที่แจงนับ, ปีงบประมาณ, พิกัด
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261119_ea_project_import.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE ea_project_eas
  ADD COLUMN IF NOT EXISTS psu_no      VARCHAR(10)   NOT NULL DEFAULT '' AFTER round_label,
  ADD COLUMN IF NOT EXISTS enum_type   VARCHAR(10)   NOT NULL DEFAULT '' AFTER psu_no,      -- Q1–Q4 = รายไตรมาส, Month = รายเดือน
  ADD COLUMN IF NOT EXISTS enum_month  TINYINT UNSIGNED NULL AFTER enum_type,               -- เดือนที่แจงนับ 1–12
  ADD COLUMN IF NOT EXISTS enum_year   SMALLINT UNSIGNED NULL AFTER enum_month,             -- ปีที่แจงนับ (พ.ศ.)
  ADD COLUMN IF NOT EXISTS fiscal_year SMALLINT UNSIGNED NULL AFTER enum_year,              -- ปีงบประมาณ (พ.ศ.)
  ADD COLUMN IF NOT EXISTS lat         DECIMAL(10,7) NULL AFTER fiscal_year,
  ADD COLUMN IF NOT EXISTS lng         DECIMAL(10,7) NULL AFTER lat,
  ADD INDEX IF NOT EXISTS idx_ea_pe_period (project_id, enum_year, enum_month);
