-- ============================================================================
-- ปฏิทิน: การลาในแผนการปฏิบัติงาน (ลากิจ / ลาป่วย / ลาพักผ่อน) — ผู้ใช้งานลงแผนการลาของตนเองได้ — รันซ้ำได้ · CREATE TABLE อย่างเดียว
--   รหัสในแผน: ลก = ลากิจ · ลป = ลาป่วย · ลพ = ลาพักผ่อน (1 คน 1 วัน ลาได้ 1 ประเภท)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261216_cal_plan_leave.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS cal_plan_leave (
  person_id   INT UNSIGNED NOT NULL,
  day         TINYINT UNSIGNED NOT NULL,
  leave_type  ENUM('personal', 'sick', 'vacation') NOT NULL,   -- ลากิจ / ลาป่วย / ลาพักผ่อน
  PRIMARY KEY (person_id, day),
  CONSTRAINT fk_cal_pl_person FOREIGN KEY (person_id) REFERENCES cal_plan_people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
