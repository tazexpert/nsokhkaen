-- ============================================================================
-- ผู้ลงนามในสลิปแยกตามช่วงเวลา (เปลี่ยนผู้ลงนามได้โดยสลิปเดือนเก่ายังแสดงผู้ลงนามเดิม) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261101_payslip_signers.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- ผู้ลงนามในสลิป แยกตามช่วงเวลา: สลิปเดือนใดใช้ผู้ลงนามที่ "เริ่มตั้งแต่" ล่าสุดไม่เกินเดือนนั้น
-- effective_ym = ปี พ.ศ. × 100 + เดือน เช่น 256911 = พฤศจิกายน 2569 · 0 = ใช้กับทุกเดือนก่อนหน้า (ผู้ลงนามคนแรก)
CREATE TABLE IF NOT EXISTS pay_signers (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  effective_ym    INT UNSIGNED NOT NULL UNIQUE,
  name            VARCHAR(150) NOT NULL,
  position        VARCHAR(300) NULL,
  signature_path  VARCHAR(255) NULL,                   -- uploads/payslip/... (ปิดการเข้าถึงตรง)
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ย้ายผู้ลงนามเดิม (จากหน้าตั้งค่าสลิป) เป็นผู้ลงนามคนแรก ใช้กับทุกเดือนก่อนหน้า
INSERT IGNORE INTO pay_signers (effective_ym, name, position, signature_path)
SELECT 0,
       COALESCE((SELECT `value` FROM pay_settings WHERE `key` = 'signer_name' AND `value` <> ''), 'นายสมศักดิ์  แก้วสอน'),
       COALESCE((SELECT `value` FROM pay_settings WHERE `key` = 'signer_position'), 'สถิติจังหวัดขอนแก่น'),
       (SELECT NULLIF(`value`, '') FROM pay_settings WHERE `key` = 'signature_path')
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM pay_signers);
DELETE FROM pay_settings WHERE `key` IN ('signer_name', 'signer_position', 'signature_path');
