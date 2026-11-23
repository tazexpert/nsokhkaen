-- ============================================================================
-- ระบบตัวชี้วัด (kpi_) — รันซ้ำได้
--   ตัวชี้วัด (ปีงบประมาณ / รอบ) → ระดับคะแนน (ประเด็นการประเมิน) → กิจกรรม/หลักฐาน (มอบผู้ปฏิบัติรายกิจกรรม · วันที่ต้องส่ง)
--   ผู้ปฏิบัติบันทึกความก้าวหน้า + แนบไฟล์หลักฐาน · ผู้ควบคุมตัวชี้วัดตรวจและให้คะแนน
--   ไฟล์แนบเก็บที่ uploads/kpi (ปิดการเข้าถึงตรง — เปิดผ่าน modules/kpi/file.php ที่ตรวจสิทธิ์)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261212_kpi.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- ตัวชี้วัด
CREATE TABLE IF NOT EXISTS kpi_indicators (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year    SMALLINT UNSIGNED NOT NULL,              -- ปีงบประมาณ (พ.ศ.)
  round_no       TINYINT UNSIGNED  NOT NULL DEFAULT 1,    -- รอบที่ 1 = 1 ต.ค. – 31 มี.ค. · รอบที่ 2 = 1 เม.ย. – 30 ก.ย.
  code           VARCHAR(20)  NULL,                       -- ลำดับตัวชี้วัด เช่น 2, 1.1
  name           VARCHAR(500) NOT NULL,
  unit           VARCHAR(100) NULL,                       -- หน่วยวัด
  weight         DECIMAL(6,2) NULL,                       -- น้ำหนัก (ร้อยละ)
  description    TEXT         NULL,                       -- คำอธิบาย
  work_group_id  INT UNSIGNED NULL,                       -- กลุ่มงานที่รับผิดชอบ
  note           TEXT         NULL,                       -- หมายเหตุ
  controller_id  INT UNSIGNED NULL,                       -- ผู้ควบคุมตัวชี้วัด
  collector_id   INT UNSIGNED NULL,                       -- ผู้จัดเก็บข้อมูล
  sort_order     INT          NOT NULL DEFAULT 0,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_kpi_indicators_period (fiscal_year, round_no, sort_order),
  CONSTRAINT fk_kpi_indicators_wg FOREIGN KEY (work_group_id) REFERENCES core_work_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_kpi_indicators_ctrl FOREIGN KEY (controller_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_kpi_indicators_coll FOREIGN KEY (collector_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_kpi_indicators_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผู้ปฏิบัติของตัวชี้วัด
CREATE TABLE IF NOT EXISTS kpi_indicator_users (
  indicator_id  INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (indicator_id, user_id),
  KEY idx_kpi_indicator_users_user (user_id),
  CONSTRAINT fk_kpi_iu_ind FOREIGN KEY (indicator_id) REFERENCES kpi_indicators(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_iu_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เกณฑ์การให้คะแนน: ระดับ / ประเด็นการประเมิน / คะแนนเต็มของระดับ
CREATE TABLE IF NOT EXISTS kpi_levels (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  indicator_id  INT UNSIGNED NOT NULL,
  level_no      TINYINT UNSIGNED NOT NULL,
  issue         TEXT         NOT NULL,                    -- ประเด็นการประเมิน
  max_score     DECIMAL(6,2) NULL,
  KEY idx_kpi_levels_ind (indicator_id, level_no),
  CONSTRAINT fk_kpi_levels_ind FOREIGN KEY (indicator_id) REFERENCES kpi_indicators(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- กิจกรรม/หลักฐานในแต่ละระดับ (มอบผู้ปฏิบัติรายกิจกรรม · วันที่ต้องส่ง → แจ้งเตือนก่อน 15 วัน)
CREATE TABLE IF NOT EXISTS kpi_activities (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  indicator_id  INT UNSIGNED NOT NULL,
  level_id      INT UNSIGNED NOT NULL,
  title         TEXT         NOT NULL,                    -- กิจกรรม / หลักฐาน-เอกสารประกอบ
  score_rule    TEXT         NULL,                        -- เงื่อนไขคะแนน เช่น ส่งภายใน 2 มิ.ย. ได้ 0.5 คะแนน
  max_score     DECIMAL(6,2) NULL,
  due_date      DATE         NULL,                        -- วันที่ต้องส่ง
  status        ENUM('pending', 'submitted', 'done') NOT NULL DEFAULT 'pending',   -- ยังไม่ส่ง / ส่งแล้วรอตรวจ / ตรวจแล้ว
  score         DECIMAL(6,2) NULL,                        -- คะแนนที่ได้ (ผู้ควบคุมตัวชี้วัดให้)
  review_note   VARCHAR(500) NULL,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME     NULL,
  submitted_at  DATETIME     NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  KEY idx_kpi_activities_level (level_id, sort_order),
  KEY idx_kpi_activities_due (due_date, status),
  CONSTRAINT fk_kpi_act_ind FOREIGN KEY (indicator_id) REFERENCES kpi_indicators(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_act_level FOREIGN KEY (level_id) REFERENCES kpi_levels(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_act_rev FOREIGN KEY (reviewed_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผู้ปฏิบัติรายกิจกรรม (ไม่ระบุ = ผู้ปฏิบัติของตัวชี้วัดทุกคน)
CREATE TABLE IF NOT EXISTS kpi_activity_users (
  activity_id  INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (activity_id, user_id),
  KEY idx_kpi_activity_users_user (user_id),
  CONSTRAINT fk_kpi_au_act FOREIGN KEY (activity_id) REFERENCES kpi_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_au_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บันทึกความก้าวหน้า (ข้อความ / ลิงก์ / ร้อยละ · is_submit = ส่งหลักฐานแล้ว)
CREATE TABLE IF NOT EXISTS kpi_progress (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id  INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL,
  note         TEXT         NULL,
  link         VARCHAR(500) NULL,
  percent      TINYINT UNSIGNED NULL,
  is_submit    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kpi_progress_act (activity_id, id),
  CONSTRAINT fk_kpi_prog_act FOREIGN KEY (activity_id) REFERENCES kpi_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_prog_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ไฟล์หลักฐานของบันทึกความก้าวหน้า
CREATE TABLE IF NOT EXISTS kpi_files (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  progress_id  INT UNSIGNED NOT NULL,
  file_path    VARCHAR(255) NOT NULL,                     -- ภายใต้ uploads/ (kpi/YYYYMM/...)
  file_name    VARCHAR(255) NOT NULL,                     -- ชื่อไฟล์เดิม
  file_size    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kpi_files_prog (progress_id),
  CONSTRAINT fk_kpi_files_prog FOREIGN KEY (progress_id) REFERENCES kpi_progress(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
