-- ============================================================================
-- ย้ายระบบทั้งหมดในไฟล์เดียว: สร้างฐานข้อมูล nsokhkaen + ย้ายข้อมูลระบบวัสดุ + ระบบศูนย์ดิจิทัลชุมชน
-- (ไฟล์นี้สร้างอัตโนมัติจาก tools/build_migrate_all.py — อย่าแก้โดยตรง)
--
-- ใช้เมื่อ: ฐานข้อมูลเดิม `nsokhkaen_inventory` และ `digitalcc_khonkaen` อยู่บนเซิร์ฟเวอร์เดียวกัน
-- วิธีใช้:  mysql -u root -p --default-character-set=utf8mb4 < database/legacy/migrate_all_in_one.sql
--          หรือ phpMyAdmin/Navicat: เปิดไฟล์นี้แล้ว Run ทั้งไฟล์ (ไม่ต้องเลือกฐานข้อมูลก่อน)
--
-- ปลอดภัย: อ่านจากฐานข้อมูลเดิมอย่างเดียว ไม่แก้ไข/ลบข้อมูลเดิม
-- ถ้าฐานข้อมูล nsokhkaen มีข้อมูลอยู่แล้ว สคริปต์จะหยุดที่ error "Duplicate entry" (ไม่เขียนทับ)
--   → ต้องการรันใหม่: DROP DATABASE nsokhkaen; แล้วรันไฟล์นี้อีกครั้ง
-- หลังรัน: คัดลอกรูปภาพ และตั้ง redirect ตาม docs/MIGRATION.md ขั้นที่ 5–6
-- ============================================================================

SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS nsokhkaen DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nsokhkaen;

-- ############################################################################
-- ส่วนที่ 1: โครงสร้างตาราง (database/schema.sql)
-- ############################################################################
-- ----------------------------------------------------------------------------
-- core_ : ระบบกลาง
-- ----------------------------------------------------------------------------

-- ผู้ใช้งาน (ใช้ร่วมกันทุกระบบ — login เดียว)
-- username/password_hash เป็น NULL ได้ (ผู้ใช้ที่ยืนยันตัวตนด้วย PIN อย่างเดียว)
-- pin_hash เป็น NULL ได้ (ผู้ใช้ที่ login ด้วยชื่อผู้ใช้/รหัสผ่านอย่างเดียว)
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

CREATE TABLE IF NOT EXISTS core_users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(50)  NULL UNIQUE,
  password_hash  VARCHAR(255) NULL,
  full_name      VARCHAR(150) NOT NULL,
  position       VARCHAR(150) NULL,
  email          VARCHAR(150) NULL,
  phone          VARCHAR(50)  NULL,
  pin_hash       VARCHAR(255) NULL,
  is_superadmin  TINYINT(1)   NOT NULL DEFAULT 0,   -- ผู้ดูแลระบบกลาง: จัดการผู้ใช้/สิทธิ์ และมีสิทธิ์สูงสุดทุกระบบ
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  work_group_id  INT UNSIGNED NULL,                  -- กลุ่มงาน (core_work_groups)
  notif_seen_at  DATETIME     NULL,                  -- เปิดดูการแจ้งเตือนล่าสุดเมื่อ (รายการใหม่กว่านี้ = ยังไม่อ่าน)
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
,
  CONSTRAINT fk_core_users_work_group FOREIGN KEY (work_group_id) REFERENCES core_work_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- สิทธิ์ของผู้ใช้ในแต่ละระบบ (1 แถว = 1 ระบบ) — ไม่มีแถว = เข้าระบบนั้นไม่ได้
-- module_key ตรงกับชื่อโฟลเดอร์ใน modules/ ; role ตามที่ module.php ของระบบนั้นกำหนด
CREATE TABLE IF NOT EXISTS core_user_roles (
  user_id     INT UNSIGNED NOT NULL,
  module_key  VARCHAR(50)  NOT NULL,
  role        VARCHAR(30)  NOT NULL,
  granted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, module_key),
  KEY idx_module (module_key),
  CONSTRAINT fk_core_roles_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ค่าตั้งค่าระบบกลาง (key/value) เช่น app_name, app_url
CREATE TABLE IF NOT EXISTS core_settings (
  `key`       VARCHAR(80)  NOT NULL PRIMARY KEY,
  `value`     TEXT         NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บันทึกการใช้งาน (เข้าสู่ระบบ การจัดการผู้ใช้/สิทธิ์ ฯลฯ)
CREATE TABLE IF NOT EXISTS core_audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  module_key  VARCHAR(50)  NOT NULL DEFAULT 'core',
  action      VARCHAR(80)  NOT NULL,
  detail      VARCHAR(500) NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_user (user_id),
  CONSTRAINT fk_core_audit_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- inv_ : ระบบบริหารจัดการวัสดุ (เดิมฐานข้อมูล nsokhkaen_inventory)
-- ----------------------------------------------------------------------------

-- ค่าตั้งค่าเฉพาะระบบวัสดุ เช่น ผู้จ่าย/ผู้อนุมัติที่พิมพ์ในใบเบิก
CREATE TABLE IF NOT EXISTS inv_settings (
  `key`       VARCHAR(50)  NOT NULL PRIMARY KEY,
  `value`     VARCHAR(255) NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_storage_locations (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(255) NOT NULL UNIQUE,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- หมวดหมู่ (วัสดุ: name = รหัสหมวดวัสดุ เช่น 14111500)
CREATE TABLE IF NOT EXISTS inv_categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  item_type   ENUM('material', 'asset') NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_materials (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  material_code     VARCHAR(50)  NOT NULL UNIQUE,
  qr_code           VARCHAR(100) NOT NULL UNIQUE,     -- ค่าใน QR สติ๊กเกอร์ (ห้ามเปลี่ยน เพื่อให้สติ๊กเกอร์เดิมใช้ได้)
  name              VARCHAR(255) NOT NULL,
  category_id       INT UNSIGNED NULL,
  category_code     VARCHAR(20)  NULL,
  unit              VARCHAR(50)  NOT NULL DEFAULT 'ชิ้น',
  unit_cost         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stock_qty         INT          NOT NULL DEFAULT 0,
  min_stock         INT          NOT NULL DEFAULT 0,
  storage_location  VARCHAR(255) NULL,
  image_path        VARCHAR(255) NULL,                -- เช่น uploads/inventory/materials/1411150001.jpg
  note              TEXT         NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_materials_category FOREIGN KEY (category_id) REFERENCES inv_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_materials_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ครุภัณฑ์แบบเดิม (เลิกใช้งานแล้ว — เก็บตารางไว้เพื่อรองรับการย้ายข้อมูลระบบเดิม; ระบบใหม่ใช้ inv_equipment)
CREATE TABLE IF NOT EXISTS inv_assets (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_code        VARCHAR(50)  NOT NULL UNIQUE,
  qr_code           VARCHAR(100) NOT NULL UNIQUE,
  name              VARCHAR(255) NOT NULL,
  category_id       INT UNSIGNED NULL,
  brand_model       VARCHAR(255) NULL,
  serial_number     VARCHAR(150) NULL,
  status            ENUM('available', 'borrowed', 'maintenance', 'disposed') NOT NULL DEFAULT 'available',
  storage_location  VARCHAR(255) NULL,
  acquired_date     DATE         NULL,
  image_path        VARCHAR(255) NULL,
  note              TEXT         NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_assets_category FOREIGN KEY (category_id) REFERENCES inv_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_assets_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ใบเบิกวัสดุ (เลขที่ = เลขรัน/ปี พ.ศ. เช่น 12/2569)
CREATE TABLE IF NOT EXISTS inv_requisitions (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requisition_no      VARCHAR(50)  NOT NULL UNIQUE,
  purpose             VARCHAR(255) NULL,
  requester_name      VARCHAR(150) NULL,
  requester_position  VARCHAR(150) NULL,
  created_by          INT UNSIGNED NOT NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by         INT UNSIGNED NULL,               -- NULL = รออนุมัติ
  approved_at         DATETIME     NULL,
  CONSTRAINT fk_inv_req_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_req_approved_by FOREIGN KEY (approved_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_requisition_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requisition_id      INT UNSIGNED NOT NULL,
  item_type           ENUM('material', 'asset') NOT NULL,
  material_id         INT UNSIGNED NULL,
  asset_id            INT UNSIGNED NULL,
  item_name           VARCHAR(255) NOT NULL,
  unit                VARCHAR(50)  NULL,
  quantity_requested  INT          NOT NULL DEFAULT 1,
  quantity_issued     INT          NOT NULL DEFAULT 1,
  note                VARCHAR(255) NULL,
  CONSTRAINT fk_inv_reqitem_requisition FOREIGN KEY (requisition_id) REFERENCES inv_requisitions(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_reqitem_material FOREIGN KEY (material_id) REFERENCES inv_materials(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_reqitem_asset FOREIGN KEY (asset_id) REFERENCES inv_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_material_transactions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  material_id       INT UNSIGNED NOT NULL,
  user_id           INT UNSIGNED NOT NULL,
  requisition_id    INT UNSIGNED NULL,
  transaction_type  ENUM('withdraw', 'stock_in') NOT NULL,
  quantity          INT          NOT NULL,
  balance_after     INT          NOT NULL,
  note              VARCHAR(255) NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_mtx_material FOREIGN KEY (material_id) REFERENCES inv_materials(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_mtx_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_mtx_requisition FOREIGN KEY (requisition_id) REFERENCES inv_requisitions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_asset_transactions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id        INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  requisition_id  INT UNSIGNED NULL,
  action          ENUM('borrow', 'return') NOT NULL,
  borrower_name   VARCHAR(150) NULL,
  note            VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_atx_asset FOREIGN KEY (asset_id) REFERENCES inv_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_atx_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_atx_requisition FOREIGN KEY (requisition_id) REFERENCES inv_requisitions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  assigned_user_id  INT UNSIGNED NULL,                 -- ผู้ใช้งานประจำ (เช่น คอมพิวเตอร์ตั้งโต๊ะที่มีคนนั่งใช้ประจำ)
  image_path        VARCHAR(255) NULL,
  note              VARCHAR(500) NULL,                 -- หมายเหตุ
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_inv_equipment_status (status),
  CONSTRAINT fk_inv_equipment_type FOREIGN KEY (type_id) REFERENCES inv_equipment_types(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_equipment_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_equipment_assigned FOREIGN KEY (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO inv_equipment_types (id, name, sort_order) VALUES
  (1, 'ไมโครคอมพิวเตอร์ (PC Desktop)', 1),
  (2, 'โน้ตบุ๊ค (Notebook)', 2),
  (3, 'แท็บเล็ต (Tablet)', 3);

-- ทะเบียนการยืมครุภัณฑ์ (เลขที่ = เลขรัน/ปี พ.ศ. เช่น 1/2569)
-- การขออนุมัติยืมทำในระบบ e-office; ระบบนี้บันทึกผลการยืม แนบไฟล์ใบยืม และติดตามการคืน
-- borrowed = กำลังยืม, returned = คืนแล้ว (pending/rejected/cancelled สงวนไว้ ไม่ใช้งาน)
CREATE TABLE IF NOT EXISTS inv_loans (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loan_no            VARCHAR(50)  NOT NULL UNIQUE,
  borrower_id        INT UNSIGNED NOT NULL,
  borrower_name      VARCHAR(150) NOT NULL,
  borrower_position  VARCHAR(150) NULL,
  borrower_org       VARCHAR(255) NULL,
  borrower_phone     VARCHAR(50)  NULL,
  doc_no             VARCHAR(100) NULL,               -- เลขที่หนังสือ/บันทึกข้อความในระบบ e-office
  file_path          VARCHAR(255) NULL,               -- ไฟล์ใบยืมที่แนบ (PDF/รูป) เก็บใน uploads/inventory/loans/
  file_name          VARCHAR(255) NULL,               -- ชื่อไฟล์เดิม
  purpose            VARCHAR(500) NULL,
  start_date         DATE         NOT NULL,
  due_date           DATE         NOT NULL,
  status             ENUM('pending', 'borrowed', 'returned', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
  lender_id          INT UNSIGNED NULL,               -- ผู้บันทึกการยืม
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

-- ----------------------------------------------------------------------------
-- dcc_ : ระบบบริหารจัดการศูนย์ดิจิทัลชุมชน (เดิมฐานข้อมูล digitalcc_khonkaen)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS dcc_centers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30)  NULL,                    -- รหัสศูนย์ เช่น ONDE0728
  name          VARCHAR(255) NOT NULL,
  center_type   VARCHAR(100) NOT NULL DEFAULT '',     -- สถานศึกษา / องค์กรปกครองส่วนท้องถิ่น / สกร. / วัด/ชุมชน
  house_no      VARCHAR(30)  NOT NULL DEFAULT '',
  moo           VARCHAR(10)  NOT NULL DEFAULT '',
  address       VARCHAR(255) NOT NULL DEFAULT '',
  village       VARCHAR(100) NOT NULL DEFAULT '',
  subdistrict   VARCHAR(100) NOT NULL DEFAULT '',
  district      VARCHAR(100) NOT NULL DEFAULT '',
  province      VARCHAR(100) NOT NULL DEFAULT 'ขอนแก่น',
  postcode      VARCHAR(10)  NOT NULL DEFAULT '',
  lat           DECIMAL(10,7) NULL,
  lng           DECIMAL(10,7) NULL,
  phone         VARCHAR(50)  NOT NULL DEFAULT '',
  email         VARCHAR(150) NOT NULL DEFAULT '',
  facebook      VARCHAR(255) NOT NULL DEFAULT '',
  open_hours    VARCHAR(150) NOT NULL DEFAULT '',
  services      TEXT NULL,
  description   TEXT NULL,
  cover_image   VARCHAR(255) NOT NULL DEFAULT '',     -- path ภายใต้ uploads/ เช่น digitalcc/centers/x.jpg
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dcc_code (code),
  KEY idx_dcc_district (district)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dcc_center_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  center_id   INT UNSIGNED NOT NULL,
  file        VARCHAR(255) NOT NULL,
  caption     VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dcc_cimg_center FOREIGN KEY (center_id) REFERENCES dcc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dcc_center_staff (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  center_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  position    VARCHAR(150) NOT NULL DEFAULT '',
  phone       VARCHAR(50)  NOT NULL DEFAULT '',
  email       VARCHAR(150) NOT NULL DEFAULT '',
  line_id     VARCHAR(100) NOT NULL DEFAULT '',
  bio         TEXT NULL,
  photo       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dcc_staff_center FOREIGN KEY (center_id) REFERENCES dcc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dcc_activities (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  center_id     INT UNSIGNED NOT NULL,
  title         VARCHAR(255) NOT NULL,
  activity_date DATE NULL,
  organizer     VARCHAR(255) NOT NULL DEFAULT '',
  participants  INT UNSIGNED NOT NULL DEFAULT 0,
  description   TEXT NULL,
  cover_image   VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dcc_center_date (center_id, activity_date),
  CONSTRAINT fk_dcc_act_center FOREIGN KEY (center_id) REFERENCES dcc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dcc_activity_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id INT UNSIGNED NOT NULL,
  file        VARCHAR(255) NOT NULL,
  caption     VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dcc_aimg_act FOREIGN KEY (activity_id) REFERENCES dcc_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การตรวจเยี่ยมศูนย์ (แต่ละครั้งสร้างรายการกิจกรรมคู่กันเพื่อเก็บภาพ)
CREATE TABLE IF NOT EXISTS dcc_visits (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  center_id          INT UNSIGNED NOT NULL,
  activity_id        INT UNSIGNED NULL,
  visit_date         DATE NOT NULL,
  visit_time         TIME NULL,
  data               LONGTEXT NULL,                 -- คำตอบส่วนที่ 1–3 (JSON)
  problems           TEXT NULL,
  suggestions        TEXT NULL,
  informant_name     VARCHAR(150) NOT NULL DEFAULT '',
  informant_position VARCHAR(150) NOT NULL DEFAULT '',
  inspector_name     VARCHAR(150) NOT NULL DEFAULT '',
  inspector_position VARCHAR(150) NOT NULL DEFAULT '',
  lat                DECIMAL(10,7) NULL,
  lng                DECIMAL(10,7) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dcc_visit_center_date (center_id, visit_date),
  CONSTRAINT fk_dcc_visit_center FOREIGN KEY (center_id) REFERENCES dcc_centers(id) ON DELETE CASCADE,
  CONSTRAINT fk_dcc_visit_act FOREIGN KEY (activity_id) REFERENCES dcc_activities(id) ON DELETE SET NULL,
  CONSTRAINT fk_dcc_visit_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- pay_ : ระบบสลิปเงินเดือนพนักงาน (payslip) — ย้ายมาจากระบบ Google Sheet เดิม
-- ----------------------------------------------------------------------------

-- รายชื่อพนักงานตามรหัส (เลขที่ในสลิป เช่น 001) ผูกกับบัญชีผู้ใช้ — พนักงานดูได้เฉพาะสลิปของรหัสตนเอง
CREATE TABLE IF NOT EXISTS pay_employees (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL UNIQUE,
  full_name   VARCHAR(150) NOT NULL,
  user_id     INT UNSIGNED NULL UNIQUE,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_employees_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- สลิปเงินเดือน 1 แถว = พนักงาน 1 คน / 1 เดือน (ปี พ.ศ.)
CREATE TABLE IF NOT EXISTS pay_slips (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  year            SMALLINT UNSIGNED NOT NULL,          -- พ.ศ.
  month           TINYINT UNSIGNED NOT NULL,           -- 1–12
  full_name       VARCHAR(150) NOT NULL,               -- ชื่อตามข้อมูลเดือนนั้น
  salary          DECIMAL(12,2) NULL,                  -- อัตราเงินเดือน
  arrears         DECIMAL(12,2) NULL,                  -- เงินตกเบิก
  cola            DECIMAL(12,2) NULL,                  -- ค่าครองชีพชั่วคราว
  total_income    DECIMAL(12,2) NULL,                  -- รวมรายได้
  tax             DECIMAL(12,2) NULL,                  -- ภาษี
  social_security DECIMAL(12,2) NULL,                  -- เงินประกันสังคม
  loan_welfare    DECIMAL(12,2) NULL,                  -- เงินกู้สวัสดิการ
  loan_ghb        DECIMAL(12,2) NULL,                  -- เงินกู้ ธอส.
  loan_slf        DECIMAL(12,2) NULL,                  -- เงินกู้ กยศ.
  loan_islamic    DECIMAL(12,2) NULL,                  -- เงินกู้ธนาคารอิสลาม
  total_deduct    DECIMAL(12,2) NULL,                  -- รวมรายจ่าย
  net_pay         DECIMAL(12,2) NULL,                  -- เงินเดือนคงเหลือ
  status          ENUM('pending', 'approved') NOT NULL DEFAULT 'pending',
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_slip (employee_id, year, month),
  KEY idx_pay_slips_period (year, month),
  KEY idx_pay_slips_status (status),
  CONSTRAINT fk_pay_slips_employee FOREIGN KEY (employee_id) REFERENCES pay_employees(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_slips_approved_by FOREIGN KEY (approved_by) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_slips_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ค่าตั้งค่า: ผู้ลงนามในสลิป ลายเซ็น
CREATE TABLE IF NOT EXISTS pay_settings (
  `key`       VARCHAR(50)  NOT NULL PRIMARY KEY,
  `value`     VARCHAR(500) NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ############################################################################
-- ส่วนที่ 2: ย้ายข้อมูลระบบวัสดุ (database/legacy/01_inventory.sql)
-- ############################################################################
-- ============================================================================
-- ย้ายข้อมูลจากระบบวัสดุเดิม (ฐานข้อมูล `nsokhkaen_inventory`) เข้าสู่ระบบใหม่ (`nsokhkaen`)
-- รายละเอียดขั้นตอนทั้งหมด: docs/MIGRATION.md
--
-- เงื่อนไข:
--   1. ฐานข้อมูลเดิมผ่าน migration ของระบบเดิมครบถึง 009_add_requisition_approval.sql แล้ว
--   2. ฐานข้อมูลใหม่สร้างจาก database/schema.sql แล้ว และ "ยังว่าง" (ห้ามรัน seed.sql ก่อน)
--   3. ฐานข้อมูลทั้งสองอยู่บน MySQL/MariaDB เครื่องเดียวกัน
--   ถ้าชื่อฐานข้อมูลเดิมไม่ใช่ nsokhkaen_inventory ให้แทนที่ `nsokhkaen_inventory` ทั้งไฟล์
--   (หรือใช้ php tools/migrate_legacy.php --inventory-db=ชื่อจริง ซึ่งแทนที่ให้อัตโนมัติ)
--
-- สิ่งที่คงไว้เหมือนเดิม: id ทุกตาราง, ชื่อผู้ใช้/รหัสผ่าน/PIN (hash เดิม), qr_code ของวัสดุ
-- (สติ๊กเกอร์เดิมยังสแกนได้), เลขที่ใบเบิก และลำดับเลขที่ใบเบิกถัดไป
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ---------- ผู้ใช้ → ระบบกลาง (คง id เดิม) ----------
-- admin ของระบบเดิม ("จัดการได้ทุกระบบ") = ผู้ดูแลระบบกลาง (is_superadmin) ในระบบใหม่
INSERT INTO core_users (id, username, password_hash, full_name, position, pin_hash, is_superadmin, is_active, created_at, updated_at)
SELECT id, username, password_hash, full_name, position, pin_hash, (role = 'admin'), is_active, created_at, updated_at
FROM `nsokhkaen_inventory`.`users`;

-- สิทธิ์ในระบบวัสดุตาม role เดิม (admin / staff)
INSERT INTO core_user_roles (user_id, module_key, role)
SELECT id, 'inventory', role FROM `nsokhkaen_inventory`.`users`;

-- ---------- ค่าตั้งค่า ----------
-- app_name / app_url เดิมเป็นของระบบวัสดุ ไม่ย้าย — ระบบใหม่ใช้ค่ากลางด้านล่าง (แก้ได้ที่เมนูตั้งค่าระบบ)
INSERT INTO inv_settings (`key`, `value`)
SELECT `key`, `value` FROM `nsokhkaen_inventory`.`settings`
WHERE `key` IN ('issuer_name', 'issuer_position', 'approver_name', 'approver_position');

INSERT IGNORE INTO core_settings (`key`, `value`) VALUES
('app_name', 'ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น'),
('org_name', 'สำนักงานสถิติจังหวัดขอนแก่น');

-- ---------- ข้อมูลหลัก ----------
INSERT INTO inv_storage_locations (id, name, created_at)
SELECT id, name, created_at FROM `nsokhkaen_inventory`.`storage_locations`;

INSERT INTO inv_categories (id, name, item_type, created_at)
SELECT id, name, item_type, created_at FROM `nsokhkaen_inventory`.`categories`;

-- รูปภาพย้ายจาก uploads/materials/ → uploads/inventory/materials/
INSERT INTO inv_materials (id, material_code, qr_code, name, category_id, category_code, unit, unit_cost,
                           stock_qty, min_stock, storage_location, image_path, note, created_by, created_at, updated_at)
SELECT id, material_code, qr_code, name, category_id, category_code, unit, unit_cost,
       stock_qty, min_stock, storage_location,
       CASE WHEN image_path LIKE 'uploads/materials/%'
            THEN CONCAT('uploads/inventory/materials/', SUBSTRING(image_path, CHAR_LENGTH('uploads/materials/') + 1))
            ELSE image_path END,
       note, created_by, created_at, updated_at
FROM `nsokhkaen_inventory`.`materials`;

-- ครุภัณฑ์ (ไม่มีหน้าจอใช้งานแล้ว แต่ย้ายไว้เพื่อรักษาข้อมูลเดิม)
INSERT INTO inv_assets (id, asset_code, qr_code, name, category_id, brand_model, serial_number, status,
                        storage_location, acquired_date, image_path, note, created_by, created_at, updated_at)
SELECT id, asset_code, qr_code, name, category_id, brand_model, serial_number, status,
       storage_location, acquired_date,
       CASE WHEN image_path LIKE 'uploads/assets/%'
            THEN CONCAT('uploads/inventory/assets/', SUBSTRING(image_path, CHAR_LENGTH('uploads/assets/') + 1))
            ELSE image_path END,
       note, created_by, created_at, updated_at
FROM `nsokhkaen_inventory`.`assets`;

-- ---------- ใบเบิกและประวัติ ----------
INSERT INTO inv_requisitions (id, requisition_no, purpose, requester_name, requester_position,
                              created_by, created_at, approved_by, approved_at)
SELECT id, requisition_no, purpose, requester_name, requester_position,
       created_by, created_at, approved_by, approved_at
FROM `nsokhkaen_inventory`.`requisitions`;

INSERT INTO inv_requisition_items (id, requisition_id, item_type, material_id, asset_id, item_name, unit,
                                   quantity_requested, quantity_issued, note)
SELECT id, requisition_id, item_type, material_id, asset_id, item_name, unit,
       quantity_requested, quantity_issued, note
FROM `nsokhkaen_inventory`.`requisition_items`;

INSERT INTO inv_material_transactions (id, material_id, user_id, requisition_id, transaction_type,
                                       quantity, balance_after, note, created_at)
SELECT id, material_id, user_id, requisition_id, transaction_type, quantity, balance_after, note, created_at
FROM `nsokhkaen_inventory`.`material_transactions`;

INSERT INTO inv_asset_transactions (id, asset_id, user_id, requisition_id, action, borrower_name, note, created_at)
SELECT id, asset_id, user_id, requisition_id, action, borrower_name, note, created_at
FROM `nsokhkaen_inventory`.`asset_transactions`;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ตรวจสอบจำนวนแถว (ควรเท่ากับฐานข้อมูลเดิม)
SELECT 'users' AS tbl, (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`users`) AS legacy, (SELECT COUNT(*) FROM core_users) AS migrated
UNION ALL SELECT 'materials', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`materials`), (SELECT COUNT(*) FROM inv_materials)
UNION ALL SELECT 'requisitions', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`requisitions`), (SELECT COUNT(*) FROM inv_requisitions)
UNION ALL SELECT 'requisition_items', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`requisition_items`), (SELECT COUNT(*) FROM inv_requisition_items)
UNION ALL SELECT 'material_transactions', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`material_transactions`), (SELECT COUNT(*) FROM inv_material_transactions)
UNION ALL SELECT 'stock_qty_total', (SELECT COALESCE(SUM(stock_qty),0) FROM `nsokhkaen_inventory`.`materials`), (SELECT COALESCE(SUM(stock_qty),0) FROM inv_materials);

-- ############################################################################
-- ส่วนที่ 3: ย้ายข้อมูลระบบศูนย์ดิจิทัลชุมชน (database/legacy/02_digitalcc.sql)
-- ############################################################################
-- ============================================================================
-- ย้ายข้อมูลจากระบบศูนย์ดิจิทัลชุมชนเดิม (ฐานข้อมูล `digitalcc_khonkaen`) เข้าสู่ระบบใหม่ (`nsokhkaen`)
-- รันหลัง 01_inventory.sql (หรือหลังติดตั้งใหม่ถ้าไม่มีระบบวัสดุเดิม) — รายละเอียด: docs/MIGRATION.md
--
-- เงื่อนไข: ฐานข้อมูลเดิมรัน database/migrate_visits.sql ของระบบเดิมแล้ว (มีตาราง visits และคอลัมน์ code, house_no, moo)
-- ถ้าชื่อฐานข้อมูลเดิมไม่ใช่ digitalcc_khonkaen ให้แทนที่ `digitalcc_khonkaen` ทั้งไฟล์
--
-- ผู้ใช้: ถ้าชื่อผู้ใช้ซ้ำกับบัญชีที่มีอยู่แล้ว (เช่น admin จากระบบวัสดุ) ถือว่าเป็นคนเดียวกัน
--         ใช้รหัสผ่านเดิมของบัญชีที่มีอยู่ แล้วเพิ่มสิทธิ์ระบบศูนย์ฯ ให้
-- รูปภาพ: path ในฐานข้อมูลเติมหน้า digitalcc/ (ไฟล์ต้องคัดลอกจาก uploads/ เดิม → uploads/digitalcc/)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ---------- ผู้ใช้ ----------
INSERT INTO core_users (username, password_hash, full_name, created_at)
SELECT u.username, u.password_hash, u.fullname, u.created_at
FROM `digitalcc_khonkaen`.`users` u
WHERE NOT EXISTS (SELECT 1 FROM core_users c WHERE c.username = u.username COLLATE utf8mb4_unicode_ci);

-- ผู้ใช้ระบบเดิมทุกคนมีสิทธิ์เต็ม → ผู้ดูแลระบบศูนย์ฯ
INSERT INTO core_user_roles (user_id, module_key, role)
SELECT c.id, 'digitalcc', 'admin'
FROM `digitalcc_khonkaen`.`users` u
JOIN core_users c ON c.username = u.username COLLATE utf8mb4_unicode_ci
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- ---------- ศูนย์ (คง id เดิม เพื่อให้ลิงก์ center.html?id= เดิมยังใช้ได้) ----------
INSERT INTO dcc_centers (id, code, name, center_type, house_no, moo, address, village, subdistrict, district, province,
                         postcode, lat, lng, phone, email, facebook, open_hours, services, description, cover_image,
                         is_active, created_at, updated_at)
SELECT id, code, name,
       CASE WHEN center_type = 'กศน.' THEN 'สกร.' ELSE center_type END,
       house_no, moo, address, village, subdistrict, district, province, postcode, lat, lng, phone, email, facebook,
       open_hours, services, description,
       CASE WHEN cover_image <> '' THEN CONCAT('digitalcc/', cover_image) ELSE '' END,
       is_active, created_at, updated_at
FROM `digitalcc_khonkaen`.`centers`;

INSERT INTO dcc_center_images (id, center_id, file, caption, created_at)
SELECT id, center_id, CONCAT('digitalcc/', file), caption, created_at FROM `digitalcc_khonkaen`.`center_images`;

INSERT INTO dcc_center_staff (id, center_id, name, position, phone, email, line_id, bio, photo, sort_order, created_at)
SELECT id, center_id, name, position, phone, email, line_id, bio,
       CASE WHEN photo <> '' THEN CONCAT('digitalcc/', photo) ELSE '' END, sort_order, created_at
FROM `digitalcc_khonkaen`.`center_staff`;

INSERT INTO dcc_activities (id, center_id, title, activity_date, organizer, participants, description, cover_image, created_at, updated_at)
SELECT id, center_id, title, activity_date, organizer, participants, description,
       CASE WHEN cover_image <> '' THEN CONCAT('digitalcc/', cover_image) ELSE '' END, created_at, updated_at
FROM `digitalcc_khonkaen`.`activities`;

INSERT INTO dcc_activity_images (id, activity_id, file, caption, created_at)
SELECT id, activity_id, CONCAT('digitalcc/', file), caption, created_at FROM `digitalcc_khonkaen`.`activity_images`;

-- ---------- การตรวจเยี่ยม (created_by เดิม → id ผู้ใช้ใหม่ โดยจับคู่ด้วยชื่อผู้ใช้) ----------
INSERT INTO dcc_visits (id, center_id, activity_id, visit_date, visit_time, data, problems, suggestions,
                        informant_name, informant_position, inspector_name, inspector_position, lat, lng,
                        created_by, created_at, updated_at)
SELECT v.id, v.center_id, v.activity_id, v.visit_date, v.visit_time, v.data, v.problems, v.suggestions,
       v.informant_name, v.informant_position, v.inspector_name, v.inspector_position, v.lat, v.lng,
       c.id, v.created_at, v.updated_at
FROM `digitalcc_khonkaen`.`visits` v
LEFT JOIN `digitalcc_khonkaen`.`users` lu ON lu.id = v.created_by
LEFT JOIN core_users c ON c.username = lu.username COLLATE utf8mb4_unicode_ci;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'centers' AS tbl, (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`centers`) AS legacy, (SELECT COUNT(*) FROM dcc_centers) AS migrated
UNION ALL SELECT 'center_staff', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`center_staff`), (SELECT COUNT(*) FROM dcc_center_staff)
UNION ALL SELECT 'center_images', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`center_images`), (SELECT COUNT(*) FROM dcc_center_images)
UNION ALL SELECT 'activities', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`activities`), (SELECT COUNT(*) FROM dcc_activities)
UNION ALL SELECT 'activity_images', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`activity_images`), (SELECT COUNT(*) FROM dcc_activity_images)
UNION ALL SELECT 'visits', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`visits`), (SELECT COUNT(*) FROM dcc_visits);

-- ############################################################################
-- ส่วนที่ 4: ค่าตั้งค่าของสำนักงาน
-- ############################################################################
-- URL ของระบบใหม่ (ใช้ใน QR Code) — เครื่องเซิร์ฟเวอร์เดิมของระบบวัสดุคือ 192.168.144.83
-- ถ้าติดตั้งที่อื่น แก้ภายหลังได้ที่ ผู้ดูแลระบบกลาง → ตั้งค่าระบบ
INSERT INTO core_settings (`key`, `value`) VALUES ('app_url', 'http://192.168.144.83/nsokhkaen/')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- สรุปผู้ใช้และสิทธิ์หลังย้าย (ตรวจว่าถูกต้อง แล้วปรับได้ที่เมนู ผู้ใช้งานและสิทธิ์)
SELECT u.id, u.username, u.full_name, u.is_superadmin AS superadmin,
       GROUP_CONCAT(CONCAT(r.module_key, ':', r.role) ORDER BY r.module_key SEPARATOR ', ') AS roles,
       (u.pin_hash IS NOT NULL) AS has_pin
FROM core_users u LEFT JOIN core_user_roles r ON r.user_id = u.id
GROUP BY u.id ORDER BY u.id;
