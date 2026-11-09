-- ============================================================================
-- ปฏิทินการปฏิบัติงาน: เวลาปฏิบัติราชการ (นำเข้าจาก PDF "รายงานข้อมูลเวลาปฏิบัติราชการ" ทุกวันจันทร์) — รันซ้ำได้
--   แสดงผลในแผนการปฏิบัติงาน: ส = สาย · ลาเช้า / ลาบ่าย · ร = ไปราชการ · ข = ขาดราชการ · ล = รับรองการมาปฏิบัติราชการ
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261128_cal_attendance.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS cal_attendance (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  work_date      DATE         NOT NULL,
  holiday        TINYINT(1)   NOT NULL DEFAULT 0,       -- วันหยุดราชการ (ตามไฟล์)
  shift          VARCHAR(30)  NOT NULL DEFAULT '',      -- รอบเวลาการทำงาน เช่น 08:30 - 16:30
  time_in        TIME         NULL,                     -- เวลาเข้า
  time_out       TIME         NULL,                     -- เวลาออก
  late           VARCHAR(20)  NOT NULL DEFAULT '',      -- ช่อง "สาย" ในไฟล์ (มีค่า = สาย)
  certify        VARCHAR(20)  NOT NULL DEFAULT '',      -- ช่อง "รับรองการมาปฏิบัติราชการ"
  leave_code     VARCHAR(20)  NOT NULL DEFAULT '',      -- ช่อง "ลา" (ก ป พ ร ...)
  absent_am      TINYINT(1)   NOT NULL DEFAULT 0,       -- ขาดงานครึ่งวันเช้า (ข ในไฟล์)
  absent_pm      TINYINT(1)   NOT NULL DEFAULT 0,
  detail         VARCHAR(255) NOT NULL DEFAULT '',      -- ประเภท/รายละเอียด
  report_at      DATETIME     NULL,                     -- เวลาที่พิมพ์รายงาน (วันนั้นและหลังจากนั้นยังไม่ตัดสิน)
  override_code  VARCHAR(5)   NOT NULL DEFAULT '',      -- ผู้ดูแลแก้ ข → ล (รับรองการมาปฏิบัติราชการ)
  override_by    INT UNSIGNED NULL,
  override_at    DATETIME     NULL,
  imported_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cal_att (user_id, work_date),
  KEY idx_cal_att_date (work_date),
  CONSTRAINT fk_cal_att_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
