-- ============================================================================
-- ปฏิทินการปฏิบัติงาน: ลิงก์สมัครรับ (iCal) "งานของฉัน" สำหรับ Google Calendar / ปฏิทิน iOS — รันซ้ำได้
--   แต่ละคนมีรหัสลับ 1 รหัสในลิงก์ (แอปปฏิทินเข้าสู่ระบบไม่ได้) · สร้างใหม่ได้ = ลิงก์เดิมใช้ไม่ได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261204_cal_ical_tokens.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS cal_ical_tokens (
  user_id       INT UNSIGNED NOT NULL PRIMARY KEY,
  token         CHAR(48)     NOT NULL,                      -- รหัสลับในลิงก์ (สุ่ม 24 ไบต์ hex)
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at  DATETIME     NULL,                          -- แอปปฏิทินดึงข้อมูลล่าสุด
  UNIQUE KEY uq_cal_ical_token (token),
  CONSTRAINT fk_cal_ical_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
