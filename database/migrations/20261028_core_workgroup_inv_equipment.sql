-- ============================================================================
-- ปรับโครงสร้าง 2026-10-28: กลุ่มงานผู้ใช้ การแจ้งเตือน และระบบครุภัณฑ์/ยืม-คืน
--
-- ใช้เฉพาะฐานข้อมูลที่ติดตั้ง/ย้ายข้อมูลไปแล้ว "ก่อน" เวอร์ชันนี้
-- (ถ้าติดตั้งใหม่ด้วย schema.sql หรือ migrate_all_in_one.sql เวอร์ชันล่าสุด ไม่ต้องรัน)
-- รันซ้ำได้ ไม่ทำให้ข้อมูลเสียหาย (MariaDB 10.3+)
--
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261028_core_workgroup_inv_equipment.sql
-- ============================================================================

SET NAMES utf8mb4;
USE nsokhkaen;

-- กลุ่มงานของผู้ใช้
CREATE TABLE IF NOT EXISTS core_work_groups (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL UNIQUE,
  sort_order  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO core_work_groups (id, name, sort_order) VALUES
  (1, 'กลุ่มปฏิบัติการสถิติและดิจิทัล', 1),
  (2, 'กลุ่มวิชาการสถิติและวางแผน', 2),
  (3, 'กลุ่มขับเคลื่อนงานด้านดิจิทัล', 3);

ALTER TABLE core_users
  ADD COLUMN IF NOT EXISTS work_group_id INT UNSIGNED NULL AFTER is_active,
  ADD COLUMN IF NOT EXISTS notif_seen_at DATETIME NULL AFTER work_group_id;
ALTER TABLE core_users
  ADD CONSTRAINT fk_core_users_work_group FOREIGN KEY IF NOT EXISTS (work_group_id) REFERENCES core_work_groups(id) ON DELETE SET NULL;

-- ---------- ครุภัณฑ์และการยืม-คืน ----------
-- ประเภทครุภัณฑ์ เช่น ไมโครคอมพิวเตอร์ (PC Desktop), โน้ตบุ๊ค (Notebook), แท็บเล็ต (Tablet)
CREATE TABLE IF NOT EXISTS inv_equipment_types (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL UNIQUE,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายการครุภัณฑ์ (คอลัมน์ตามรายงานครุภัณฑ์คอมพิวเตอร์ของสำนักงาน)
-- status = สภาพการใช้งาน; การถูกยืมดูจาก inv_loan_items ที่ยังไม่คืน
CREATE TABLE IF NOT EXISTS inv_equipment (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_no          VARCHAR(100) NULL UNIQUE,          -- เลขครุภัณฑ์ (ว่างได้ กรณีรับใหม่ยังไม่ออกเลข)
  type_id           INT UNSIGNED NULL,
  brand             VARCHAR(100) NULL,                 -- ยี่ห้อ
  model             VARCHAR(255) NULL,                 -- รุ่น (Model)
  serial_number     VARCHAR(150) NULL,                 -- หมายเลขเครื่อง (Serial no.)
  fiscal_year       SMALLINT UNSIGNED NULL,            -- ปีงบประมาณที่ได้มา (พ.ศ.)
  status            ENUM('normal', 'repair', 'dispose') NOT NULL DEFAULT 'normal',  -- ใช้งานตามปกติ / เสียรอซ่อม / รอจำหน่าย
  storage_location  VARCHAR(255) NULL,
  image_path        VARCHAR(255) NULL,
  note              VARCHAR(500) NULL,                 -- หมายเหตุ
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_inv_equipment_status (status),
  CONSTRAINT fk_inv_equipment_type FOREIGN KEY (type_id) REFERENCES inv_equipment_types(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_equipment_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO inv_equipment_types (id, name, sort_order) VALUES
  (1, 'ไมโครคอมพิวเตอร์ (PC Desktop)', 1),
  (2, 'โน้ตบุ๊ค (Notebook)', 2),
  (3, 'แท็บเล็ต (Tablet)', 3);

-- บันทึกข้อความขออนุมัติยืมพัสดุ (เลขที่ = เลขรัน/ปี พ.ศ. เช่น 1/2569)
-- pending = รออนุมัติ, borrowed = อนุมัติ/ส่งมอบแล้ว (กำลังยืม), returned = คืนแล้ว,
-- rejected = ไม่อนุมัติ, cancelled = ผู้ยืมยกเลิก
CREATE TABLE IF NOT EXISTS inv_loans (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loan_no            VARCHAR(50)  NOT NULL UNIQUE,
  borrower_id        INT UNSIGNED NOT NULL,
  borrower_name      VARCHAR(150) NOT NULL,
  borrower_position  VARCHAR(150) NULL,
  borrower_org       VARCHAR(255) NULL,
  borrower_phone     VARCHAR(50)  NULL,
  purpose            VARCHAR(500) NOT NULL,
  start_date         DATE         NOT NULL,
  due_date           DATE         NOT NULL,
  status             ENUM('pending', 'borrowed', 'returned', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
  lender_id          INT UNSIGNED NULL,               -- ผู้ให้ยืม (ผู้ดูแลที่อนุมัติ/ส่งมอบ)
  lender_name        VARCHAR(150) NULL,
  lender_position    VARCHAR(150) NULL,
  approver_name      VARCHAR(150) NULL,               -- ผู้อนุมัติ (ตามที่ตั้งค่าไว้ ณ วันอนุมัติ)
  approver_position  VARCHAR(500) NULL,
  lent_at            DATETIME     NULL,
  returned_at        DATETIME     NULL,
  receiver_id        INT UNSIGNED NULL,               -- ผู้รับคืน
  receiver_name      VARCHAR(150) NULL,
  receiver_position  VARCHAR(150) NULL,
  return_note        VARCHAR(500) NULL,
  reject_reason      VARCHAR(500) NULL,
  created_by         INT UNSIGNED NOT NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_inv_loans_status (status),
  CONSTRAINT fk_inv_loans_borrower FOREIGN KEY (borrower_id) REFERENCES core_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_loans_lender FOREIGN KEY (lender_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_loans_receiver FOREIGN KEY (receiver_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_loans_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายการในใบยืม: ครุภัณฑ์จากทะเบียน (equipment_id) หรืออุปกรณ์ประกอบ เช่น สายชาร์จ กระเป๋า (equipment_id = NULL)
CREATE TABLE IF NOT EXISTS inv_loan_items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loan_id        INT UNSIGNED NOT NULL,
  equipment_id   INT UNSIGNED NULL,
  item_name      VARCHAR(255) NOT NULL,
  brand_model    VARCHAR(255) NULL,
  serial_number  VARCHAR(150) NULL,
  asset_no       VARCHAR(100) NULL,
  quantity       INT          NOT NULL DEFAULT 1,
  sort_order     INT          NOT NULL DEFAULT 0,
  CONSTRAINT fk_inv_loanitem_loan FOREIGN KEY (loan_id) REFERENCES inv_loans(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_loanitem_equipment FOREIGN KEY (equipment_id) REFERENCES inv_equipment(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ชื่อระบบใหม่
UPDATE core_settings SET `value` = 'ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น' WHERE `key` = 'app_name';
