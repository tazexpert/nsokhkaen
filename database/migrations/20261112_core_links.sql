-- ============================================================================
-- ลิงก์และเครื่องมือ (หน้าแรก): เก็บในฐานข้อมูล ผู้ดูแลระบบกลางเพิ่ม/แก้ไข/ลบ/เรียงลำดับได้ — รันซ้ำได้
-- ใส่ลิงก์เริ่มต้นให้เฉพาะครั้งแรก (ตารางยังว่าง)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261112_core_links.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS core_links (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label         VARCHAR(150) NOT NULL,
  url           VARCHAR(500) NOT NULL,
  icon          VARCHAR(60)  NOT NULL DEFAULT 'bi-link-45deg',
  sort_order    INT          NOT NULL DEFAULT 0,
  members_only  TINYINT(1)   NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO core_links (label, url, icon, sort_order)
SELECT * FROM (
  SELECT 'สำนักงานสถิติจังหวัดขอนแก่น' AS label, 'https://khonkaen.nso.go.th/' AS url, 'bi-building' AS icon, 10 AS sort_order
  UNION ALL SELECT 'ระบบสารบรรณสำนักงานสถิติจังหวัดขอนแก่น', 'https://nso.eoffice.go.th/', 'bi-envelope-paper', 20
  UNION ALL SELECT 'ระบบสารบรรณจังหวัดขอนแก่น', 'https://khonkaen.eoffice.go.th/', 'bi-envelope-paper', 30
  UNION ALL SELECT 'Drive Province (ขอนแก่น)', 'https://driveprovince.nso.go.th/', 'bi-cloud', 40
  UNION ALL SELECT 'เว็บไซต์ศูนย์ดิจิทัลชุมชน', 'modules/digitalcc/public/', 'bi-globe', 50
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM core_links);
