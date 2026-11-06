-- ============================================================================
-- ระบบ EA: องค์กรปกครองส่วนท้องถิ่น (เทศบาล / อบต.) ของ EA — รันซ้ำได้
--   รหัส EA 15 หลัก = ภาค(1) + จังหวัด(2) + อำเภอ(2) + ตำบล(2) + เขตการปกครอง(1) + รหัสเทศบาล/อบต.(3) + EA(4)
--   เช่น 6 40 01 01 1 999 0001 (999 = เทศบาลนครขอนแก่น) · 6 40 01 03 2 002 0001 (002 = อบต.โคกสี)
--   นำเข้าไฟล์ SurveyEaHome.xlsx ที่ EA → นำเข้าบัญชี EA เพื่อปรับรหัส EA และชื่อเทศบาล/อบต. ให้ถูกต้อง
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261122_ea_local_org.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE ea_areas
  ADD COLUMN IF NOT EXISTS local_code VARCHAR(3)   NOT NULL DEFAULT '' AFTER area_type,   -- รหัสเทศบาล / อบต. (หลักที่ 9–11 ของรหัส EA)
  ADD COLUMN IF NOT EXISTS local_name VARCHAR(150) NOT NULL DEFAULT '' AFTER local_code;  -- ชื่อเทศบาล / อบต.
