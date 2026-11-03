-- ============================================================================
-- ปฏิทิน: เปลี่ยนชื่อ "เวรรักษาการณ์ศาลากลางจังหวัดขอนแก่น" เป็น "เวรศาลากลาง" (รหัส * และกิจกรรมในปฏิทิน) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261114_calendar_guard_name.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- (ตาราง cal_plan_codes ถูกย้ายเป็น core_projects ใน 20261115 — สร้างว่างไว้ให้คำสั่งนี้รันได้ ถ้ารันซ้ำภายหลัง)
CREATE TABLE IF NOT EXISTS cal_plan_codes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL DEFAULT '',
  kind ENUM('field', 'guard') NOT NULL DEFAULT 'field', color VARCHAR(7) NOT NULL DEFAULT '#0d6efd', sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE cal_plan_codes SET name = 'เวรศาลากลาง' WHERE kind = 'guard' AND name = 'เวรรักษาการณ์ศาลากลางจังหวัดขอนแก่น';
UPDATE cal_events SET title = 'เวรศาลากลาง' WHERE kind = 'guard' AND title <> 'เวรศาลากลาง';
