-- ============================================================================
-- ระบบบริหารโครงการ: สถานประกอบการของโครงการ (เช่น โครงการสำรวจยอดขายรายไตรมาส) — รันซ้ำได้
--   นำเข้าจากแบบฟอร์ม "บัญชีมอบหมายการปฏิบัติงาน" ที่มีคอลัมน์ ชื่อสถานประกอบการ / สถานที่ติดต่อได้ในปัจจุบัน
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261125_ea_project_units.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS ea_project_units (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id     INT UNSIGNED NOT NULL,
  round_label    VARCHAR(50)  NOT NULL DEFAULT '',      -- รอบ/งวด เช่น "ไตรมาส 3/2569"
  enum_type      VARCHAR(10)  NOT NULL DEFAULT '',      -- Q1–Q4 / Month
  enum_month     TINYINT UNSIGNED NULL,
  enum_year      SMALLINT UNSIGNED NULL,
  seq            INT UNSIGNED NOT NULL DEFAULT 0,       -- ลำดับในไฟล์
  prefix         VARCHAR(30)  NOT NULL DEFAULT '',      -- บจก. / หจก. / ...
  name           VARCHAR(255) NOT NULL,                 -- ชื่อสถานประกอบการ
  ea_no          VARCHAR(10)  NOT NULL DEFAULT '',      -- EA ตามไฟล์ ('' / 0000 = ไม่ทราบ)
  ea_id          INT UNSIGNED NULL,                     -- EA ในบัญชี (จับคู่ได้)
  moo            VARCHAR(10)  NOT NULL DEFAULT '',      -- หมู่ที่
  house_no       VARCHAR(50)  NOT NULL DEFAULT '',      -- เลขที่
  road           VARCHAR(150) NOT NULL DEFAULT '',      -- ถนน
  tambon_name    VARCHAR(100) NOT NULL DEFAULT '',
  district_name  VARCHAR(100) NOT NULL DEFAULT '',
  district_code  CHAR(2)      NOT NULL DEFAULT '',      -- เติมจากชื่อ (บัญชี EA)
  tambon_code    CHAR(2)      NOT NULL DEFAULT '',
  assigned_user_id INT UNSIGNED NULL,
  assigned_at    DATETIME     NULL,
  assigned_by    INT UNSIGNED NULL,
  status         ENUM('pending', 'in_progress', 'done') NOT NULL DEFAULT 'pending',
  note           VARCHAR(255) NOT NULL DEFAULT '',
  done_at        DATETIME     NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ea_pu_project (project_id, round_label),
  KEY idx_ea_pu_user (assigned_user_id),
  CONSTRAINT fk_ea_pu_project FOREIGN KEY (project_id) REFERENCES core_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_ea_pu_ea FOREIGN KEY (ea_id) REFERENCES ea_areas(id) ON DELETE SET NULL,
  CONSTRAINT fk_ea_pu_user FOREIGN KEY (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
