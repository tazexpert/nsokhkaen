-- ============================================================================
-- ปฏิทิน: แผนการปฏิบัติงานรายเดือน (คน × วัน × รหัสงาน) + เวรรักษาการณ์ศาลากลางฯ — รันซ้ำได้
--   · cal_plan_codes  รหัสงาน (โครงการสนาม + "*" เวรรักษาการณ์ศาลากลางฯ) ผู้ดูแลเพิ่ม/แก้ไขได้
--   · cal_plans       แผนรายเดือน · cal_plan_people รายชื่อในแผน (มี/ไม่มีบัญชีผู้ใช้) · cal_plan_cells รหัสงานรายวัน
--   · เวรรักษาการณ์ในแผน → สร้างเป็นกิจกรรม kind = 'guard' ในปฏิทินอัตโนมัติ (แสดงในตาราง + แจ้งเตือนผู้รับผิดชอบ)
--   · เวรสำนักงานไม่เก็บในแผน — ดึงจากปฏิทิน (kind = 'duty') มาแสดง
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261113_calendar_plan.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE cal_events MODIFY kind ENUM('event', 'duty', 'guard') NOT NULL DEFAULT 'event';

CREATE TABLE IF NOT EXISTS cal_plan_codes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(200) NOT NULL DEFAULT '',
  kind        ENUM('field', 'guard') NOT NULL DEFAULT 'field',   -- guard = เวรรักษาการณ์ศาลากลางฯ (รหัส *)
  color       VARCHAR(7)   NOT NULL DEFAULT '#0d6efd',
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cal_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cal_plan_codes (code, name, kind, color, sort_order) VALUES
  ('สรง', 'สรง', 'field', '#2563eb', 10),
  ('สศส', 'สศส', 'field', '#16a34a', 20),
  ('ธปท', 'ธปท', 'field', '#9333ea', 30),
  ('โพล', 'โพล', 'field', '#ea580c', 40),
  ('สพค', 'สพค', 'field', '#0891b2', 50),
  ('*', 'เวรรักษาการณ์ศาลากลางจังหวัดขอนแก่น', 'guard', '#dc2626', 90);

CREATE TABLE IF NOT EXISTS cal_plans (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_year   SMALLINT UNSIGNED NOT NULL,          -- ค.ศ.
  plan_month  TINYINT UNSIGNED  NOT NULL,
  note        TEXT         NULL,
  updated_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cal_plan_month (plan_year, plan_month),
  CONSTRAINT fk_cal_plan_user FOREIGN KEY (updated_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cal_plan_people (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id     INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,                   -- ว่าง = ผู้ปฏิบัติงานที่ไม่มีบัญชีในระบบ (ใช้ชื่อ)
  name        VARCHAR(150) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_cal_plan_people (plan_id),
  CONSTRAINT fk_cal_pp_plan FOREIGN KEY (plan_id) REFERENCES cal_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_pp_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cal_plan_cells (
  person_id   INT UNSIGNED NOT NULL,
  day         TINYINT UNSIGNED NOT NULL,           -- วันที่ 1–31
  code_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (person_id, day, code_id),
  CONSTRAINT fk_cal_pc_person FOREIGN KEY (person_id) REFERENCES cal_plan_people(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_pc_code FOREIGN KEY (code_id) REFERENCES cal_plan_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
