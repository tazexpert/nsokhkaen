-- ============================================================================
-- ปฏิทิน: เลือกตำแหน่งที่แสดงในตาราง ต่อผู้รับผิดชอบต่อกิจกรรม (ผู้มีหลายตำแหน่ง เช่น ผอ.กว / ผอ.กข / รก.สถิติจังหวัด) — รันซ้ำได้
--   ว่าง = ตำแหน่งหลัก
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261117_calendar_show_as.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE cal_event_users ADD COLUMN IF NOT EXISTS show_as VARCHAR(100) NULL AFTER user_id;
