-- ============================================================================
-- แทน migrations/20261213_kpi_office.sql สำหรับเซิร์ฟเวอร์ที่ไม่มีสิทธิ์ ALTER TABLE — รัน "ครั้งเดียว"
--   เพิ่มคอลัมน์ kpi_indicators.is_office (ตัวชี้วัดจากสำนักงาน) โดย: สำรองข้อมูล → ลบตาราง → สร้างใหม่ → ใส่ข้อมูลคืน
--   ใช้สิทธิ์ CREATE / DROP / INSERT / SELECT เท่านั้น · ข้อมูลตัวชี้วัดเดิมคงอยู่ (ตั้งเป็น "จากสำนักงาน" ทั้งหมด)
--   ตารางลูก (ระดับ กิจกรรม ผู้ปฏิบัติ ความก้าวหน้า ไฟล์) ไม่ถูกแตะ — อ้างอิง kpi_indicators ตาม id เดิม
--   ⚠ รันซ้ำหลังเปลี่ยนเป็น "ไม่ใช่" แล้ว ค่าจะกลับเป็น "จากสำนักงาน" · ถ้าหยุดกลางทาง ข้อมูลยังอยู่ในตาราง kpi_indicators_bak
--   php tools/sql_use_db.php database/noalter/20261213_kpi_office.sql   (→ storage/sql/20261213_kpi_office.sql ใช้ชื่อฐานข้อมูลจริง)
--   mysql -u root -p --default-character-set=utf8mb4 < storage/sql/20261213_kpi_office.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS kpi_indicators_bak;
CREATE TABLE kpi_indicators_bak AS SELECT id, fiscal_year, round_no, code, name, unit, weight, description, work_group_id, note, controller_id, collector_id, sort_order, created_by, created_at, updated_at FROM kpi_indicators;
DROP TABLE kpi_indicators;

CREATE TABLE kpi_indicators (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year    SMALLINT UNSIGNED NOT NULL,              -- ปีงบประมาณ (พ.ศ.)
  round_no       TINYINT UNSIGNED  NOT NULL DEFAULT 1,    -- รอบที่ 1 = 1 ต.ค. – 31 มี.ค. · รอบที่ 2 = 1 เม.ย. – 30 ก.ย.
  is_office      TINYINT(1)   NOT NULL DEFAULT 1,         -- 1 = ตัวชี้วัดจากสำนักงาน · 0 = ไม่ใช่
  code           VARCHAR(20)  NULL,                       -- ลำดับตัวชี้วัด เช่น 2, 1.1
  name           VARCHAR(500) NOT NULL,
  unit           VARCHAR(100) NULL,                       -- หน่วยวัด
  weight         DECIMAL(6,2) NULL,                       -- น้ำหนัก (ร้อยละ)
  description    TEXT         NULL,                       -- คำอธิบาย
  work_group_id  INT UNSIGNED NULL,                       -- กลุ่มงานที่รับผิดชอบ
  note           TEXT         NULL,                       -- หมายเหตุ
  controller_id  INT UNSIGNED NULL,                       -- ผู้ควบคุมตัวชี้วัด
  collector_id   INT UNSIGNED NULL,                       -- ผู้จัดเก็บข้อมูล
  sort_order     INT          NOT NULL DEFAULT 0,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_kpi_indicators_period (fiscal_year, round_no, sort_order),
  CONSTRAINT fk_kpi_indicators_wg FOREIGN KEY (work_group_id) REFERENCES core_work_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_kpi_indicators_ctrl FOREIGN KEY (controller_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_kpi_indicators_coll FOREIGN KEY (collector_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_kpi_indicators_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO kpi_indicators (id, fiscal_year, round_no, code, name, unit, weight, description, work_group_id, note, controller_id, collector_id, sort_order, created_by, created_at, updated_at)
SELECT id, fiscal_year, round_no, code, name, unit, weight, description, work_group_id, note, controller_id, collector_id, sort_order, created_by, created_at, updated_at FROM kpi_indicators_bak;
DROP TABLE kpi_indicators_bak;

SET FOREIGN_KEY_CHECKS = 1;

-- ตรวจผล: จำนวนตัวชี้วัด และมีคอลัมน์ is_office
SELECT COUNT(*) AS indicators, SUM(is_office) AS office FROM kpi_indicators;
