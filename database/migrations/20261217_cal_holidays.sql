-- ============================================================================
-- ปฏิทิน: วันหยุดราชการ / วันหยุดพิเศษ (นอกจากเสาร์-อาทิตย์) — ผู้ดูแลปฏิทินกำหนดที่เมนู "วันหยุด" — รันซ้ำได้ · CREATE TABLE อย่างเดียว
--   แสดงเป็นวันหยุด (สีเทา + ชื่อวันหยุด) ในปฏิทินรายเดือน ตารางกิจกรรม และแผนการปฏิบัติงาน
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261217_cal_holidays.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS cal_holidays (
  holiday_date  DATE         NOT NULL PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cal_holidays_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
