-- ============================================================================
-- ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น (nsokhkaen)
-- โครงสร้างฐานข้อมูลทั้งหมด (ไม่มีข้อมูล) — ติดตั้งใหม่: รันไฟล์นี้ แล้วตามด้วย seed.sql
--
--   mysql -u root -p < database/schema.sql
--   mysql -u root -p < database/seed.sql
--
-- การตั้งชื่อตาราง: แยกตามระบบด้วย prefix
--   core_  ระบบกลาง (ผู้ใช้ สิทธิ์ ค่าตั้งค่า บันทึกการใช้งาน)
--   inv_   ระบบบริหารจัดการวัสดุและครุภัณฑ์ (inventory)
--   dcc_   ระบบบริหารจัดการศูนย์ดิจิทัลชุมชน (digitalcc)
--   pay_   ระบบสลิปเงินเดือนพนักงาน (payslip)
-- ระบบใหม่ในอนาคต: ใช้ prefix ของตัวเอง และอ้างอิงผู้ใช้ที่ core_users.id
-- ============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS nsokhkaen
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nsokhkaen;

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
