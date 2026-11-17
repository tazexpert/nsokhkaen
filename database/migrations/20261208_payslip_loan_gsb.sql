-- ============================================================================
-- สลิปเงินเดือน: รายการหักข้อ 7 เงินกู้ธนาคารออมสิน (pay_slips.loan_gsb) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261208_payslip_loan_gsb.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE pay_slips ADD COLUMN IF NOT EXISTS loan_gsb DECIMAL(12,2) NULL AFTER loan_islamic;   -- เงินกู้ธนาคารออมสิน
