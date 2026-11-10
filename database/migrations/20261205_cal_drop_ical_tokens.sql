-- ============================================================================
-- ปฏิทิน: ยกเลิกลิงก์สมัครรับ (iCal) — ลบตาราง cal_ical_tokens (ถ้าเคยรัน 20261204) — รันซ้ำได้
--   ส่งออก "งานของฉัน" ใช้ดาวน์โหลดไฟล์ .ics อย่างเดียว (ไม่ต้องใช้ตาราง)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261205_cal_drop_ical_tokens.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

DROP TABLE IF EXISTS cal_ical_tokens;
