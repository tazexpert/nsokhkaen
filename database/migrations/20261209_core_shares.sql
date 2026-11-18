-- ============================================================================
-- แชร์ข้อความ (ระบบกลาง): บุคลากรโพสต์ข้อความ (+ ไฟล์แนบ) แล้วเลือกผู้เห็น — ทุกคน หรือเลือกรายบุคคล/กลุ่มงาน
--   ผู้รับดูอย่างเดียว + แจ้งเตือนที่กระดิ่ง · ผู้แชร์แก้ไข/ลบโพสต์ของตนได้ — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261209_core_shares.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS core_shares (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,                  -- ผู้แชร์
  body           MEDIUMTEXT   NOT NULL,                  -- HTML จัดรูปแบบ (core/richtext.php)
  audience       ENUM('all', 'select') NOT NULL DEFAULT 'all',   -- all = บุคลากรทุกคน · select = เฉพาะใน core_share_users
  audience_text  VARCHAR(500) NULL,                      -- ข้อความสรุปผู้รับ เช่น "กลุ่มวิชาการสถิติและวางแผน, นายก …"
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  KEY idx_core_shares_user (user_id, id),
  CONSTRAINT fk_core_shares_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผู้รับที่เลือก (audience = select) — added_at = เวลาที่ได้รับแจ้งเตือน (เพิ่มผู้รับภายหลังก็ได้รับแจ้งเตือน)
CREATE TABLE IF NOT EXISTS core_share_users (
  share_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (share_id, user_id),
  KEY idx_core_share_users_user (user_id),
  CONSTRAINT fk_core_share_users_share FOREIGN KEY (share_id) REFERENCES core_shares(id) ON DELETE CASCADE,
  CONSTRAINT fk_core_share_users_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ไฟล์แนบ (uploads/shares — ปิดการเปิดตรง เปิดผ่าน share_file.php ที่ตรวจสิทธิ์)
CREATE TABLE IF NOT EXISTS core_share_files (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  share_id   INT UNSIGNED NOT NULL,
  file_path  VARCHAR(255) NOT NULL,
  file_name  VARCHAR(255) NOT NULL,
  file_size  INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_core_share_files (share_id),
  CONSTRAINT fk_core_share_files FOREIGN KEY (share_id) REFERENCES core_shares(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
