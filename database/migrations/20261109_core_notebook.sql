-- ============================================================================
-- สมุดบันทึกส่วนตัว (ทุกคนมีคนละเล่ม เห็นเฉพาะเจ้าของ · ดู/แก้ไขได้ ไม่มีเพิ่ม/ลบ)
-- เนื้อหาเข้ารหัส AES-256-GCM ด้วยกุญแจใน storage/notebook_key.php · ทุกครั้งที่บันทึกเก็บเป็นประวัติ — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261109_core_notebook.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS core_notebook_revisions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  content     MEDIUMTEXT   NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_core_notebook_user (user_id, id),
  CONSTRAINT fk_core_notebook_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
