-- ============================================================================
-- ปฏิทิน: กิจกรรมสาธารณะ / ส่วนตัว (เห็นเฉพาะผู้บันทึกและผู้รับผิดชอบ) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261106_calendar_visibility.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE cal_events ADD COLUMN IF NOT EXISTS visibility ENUM('public', 'private') NOT NULL DEFAULT 'public' AFTER kind;
