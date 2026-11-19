-- ============================================================================
-- ปฏิทิน: ตัวย่อ ผู้อำนวยการกลุ่มขับเคลื่อนงานด้านดิจิทัล  ผอ.กข → ผอ.กด — รันซ้ำได้
--   แก้ตำแหน่งที่เลือกแสดงไว้แล้วในกิจกรรมเดิม (cal_event_users.show_as เช่น "ผอ.กว / ผอ.กข", "รก.ผอ.กข")
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261210_cal_abbr_dir3.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

UPDATE cal_event_users SET show_as = REPLACE(show_as, 'ผอ.กข', 'ผอ.กด') WHERE show_as LIKE '%ผอ.กข%';
