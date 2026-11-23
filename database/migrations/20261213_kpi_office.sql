-- ============================================================================
-- ระบบตัวชี้วัด: ระบุว่าเป็น "ตัวชี้วัดจากสำนักงาน" หรือไม่ (kpi_indicators.is_office) — รันซ้ำได้
--   1 = ตัวชี้วัดจากสำนักงาน (ค่าเริ่มต้น) · 0 = ไม่ใช่ (ตัวชี้วัดภายใน/อื่น ๆ)
--   เซิร์ฟเวอร์ที่ไม่มีสิทธิ์ ALTER TABLE: ใช้สคริปต์สร้างตารางใหม่แทน (ดู docs/MIGRATION.md)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261213_kpi_office.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE kpi_indicators ADD COLUMN IF NOT EXISTS is_office TINYINT(1) NOT NULL DEFAULT 1 AFTER round_no;
