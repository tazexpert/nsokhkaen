-- ============================================================================
-- สลิปเงินเดือน: ผู้อนุมัติแจ้งแก้ไข (comment + ค่าที่เสนอ) ให้ผู้จัดการสลิปตรวจสอบ — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261103_payslip_comments.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- ข้อเสนอแก้ไขสลิปจากผู้อนุมัติ (ไม่แก้สลิปโดยตรง) → แจ้งเตือนผู้จัดการสลิปให้ตรวจสอบและแก้ไข
--   changes = JSON {คอลัมน์: [ค่าเดิม, ค่าที่เสนอ]} · status: open = รอผู้จัดการสลิป, resolved = แก้ไข/ตอบแล้ว
CREATE TABLE IF NOT EXISTS pay_slip_comments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slip_id         INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NULL,                   -- ผู้อนุมัติที่แจ้ง
  message         TEXT         NULL,
  changes         TEXT         NULL,
  status          ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
  resolved_by     INT UNSIGNED NULL,
  resolved_at     DATETIME     NULL,
  resolve_note    VARCHAR(500) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_comments_slip (slip_id, status),
  KEY idx_pay_comments_status (status),
  CONSTRAINT fk_pay_comments_slip FOREIGN KEY (slip_id) REFERENCES pay_slips(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_comments_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_comments_resolved_by FOREIGN KEY (resolved_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

