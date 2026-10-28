-- ============================================================================
-- เพิ่มระบบสลิปเงินเดือนพนักงาน (pay_*) — ใช้กับฐานข้อมูลที่ติดตั้งไปแล้ว (รันซ้ำได้)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261031_payslip_init.sql
-- ข้อมูลสลิปเดิมจาก Google Sheet: นำเข้าทางหน้า "นำเข้าข้อมูล" ของระบบ (ไฟล์ .xlsx ที่ดาวน์โหลดจาก Google Sheet)
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

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
