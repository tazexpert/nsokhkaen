-- ============================================================================
-- ปฏิทิน: เวรกิจกรรม — สถิติจังหวัดเข้าร่วมด้วย (เลือกต่อกิจกรรม) — รันซ้ำได้
--   chief_user_id / chief_name = สถิติจังหวัด (หรือผู้รักษาการ) ที่เข้าร่วม · ว่าง = ไม่เข้าร่วม
--   chief_counted = 1 → ผู้รักษาการเป็นข้าราชการที่ถึงคิวพอดี นับเป็นคิวข้าราชการของคนนั้นแล้ว (ข้าราชการของกิจกรรมเป็นคิวถัดไป)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261211_cal_rota_chief.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE cal_rota_turns
  ADD COLUMN IF NOT EXISTS chief_user_id INT UNSIGNED NULL AFTER event_id,
  ADD COLUMN IF NOT EXISTS chief_name VARCHAR(150) NULL AFTER chief_user_id,
  ADD COLUMN IF NOT EXISTS chief_counted TINYINT(1) NOT NULL DEFAULT 0 AFTER chief_name;
