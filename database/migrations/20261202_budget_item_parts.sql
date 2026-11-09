-- ============================================================================
-- ระบบงบประมาณ: แยกหมวดเป็น 3 ส่วน งบประมาณ · ค่าใช้จ่าย · กิจกรรม — รันซ้ำได้
--   โครงสร้าง: แผนงาน → งานและโครงการ → งบประมาณ → ค่าใช้จ่าย → กิจกรรม
--   ทะเบียนคุมเงินประจำงวด / รายงาน รวม 3 ส่วนเป็น "หมวด" เหมือนเดิม: งบประมาณ (ค่าใช้จ่าย) กิจกรรม
--   ย้ายข้อมูลเดิม: แยกจากชื่อหมวด เช่น "งบดำเนินงาน (ค่าตอบแทนใช้สอยฯ) ค่าน้ำมันเชื้อเพลิง"
--     → งบดำเนินงาน · ค่าตอบแทนใช้สอยฯ · ค่าน้ำมันเชื้อเพลิง  (รายการรับเข้า/เบิกจ่ายไม่เปลี่ยน — ยังผูกกับหมวดเดิม)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261202_budget_item_parts.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE bud_items
  ADD COLUMN IF NOT EXISTS budget_name   VARCHAR(100) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN IF NOT EXISTS expense_name  VARCHAR(150) NOT NULL DEFAULT '' AFTER budget_name,
  ADD COLUMN IF NOT EXISTS activity_name VARCHAR(150) NOT NULL DEFAULT '' AFTER expense_name;

-- ขั้นตอนที่ใช้คอลัมน์ name ทำเฉพาะเมื่อยังมี name (หลังรัน 20261203 ที่ลบ name แล้ว รันไฟล์นี้ซ้ำได้โดยไม่ error)
SET @has_name = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bud_items' AND COLUMN_NAME = 'name');
SET @q = IF(@has_name, 'ALTER TABLE bud_items MODIFY COLUMN name VARCHAR(255) NOT NULL', 'DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- 1) แยกจากชื่อ (เฉพาะหมวดที่ยังไม่แยก): คำขึ้นต้น งบ... / เงินนอกงบประมาณ = งบประมาณ · (...) ถัดไป = ค่าใช้จ่าย · ที่เหลือ = กิจกรรม
--    ค่ากำหนดค่าตามลำดับซ้าย → ขวา (ค่าที่กำหนดแล้วใช้ต่อในนิพจน์ถัดไป)
SET @q = IF(@has_name, 'UPDATE bud_items SET
  budget_name   = COALESCE(REGEXP_SUBSTR(TRIM(name), ''^(เงินนอกงบประมาณ|งบ[^ (]+)''), ''''),
  expense_name  = IF(TRIM(SUBSTRING(TRIM(name), CHAR_LENGTH(budget_name) + 1)) REGEXP ''^\\\\([^)]*\\\\)'',
                     TRIM(REGEXP_REPLACE(TRIM(SUBSTRING(TRIM(name), CHAR_LENGTH(budget_name) + 1)), ''^\\\\(([^)]*)\\\\).*$'', ''\\\\1'')), ''''),
  activity_name = TRIM(IF(expense_name <> '''',
                     REGEXP_REPLACE(TRIM(SUBSTRING(TRIM(name), CHAR_LENGTH(budget_name) + 1)), ''^\\\\([^)]*\\\\)'', ''''),
                     SUBSTRING(TRIM(name), CHAR_LENGTH(budget_name) + 1)))
WHERE budget_name = '''' AND expense_name = '''' AND activity_name = ''''', 'DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) ชื่อที่ไม่มีงบประมาณนำหน้า แต่เป็น "ค่าใช้จ่าย (กิจกรรม)" ของงบดำเนินงาน เช่น "ค่าใช้สอย (คนขับรถยนต์)"
SET @q = IF(@has_name, 'UPDATE bud_items SET
  budget_name   = ''งบดำเนินงาน'',
  expense_name  = TRIM(REGEXP_REPLACE(activity_name, ''\\\\s*\\\\(.*$'', '''')),
  activity_name = TRIM(REGEXP_REPLACE(activity_name, ''^[^(]*\\\\((.*)\\\\)\\\\s*$'', ''\\\\1''))
WHERE budget_name = '''' AND expense_name = ''''
  AND activity_name REGEXP ''^(ค่าใช้สอย|ค่าสาธารณูปโภค|ค่าตอบแทน|ค่าวัสดุ)[^(]*\\\\(.*\\\\)$''', 'DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) ชื่อหมวด = งบประมาณ (ค่าใช้จ่าย) กิจกรรม
SET @q = IF(@has_name, 'UPDATE bud_items SET name = TRIM(CONCAT(
  budget_name,
  IF(expense_name <> '''', CONCAT('' ('', expense_name, '')''), ''''),
  IF(activity_name <> '''', CONCAT('' '', activity_name), '''')))
WHERE budget_name <> '''' OR expense_name <> '''' OR activity_name <> ''''', 'DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;
