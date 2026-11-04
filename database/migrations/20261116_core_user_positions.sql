-- ============================================================================
-- ผู้ใช้งาน: ตำแหน่งเพิ่มเติม (ดำรงตำแหน่งควบ / รักษาการ) — รันซ้ำได้
--   เช่น ผู้อำนวยการกลุ่มวิชาการฯ (ตำแหน่งหลัก) + ผู้อำนวยการกลุ่มขับเคลื่อนงานด้านดิจิทัล (ควบ) + รักษาการสถิติจังหวัดขอนแก่น
--   ใช้กับ: ลำดับบุคลากร (ใช้ตำแหน่งที่สูงที่สุด) · หน้าบุคลากร · ผู้อำนวยการกลุ่มงานในปฏิทิน
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261116_core_user_positions.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS core_user_positions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  position    VARCHAR(150) NOT NULL,                       -- ชื่อตำแหน่ง เช่น สถิติจังหวัดขอนแก่น
  kind        ENUM('concurrent', 'acting') NOT NULL DEFAULT 'concurrent',   -- concurrent = ควบ, acting = รักษาการ (แสดง "รักษาการ" นำหน้า)
  start_date  DATE         NULL,                           -- ช่วงเวลา (ไม่บังคับ) — พ้นวันสิ้นสุดแล้วไม่นำมาใช้
  end_date    DATE         NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_core_user_positions (user_id),
  CONSTRAINT fk_core_up_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
