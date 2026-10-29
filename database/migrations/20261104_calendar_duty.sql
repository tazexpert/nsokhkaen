-- ============================================================================
-- ปฏิทิน: เวรสำนักงาน (นำเข้ารายเดือนจาก CSV: วันที่ | ผู้รับผิดชอบ) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261104_calendar_duty.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- event = กิจกรรม, duty = เวรสำนักงาน
ALTER TABLE cal_events ADD COLUMN IF NOT EXISTS kind ENUM('event', 'duty') NOT NULL DEFAULT 'event' AFTER id;
ALTER TABLE cal_events ADD INDEX IF NOT EXISTS idx_cal_events_kind (kind, event_date);
