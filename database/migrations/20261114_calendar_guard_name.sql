-- ============================================================================
-- ปฏิทิน: เปลี่ยนชื่อ "เวรรักษาการณ์ศาลากลางจังหวัดขอนแก่น" เป็น "เวรศาลากลาง" (รหัส * และกิจกรรมในปฏิทิน) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261114_calendar_guard_name.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

UPDATE cal_plan_codes SET name = 'เวรศาลากลาง' WHERE kind = 'guard' AND name = 'เวรรักษาการณ์ศาลากลางจังหวัดขอนแก่น';
UPDATE cal_events SET title = 'เวรศาลากลาง' WHERE kind = 'guard' AND title <> 'เวรศาลากลาง';
