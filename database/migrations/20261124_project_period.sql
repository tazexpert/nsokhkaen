-- ============================================================================
-- โครงการที่รับผิดชอบ: คาบการสำรวจ (รายเดือน / รายไตรมาส / รายปี) — รันซ้ำได้
--   เติมให้อัตโนมัติจากไฟล์นำเข้า EA ของโครงการ (enumerate_type Q1–Q4 = รายไตรมาส, Month = รายเดือน)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261124_project_period.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE core_projects
  ADD COLUMN IF NOT EXISTS survey_period VARCHAR(10) NOT NULL DEFAULT '' AFTER fiscal_year;   -- monthly | quarterly | yearly ('' = ไม่ระบุ)

-- โครงการที่มี EA จากไฟล์อยู่แล้ว: เติมคาบการสำรวจจากประเภทการแจงนับ
UPDATE core_projects p
JOIN (SELECT project_id, SUM(enum_type LIKE 'Q_') q, SUM(enum_type = 'Month') m FROM ea_project_eas GROUP BY project_id) x ON x.project_id = p.id
SET p.survey_period = IF(x.q >= x.m, 'quarterly', 'monthly')
WHERE p.survey_period = '' AND (x.q > 0 OR x.m > 0);
