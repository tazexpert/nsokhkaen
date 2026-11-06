-- ============================================================================
-- ระบบบริหารจัดการ EA (เขตแจงนับ) จังหวัดขอนแก่น — รันซ้ำได้
--   ea_areas        บัญชีเขตแจงนับ (นำเข้าจากไฟล์ Excel "บัญชีเขตแจงนับ" ที่ modules/ea/import.php)
--   ea_project_eas  EA ที่แต่ละโครงการ (core_projects) ใช้สำรวจ + รอบ/งวด ผู้รับผิดชอบ และสถานะ
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261118_ea.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS ea_areas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ea_code        CHAR(11)     NOT NULL,                 -- รหัส EA 11 หลัก = จังหวัด(2)+อำเภอ(2)+ตำบล(2)+เขตการปกครอง(1)+EA(4)
  seq            INT UNSIGNED NOT NULL DEFAULT 0,       -- ลำดับที่ในบัญชี
  region_code    VARCHAR(2)   NOT NULL DEFAULT '',
  region_name    VARCHAR(50)  NOT NULL DEFAULT '',
  province_code  CHAR(2)      NOT NULL,
  province_name  VARCHAR(50)  NOT NULL DEFAULT '',
  district_code  CHAR(2)      NOT NULL,
  district_name  VARCHAR(100) NOT NULL DEFAULT '',
  tambon_code    CHAR(2)      NOT NULL,
  tambon_name    VARCHAR(100) NOT NULL DEFAULT '',
  area_type      TINYINT UNSIGNED NOT NULL,             -- เขตการปกครอง 1 = ในเขตเทศบาล, 2 = นอกเขตเทศบาล
  ea_no          CHAR(4)      NOT NULL,
  advance_ea     VARCHAR(20)  NOT NULL DEFAULT '',      -- EA สำหรับเสนอผลล่วงหน้า
  village_code   VARCHAR(3)   NOT NULL DEFAULT '',      -- หมู่บ้าน/ชุมชน (นอกเขตเทศบาล)
  village_name   VARCHAR(150) NOT NULL DEFAULT '',
  houses         INT UNSIGNED NOT NULL DEFAULT 0,       -- จำนวนบ้าน/อาคาร/สิ่งปลูกสร้าง
  households     INT UNSIGNED NOT NULL DEFAULT 0,
  population     INT UNSIGNED NOT NULL DEFAULT 0,
  note           TEXT         NULL,
  source         VARCHAR(255) NOT NULL DEFAULT '',      -- ที่มาของบัญชี เช่น "โครงการสำมะโนประชากรและเคหะ พ.ศ.2568"
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,       -- 0 = เลิกใช้ (ไม่อยู่ในบัญชีล่าสุด)
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ea_code (ea_code),
  KEY idx_ea_area (district_code, tambon_code, area_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ea_project_eas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id     INT UNSIGNED NOT NULL,
  ea_id          INT UNSIGNED NOT NULL,
  round_label    VARCHAR(50)  NOT NULL DEFAULT '',      -- รอบ/งวดการสำรวจ เช่น "ม.ค. 2570", "ไตรมาส 1" ('' = ไม่แยกรอบ)
  assigned_user_id INT UNSIGNED NULL,                   -- ผู้รับผิดชอบ EA
  status         ENUM('pending', 'in_progress', 'done') NOT NULL DEFAULT 'pending',
  note           VARCHAR(255) NOT NULL DEFAULT '',
  done_at        DATETIME     NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ea_project_round (project_id, round_label, ea_id),
  KEY idx_ea_pe_ea (ea_id),
  KEY idx_ea_pe_user (assigned_user_id),
  CONSTRAINT fk_ea_pe_project FOREIGN KEY (project_id) REFERENCES core_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_ea_pe_ea FOREIGN KEY (ea_id) REFERENCES ea_areas(id),
  CONSTRAINT fk_ea_pe_user FOREIGN KEY (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ea_pe_creator FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
