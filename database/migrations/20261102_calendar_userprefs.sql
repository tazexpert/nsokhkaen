-- ============================================================================
-- เพิ่มระบบปฏิทินการปฏิบัติงาน (cal_*) และค่าที่ผู้ใช้ตั้งเอง (core_user_prefs) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261102_calendar_userprefs.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- ค่าที่ผู้ใช้ตั้งเอง เช่น ลำดับกล่องระบบในหน้าหลัก/เมนูด้านบน (module_order = JSON array ของ key ระบบ)
CREATE TABLE IF NOT EXISTS core_user_prefs (
  user_id     INT UNSIGNED NOT NULL,
  pref_key    VARCHAR(50)  NOT NULL,
  pref_value  TEXT         NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, pref_key),
  CONSTRAINT fk_core_prefs_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- cal_ : ระบบปฏิทินการปฏิบัติงาน (calendar)
-- ----------------------------------------------------------------------------

-- กิจกรรม/ภารกิจ
CREATE TABLE IF NOT EXISTS cal_events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_date  DATE         NOT NULL,                 -- วันที่
  end_date    DATE         NULL,                     -- ถึงวันที่ (กิจกรรมหลายวัน — ไม่บังคับ)
  start_time  TIME         NULL,                     -- เวลาเริ่ม (ว่าง = ทั้งวัน)
  end_time    TIME         NULL,                     -- เวลาสิ้นสุด
  title       VARCHAR(255) NOT NULL,                 -- กิจกรรม
  location    VARCHAR(255) NULL,                     -- สถานที่
  link        VARCHAR(500) NULL,                     -- ลิงก์ประกอบ
  note        TEXT         NULL,                     -- หมายเหตุ
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cal_events_date (event_date),
  CONSTRAINT fk_cal_events_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผู้รับผิดชอบกิจกรรม (หลายคนได้) — ได้รับการแจ้งเตือน
CREATE TABLE IF NOT EXISTS cal_event_users (
  event_id     INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  assigned_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, user_id),
  KEY idx_cal_eu_user (user_id),
  CONSTRAINT fk_cal_eu_event FOREIGN KEY (event_id) REFERENCES cal_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_eu_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
