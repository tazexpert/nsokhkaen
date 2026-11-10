-- ============================================================================
-- ระบบงบประมาณ: หมวด "งบดำเนินงาน (ค่าสาธารณูปโภค)" ที่ยังไม่ระบุค่าใช้จ่าย → ค่าใช้จ่าย "ค่าไฟฟ้า ค่าไปรษณีย์" — รันซ้ำได้
--   รายงาน: 4.1 (ไม่ระบุค่าใช้จ่าย) → 4.1 ค่าไฟฟ้า ค่าไปรษณีย์ · แก้เองได้ที่เมนู แผนงานและหมวด
--   ต้องรัน 20261202 ก่อน (มีคอลัมน์ budget_name / expense_name / activity_name)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261206_budget_utility_activity.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

UPDATE bud_items i
LEFT JOIN bud_items d ON d.budget_name = i.budget_name AND d.expense_name = i.expense_name AND d.activity_name = 'ค่าไฟฟ้า ค่าไปรษณีย์'
SET i.activity_name = 'ค่าไฟฟ้า ค่าไปรษณีย์'
WHERE i.budget_name = 'งบดำเนินงาน' AND i.expense_name = 'ค่าสาธารณูปโภค' AND i.activity_name = '' AND d.id IS NULL;
