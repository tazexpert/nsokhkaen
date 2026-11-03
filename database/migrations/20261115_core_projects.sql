-- ============================================================================
-- โครงการที่รับผิดชอบของสำนักงาน (core_projects) — ใช้เป็นรหัสงานในแผนการปฏิบัติงาน — รันซ้ำได้
--   · ย้ายรหัสงานเดิม (cal_plan_codes) มาเป็นโครงการ · ช่องในแผนเดิม (cal_plan_cells) แยกเป็น
--     cal_plan_work (งานโครงการ) และ cal_plan_guard (เวรศาลากลาง "*") แล้วลบตารางเดิม
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261115_core_projects.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS core_projects (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(20)  NOT NULL,                 -- ตัวย่อ ใช้ในแผนการปฏิบัติงาน เช่น สรง
  name           VARCHAR(255) NOT NULL,                 -- ชื่อโครงการเต็ม
  fiscal_year    SMALLINT UNSIGNED NULL,                -- ปีงบประมาณ (พ.ศ.)
  start_date     DATE         NULL,                     -- ช่วงปฏิบัติงาน (ไม่บังคับ)
  end_date       DATE         NULL,
  work_group_id  INT UNSIGNED NULL,                     -- กลุ่มงานที่รับผิดชอบ
  owner_id       INT UNSIGNED NULL,                     -- ผู้รับผิดชอบหลัก
  description    TEXT         NULL,
  color          VARCHAR(7)   NOT NULL DEFAULT '#0d6efd',
  sort_order     INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_core_project_code (code),
  CONSTRAINT fk_core_project_group FOREIGN KEY (work_group_id) REFERENCES core_work_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_core_project_owner FOREIGN KEY (owner_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- งานโครงการในแผน (คน × วัน × โครงการ) · เวรศาลากลางในแผน (คน × วัน)
CREATE TABLE IF NOT EXISTS cal_plan_work (
  person_id   INT UNSIGNED NOT NULL,
  day         TINYINT UNSIGNED NOT NULL,
  project_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (person_id, day, project_id),
  KEY idx_cal_plan_work_project (project_id),
  CONSTRAINT fk_cal_pw_person FOREIGN KEY (person_id) REFERENCES cal_plan_people(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_pw_project FOREIGN KEY (project_id) REFERENCES core_projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cal_plan_guard (
  person_id   INT UNSIGNED NOT NULL,
  day         TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (person_id, day),
  CONSTRAINT fk_cal_pg_person FOREIGN KEY (person_id) REFERENCES cal_plan_people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- ย้ายข้อมูลจากรหัสงานเดิม (ถ้ามี) ----------
CREATE TABLE IF NOT EXISTS cal_plan_codes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL DEFAULT '',
  kind ENUM('field', 'guard') NOT NULL DEFAULT 'field', color VARCHAR(7) NOT NULL DEFAULT '#0d6efd', sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cal_plan_cells (person_id INT UNSIGNED NOT NULL, day TINYINT UNSIGNED NOT NULL, code_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (person_id, day, code_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO core_projects (code, name, color, sort_order, is_active)
SELECT code, IF(name = '', code, name), color, sort_order, is_active FROM cal_plan_codes WHERE kind = 'field';

INSERT IGNORE INTO cal_plan_work (person_id, day, project_id)
SELECT c.person_id, c.day, p.id FROM cal_plan_cells c
JOIN cal_plan_codes k ON k.id = c.code_id AND k.kind = 'field'
JOIN core_projects p ON p.code = k.code
JOIN cal_plan_people pp ON pp.id = c.person_id;

INSERT IGNORE INTO cal_plan_guard (person_id, day)
SELECT c.person_id, c.day FROM cal_plan_cells c
JOIN cal_plan_codes k ON k.id = c.code_id AND k.kind = 'guard'
JOIN cal_plan_people pp ON pp.id = c.person_id;

DROP TABLE IF EXISTS cal_plan_cells;
DROP TABLE IF EXISTS cal_plan_codes;

-- โครงการเริ่มต้น (ติดตั้งใหม่ / ยังไม่เคยมีรหัสงาน)
INSERT IGNORE INTO core_projects (code, name, color, sort_order) VALUES
  ('สรง', 'สรง', '#2563eb', 10),
  ('สศส', 'สศส', '#16a34a', 20),
  ('ธปท', 'ธปท', '#9333ea', 30),
  ('โพล', 'โพล', '#ea580c', 40),
  ('สพค', 'สพค', '#0891b2', 50);
