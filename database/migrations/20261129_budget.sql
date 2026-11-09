-- ============================================================================
-- ระบบงบประมาณ (budget): รายการงบประมาณ + รับเข้า (ได้รับ) / เบิกจ่าย — รันซ้ำได้
--   ชื่อโครงการที่ได้รับ = โครงการสำรวจ (core_projects) หรือ รายการงบประมาณ (bud_items)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261129_budget.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- รายการงบประมาณนอกจากโครงการสำรวจ (ผู้ดูแลระบบงบประมาณเพิ่ม/แก้ไขได้)
CREATE TABLE IF NOT EXISTS bud_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  is_other     TINYINT(1)   NOT NULL DEFAULT 0,            -- รายจ่ายอื่น (โครงการนอกสำนักงานสถิติแห่งชาติ เช่น โพล)
  project_id   INT UNSIGNED NULL,                           -- โครงการสำรวจที่นับเป็นรายการนี้แทน (เช่น โพล → รายจ่ายอื่น)
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bud_item_name (name),
  CONSTRAINT fk_bud_item_project FOREIGN KEY (project_id) REFERENCES core_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bud_items (name, sort_order) VALUES
  ('เงินนอกงบประมาณ', 10), ('ค่าน้ำมันเชื้อเพลิง', 20), ('ค่าพานพุ่ม', 30), ('ค่าวัสดุ', 40),
  ('ค่าเช่าเครื่องถ่ายเอกสาร', 50), ('ค่าตอบแทนพนักงานราชการ', 60), ('ประกันสังคมพนักงานราชการ', 70),
  ('ค่าเช่าบ้านข้าราชการ', 80), ('ค่าสาธารณูปโภค', 90), ('จ้างเหมา จนท เก็บรวบรวมข้อมูล', 100),
  ('จ้างเหมา จนท บริหารจัดการข้อมูล', 110), ('คนขับรถยนต์', 120), ('คนทำความสะอาด', 130), ('เน็ต NT', 140);
INSERT IGNORE INTO bud_items (name, is_other, project_id, sort_order)
  SELECT 'รายจ่ายอื่น (โพล)', 1, (SELECT id FROM core_projects WHERE code = 'โพล' LIMIT 1), 900;

-- รับเข้างบประมาณ (ได้รับ) / เบิกจ่ายงบประมาณ (เบิกจ่าย)
CREATE TABLE IF NOT EXISTS bud_txns (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  txn_type     VARCHAR(10)   NOT NULL,                      -- receive = ได้รับ · spend = เบิกจ่าย
  txn_date     DATE          NOT NULL,                      -- วันที่ เดือน ปี
  fiscal_year  SMALLINT UNSIGNED NOT NULL,                  -- ปีงบประมาณ (พ.ศ.) 1 ต.ค. – 30 ก.ย.
  doc_no       VARCHAR(100)  NOT NULL DEFAULT '',           -- ที่เอกสาร (เลขที่เอกสาร)
  description  VARCHAR(500)  NOT NULL,                      -- รายการ
  project_id   INT UNSIGNED  NULL,                          -- ชื่อโครงการที่ได้รับ: โครงการสำรวจ
  item_id      INT UNSIGNED  NULL,                          --   หรือ รายการงบประมาณ (อย่างใดอย่างหนึ่ง)
  amount       DECIMAL(14,2) NOT NULL,
  note         VARCHAR(500)  NOT NULL DEFAULT '',
  created_by   INT UNSIGNED  NULL,
  updated_by   INT UNSIGNED  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bud_fy (fiscal_year, txn_type),
  KEY idx_bud_date (txn_date),
  CONSTRAINT fk_bud_txn_project FOREIGN KEY (project_id) REFERENCES core_projects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bud_txn_item FOREIGN KEY (item_id) REFERENCES bud_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bud_txn_cby FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_bud_txn_uby FOREIGN KEY (updated_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
