-- ============================================================================
-- ระบบบริหารโครงการ: ใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ (ออกใบสำคัญ) — รันซ้ำได้
--   พิมพ์ชุดเอกสาร: แบบ 8708 (ส่วนที่ 1 + หน้า 2) · แบบประกอบใบเบิกค่าใช้จ่าย · ใบรับรองแทนใบเสร็จรับเงิน (แบบ บก.111)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261126_ea_claims.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS ea_claims (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,                 -- ผู้ขอเบิก
  project_id     INT UNSIGNED NULL,                     -- โครงการที่ไปปฏิบัติงาน
  claim_date     DATE         NOT NULL,                 -- วันที่เขียนใบเบิก
  office         VARCHAR(150) NOT NULL DEFAULT '',      -- ที่ทำการ
  addressee      VARCHAR(150) NOT NULL DEFAULT '',      -- เรียน
  order_no       VARCHAR(100) NOT NULL DEFAULT '',      -- ตามคำสั่ง/บันทึก ที่
  order_date     DATE         NULL,                     -- ลงวันที่
  full_name      VARCHAR(150) NOT NULL DEFAULT '',      -- ข้าพเจ้า
  position       VARCHAR(150) NOT NULL DEFAULT '',      -- ตำแหน่ง
  level          VARCHAR(100) NOT NULL DEFAULT '',      -- ระดับ
  department     VARCHAR(150) NOT NULL DEFAULT '',      -- สังกัด
  companions     TEXT         NULL,                     -- พร้อมด้วย
  purpose        TEXT         NULL,                     -- เดินทางไปปฏิบัติราชการ
  depart_from    VARCHAR(10)  NOT NULL DEFAULT 'home',  -- home | office | country
  return_to      VARCHAR(10)  NOT NULL DEFAULT 'home',
  origin_address TEXT         NULL,                     -- ที่อยู่สถานที่ออกเดินทาง (แบบประกอบ / บก.111)
  claim_for      VARCHAR(10)  NOT NULL DEFAULT 'self',  -- self = ข้าพเจ้า | group = คณะเดินทาง
  per_diem_type  VARCHAR(10)  NOT NULL DEFAULT 'ก',
  per_diem_rate  DECIMAL(10,2) NOT NULL DEFAULT 240,    -- อัตราเต็มวัน
  per_diem_days  DECIMAL(5,1) NOT NULL DEFAULT 0,       -- จำนวนวัน (ครึ่งวัน = .5) — คำนวณจากเวลารายวัน แก้ได้
  per_diem_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  per_diem_note  VARCHAR(100) NOT NULL DEFAULT '',      -- เช่น (120x8)
  lodging_type   VARCHAR(100) NOT NULL DEFAULT '',
  lodging_days   DECIMAL(5,1) NOT NULL DEFAULT 0,
  lodging_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  vehicle_kind   VARCHAR(10)  NOT NULL DEFAULT 'car',   -- car (กม.ละ 4) | moto (กม.ละ 2) | other
  km_rate        DECIMAL(6,2) NOT NULL DEFAULT 4,
  vehicle_desc   VARCHAR(255) NOT NULL DEFAULT '',      -- คำอธิบายค่าพาหนะ (ว่าง = สร้างอัตโนมัติจากระยะทาง)
  vehicle_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_desc     VARCHAR(255) NOT NULL DEFAULT '',
  other_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
  receive_amount DECIMAL(12,2) NOT NULL DEFAULT 0,      -- ขอรับเงินเพียง (ปัดเศษสตางค์ทิ้ง)
  loan_no        VARCHAR(50)  NOT NULL DEFAULT '',      -- สัญญายืมเงินเลขที่
  loan_date      DATE         NULL,
  loan_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
  note           TEXT         NULL,                     -- หมายเหตุ (หน้า 2)
  status         VARCHAR(10)  NOT NULL DEFAULT 'draft', -- draft = ร่าง | issued = ออกใบสำคัญแล้ว
  issued_at      DATETIME     NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ea_claim_user (user_id, claim_date),
  KEY idx_ea_claim_project (project_id),
  CONSTRAINT fk_ea_claim_user FOREIGN KEY (user_id) REFERENCES core_users(id),
  CONSTRAINT fk_ea_claim_project FOREIGN KEY (project_id) REFERENCES core_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายวันที่เดินทาง (แบบประกอบใบเบิก + ใบรับรองแทนใบเสร็จรับเงิน)
CREATE TABLE IF NOT EXISTS ea_claim_days (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  claim_id       INT UNSIGNED NOT NULL,
  seq            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  trip_date      DATE         NOT NULL,
  depart_time    TIME         NULL,
  return_date    DATE         NULL,                     -- ว่าง = วันเดียวกัน
  return_time    TIME         NULL,
  places         TEXT         NULL,                     -- สถานที่ที่ไปปฏิบัติงาน (หลายบรรทัด)
  km             DECIMAL(10,2) NOT NULL DEFAULT 0,      -- ระยะทางไปกลับ
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0,      -- ค่าพาหนะของวันนี้ (กม. x อัตรา ปัดทศนิยม 2 ตำแหน่ง)
  extra          TEXT         NULL,                     -- รายการค่าใช้จ่ายที่ขอเบิกนอกเหนือจากเบี้ยเลี้ยง ที่พัก ค่าพาหนะ (ว่าง = ใช้วัตถุประสงค์)
  KEY idx_ea_claim_day (claim_id, seq),
  CONSTRAINT fk_ea_claim_day FOREIGN KEY (claim_id) REFERENCES ea_claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
