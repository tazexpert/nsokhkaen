-- ============================================================================
-- กล่องจดหมาย: ส่งข้อความถึงบุคลากร (แนบไฟล์ได้) + แจ้งเตือนผู้รับ — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261110_core_messages.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS core_messages (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id          INT UNSIGNED NOT NULL,
  recipient_id       INT UNSIGNED NOT NULL,
  reply_to_id        INT UNSIGNED NULL,
  subject            VARCHAR(200) NOT NULL,
  body               TEXT         NOT NULL,
  read_at            DATETIME     NULL,
  sender_deleted     TINYINT(1)   NOT NULL DEFAULT 0,
  recipient_deleted  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_core_msg_inbox (recipient_id, recipient_deleted, id),
  KEY idx_core_msg_sent (sender_id, sender_deleted, id),
  CONSTRAINT fk_core_msg_sender FOREIGN KEY (sender_id) REFERENCES core_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_core_msg_recipient FOREIGN KEY (recipient_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS core_message_files (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id  INT UNSIGNED NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  file_name   VARCHAR(255) NOT NULL,
  file_size   INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_core_msg_files (message_id),
  CONSTRAINT fk_core_msg_files FOREIGN KEY (message_id) REFERENCES core_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
