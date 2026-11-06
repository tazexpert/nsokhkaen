-- ============================================================================
-- ระบบ EA: รหัส EA 15 หลัก — รันซ้ำได้
--   ภาค(1) + จังหวัด(2) + อำเภอ(2) + ตำบล(2) + เขตการปกครอง(1) + 3 หลัก + EA(4)
--   (3 หลัก = รหัสเทศบาล/อบต. — ไฟล์นี้ใช้รหัสหมู่บ้าน/999 ชั่วคราว ดู 20261122_ea_local_org.sql และนำเข้า SurveyEaHome)
--   เช่น 6 40 01 01 1 999 0001 = 640010119990001
--   EA ไม่ซ้ำกันตาม จังหวัด+อำเภอ+ตำบล+เขตการปกครอง+EA (ใช้จับคู่เมื่อนำเข้าซ้ำ แม้รหัสหมู่บ้านเปลี่ยน)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261121_ea_code15.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE ea_areas MODIFY ea_code VARCHAR(15) NOT NULL;

-- EA ที่ไม่มีรหัสภาค (เพิ่มจากไฟล์ EA ของโครงการ): ใช้ภาคของจังหวัดเดียวกัน
UPDATE ea_areas a JOIN (SELECT province_code, MAX(region_code) rc, MAX(region_name) rn FROM ea_areas WHERE region_code <> '' GROUP BY province_code) x
  ON x.province_code = a.province_code
SET a.region_code = x.rc, a.region_name = IF(a.region_name = '', x.rn, a.region_name)
WHERE a.region_code = '';

UPDATE ea_areas SET ea_code = CONCAT(region_code, province_code, district_code, tambon_code, area_type,
                                     IF(village_code = '', '999', LPAD(village_code, 3, '0')), ea_no);

CREATE UNIQUE INDEX IF NOT EXISTS uq_ea_area_key ON ea_areas (province_code, district_code, tambon_code, area_type, ea_no);
