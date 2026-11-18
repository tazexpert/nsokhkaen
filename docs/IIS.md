# ติดตั้งบน Windows Server: IIS + PHP 8.5 + MariaDB (ฐานข้อมูล `db_khkaen`)

คู่มือสำหรับทีมเครือข่าย — ระบบเขียนมาสำหรับ Apache (XAMPP) จึงต้องติดตั้ง/ตั้งค่าเพิ่มตามนี้
ไฟล์ `web.config` สำหรับ IIS อยู่ในโปรเจกต์แล้ว (ทำหน้าที่แทน `.htaccess` ซึ่ง IIS ไม่อ่าน)

> คำสั่งในคู่มือนี้ใช้ **Command Prompt (cmd) แบบ Run as administrator** — PowerShell ใช้ `<` ไม่ได้ และ `>` เขียนไฟล์เป็น UTF-16 ทำให้ภาษาไทยเสีย

---

## 1. IIS — ติดตั้งเพิ่ม

| รายการ | ที่มา | ทำไมต้องมี |
|---|---|---|
| ฟีเจอร์ **CGI** | Server Manager → Add Roles and Features → Web Server (IIS) → Web Server → Application Development → **CGI** | รัน PHP แบบ FastCGI |
| **URL Rewrite 2.1** | ดาวน์โหลดจาก Microsoft (iis.net/downloads/microsoft/url-rewrite) — ไม่ได้มากับ IIS | ลิงก์ในระบบไม่มี `.php` (เช่น `plan?m=2026-11`) ถ้าไม่มีโมดูลนี้แทบทุกหน้าขึ้น 404 · และใช้ปิดโฟลเดอร์ส่วนตัว |
| Request Filtering | มากับ IIS (เปิดอยู่แล้ว) | ปิดไฟล์ `.sql` `.md` `.log` และโฟลเดอร์ที่ห้ามเปิด |

**สร้างเว็บ**
1. คัดลอกโฟลเดอร์ระบบไปไว้ เช่น `C:\inetpub\wwwroot\nsokhkaen`
2. **สร้าง Application Pool ก่อน** (หน้าต่างเลือก pool ตอนสร้าง Application แสดงเฉพาะ pool ที่มีอยู่แล้ว)
   IIS Manager → คลิกชื่อเครื่อง → **Application Pools** → **Add Application Pool…**
   - Name: `nsokhkaen` · .NET CLR version: **No Managed Code** · Managed pipeline mode: Integrated
   - หรือใช้คำสั่ง: `%windir%\system32\inetsrv\appcmd add apppool /name:nsokhkaen /managedRuntimeVersion:"" /managedPipelineMode:Integrated`
3. Default Web Site → คลิกขวาโฟลเดอร์ `nsokhkaen` → **Convert to Application** (หรือ Add Application) → **Select…** เลือก pool `nsokhkaen`
   — ถ้าใช้ DefaultAppPool แทน ให้เปลี่ยนชื่อบัญชีในข้อ 5 เป็น `IIS AppPool\DefaultAppPool`
4. ผูกนามสกุล `.php` กับ PHP (Handler Mappings → Add Module Mapping: `*.php` · FastCgiModule · `C:\PHP\php-cgi.exe`)
   — ถ้าติดตั้ง PHP ผ่าน Web Platform Installer / PHP Manager จะทำให้แล้ว

`web.config` ในโปรเจกต์ตั้งค่าเหล่านี้ให้แล้ว (ไม่ต้องทำเอง):
- หน้าแรก `index.php` · ปิดการแสดงรายการไฟล์ในโฟลเดอร์
- URL ไม่มี `.php` (ลิงก์เดิม/QR Code ที่มี `.php` ยังใช้ได้ — GET ถูกพาไป URL ใหม่)
- ขนาดคำขอสูงสุด 64 MB (ค่าเริ่มต้น IIS 30 MB)
- ปิดโฟลเดอร์ `core` `libs` `database` `storage` `tools` `docs` `uploads/payslip` `uploads/inventory/loans` `uploads/messages`
  และห้ามรันสคริปต์ใน `uploads` · ห้ามเปิด `config.php` `config.local.php` `.sql` `.md` `.log` `.ini`
- ชนิดไฟล์ฟอนต์ `.ttf` `.woff2` และรูป `.webp`

> ถ้าเปิดเว็บแล้วขึ้น **500.19** (config error) = ยังไม่ได้ติดตั้ง URL Rewrite หรือเซิร์ฟเวอร์ล็อกส่วน `requestFiltering` / `staticContent` ไว้
> (IIS Manager → ระดับเซิร์ฟเวอร์ → Feature Delegation → ตั้ง Request Filtering และ MIME Types เป็น Read/Write)

## 2. PHP 8.5 — php.ini

เปิด extension (ลบ `;` หน้าบรรทัด):
```ini
extension=pdo_mysql
extension=mbstring
extension=gd          ; QR Code และย่อรูป (JPEG/PNG/WebP)
extension=fileinfo    ; ตรวจชนิดไฟล์อัปโหลด
extension=openssl     ; สมุดบันทึกส่วนตัว (เข้ารหัส)
extension=intl        ; ตัดคำไทยในใบเบิกค่าเดินทาง (ไม่มีก็ใช้ได้ แต่ตัดคำแม่นน้อยกว่า)
```
(`iconv` `zlib` `simplexml` `dom` `json` `ctype` มีในตัว PHP บน Windows แล้ว)

ค่าที่แนะนำ:
```ini
memory_limit = 256M
max_execution_time = 120
date.timezone = Asia/Bangkok
session.save_path = "C:\PHP\sessions"     ; โฟลเดอร์ที่บัญชี IIS เขียนได้ (ข้อ 5)
display_errors = Off
log_errors = On
```
ขีดจำกัดอัปโหลด (10 MB/ไฟล์ · 64 MB/ครั้ง) ตั้งไว้แล้วใน `.user.ini` ของโปรเจกต์ (PHP แบบ FastCGI อ่านไฟล์นี้)

FastCGI ให้รอนานพอสำหรับนำเข้า Excel/PDF (ปรับ path ของ php-cgi.exe ให้ตรง):
```bat
%windir%\system32\inetsrv\appcmd set config -section:system.webServer/fastCgi /[fullPath='C:\PHP\php-cgi.exe'].activityTimeout:120 /[fullPath='C:\PHP\php-cgi.exe'].requestTimeout:300 /commit:apphost
```

> ระบบทดสอบกับ PHP 8.0–8.3 · PHP 8.5 ยังไม่ได้ทดสอบบนเครื่องจริง — หลังติดตั้งให้ตรวจตามข้อ 7
> ข้อผิดพลาดถูกบันทึกที่ `storage\logs\php_error.log` (ไม่แสดงบนหน้าเว็บ)

## 3. MariaDB — ฐานข้อมูล `db_khkaen`

```sql
CREATE DATABASE IF NOT EXISTS db_khkaen DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nsokhkaen'@'localhost' IDENTIFIED BY 'ตั้งรหัสผ่านที่ยาก';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES ON db_khkaen.* TO 'nsokhkaen'@'localhost';
FLUSH PRIVILEGES;
```
- MariaDB 10.3 ขึ้นไป
- **เขตเวลา**: ระบบเทียบเวลาในฐานข้อมูลกับเวลาใน PHP (เช่น การแจ้งเตือน กิจกรรมวันนี้) — Windows ของเซิร์ฟเวอร์ต้องตั้งเป็นเวลากรุงเทพ
  หรือเพิ่มใน `my.ini` ส่วน `[mysqld]`: `default-time-zone = '+07:00'` แล้วรีสตาร์ต MariaDB

## 4. ตั้งค่าระบบ — `config.local.php`

สร้างไฟล์ `config.local.php` ในโฟลเดอร์ระบบ (ไม่ต้องแก้ `config.php` — `git pull` จะไม่ทับไฟล์นี้):
```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'db_khkaen');
define('DB_USER', 'nsokhkaen');
define('DB_PASS', 'รหัสผ่านจากข้อ 3');
define('DEFAULT_APP_URL', 'http://ชื่อเครื่องหรือ IP/nsokhkaen/');
```
หลังเข้าระบบได้ ตั้ง **URL ของระบบ** ที่ ผู้ดูแลระบบกลาง → ตั้งค่าระบบ (ใช้กับลิงก์ใน QR Code)

## 5. สิทธิ์โฟลเดอร์

บัญชีที่ IIS ใช้รัน (Application Pool `nsokhkaen` = `IIS AppPool\nsokhkaen`) ต้องเขียนได้:
```bat
icacls "C:\inetpub\wwwroot\nsokhkaen\uploads" /grant "IIS AppPool\nsokhkaen:(OI)(CI)M"
icacls "C:\inetpub\wwwroot\nsokhkaen\storage" /grant "IIS AppPool\nsokhkaen:(OI)(CI)M"
mkdir C:\PHP\sessions
icacls "C:\PHP\sessions" /grant "IIS AppPool\nsokhkaen:(OI)(CI)M"
```

## 6. ข้อมูล

### ก. ย้ายจากเครื่องเดิม (XAMPP ฐานข้อมูล `nsokhkaen`) — กรณีใช้งานอยู่แล้ว
1. เครื่องเดิม: สำรองฐานข้อมูล (ไม่มีคำสั่ง `USE` ในไฟล์ จึงนำเข้าฐานข้อมูลชื่ออื่นได้เลย)
   ```bat
   C:\xampp\mysql\bin\mysqldump -u root -p --routines --single-transaction --default-character-set=utf8mb4 --result-file=nsokhkaen_full.sql nsokhkaen
   ```
2. เครื่องใหม่: นำเข้าลง `db_khkaen`
   ```bat
   mysql -u root -p --default-character-set=utf8mb4 db_khkaen < nsokhkaen_full.sql
   ```
3. คัดลอกโฟลเดอร์ `uploads\` ทั้งหมดจากเครื่องเดิม (รูป ลายมือชื่อ ใบยืม ไฟล์แนบ) ไปทับในเครื่องใหม่
   — **ไม่ต้องคัดลอก** `config.local.php` ของเครื่องเดิม (ใช้ไฟล์จากข้อ 4)

### ข. ติดตั้งใหม่ (ฐานข้อมูลว่าง)
ไฟล์ SQL ใน `database\` เขียน `USE nsokhkaen;` ไว้ — แปลงให้ใช้ `db_khkaen` (ตาม DB_NAME ใน `config.local.php`) ก่อนรัน:
```bat
cd C:\inetpub\wwwroot\nsokhkaen
C:\PHP\php.exe tools\sql_use_db.php database\schema.sql database\seed.sql database\migrations\20261207_cal_rota.sql
mysql -u root -p --default-character-set=utf8mb4 < storage\sql\schema.sql
mysql -u root -p --default-character-set=utf8mb4 < storage\sql\seed.sql
mysql -u root -p --default-character-set=utf8mb4 < storage\sql\20261207_cal_rota.sql
```
(`schema.sql` เป็นโครงสร้างล่าสุดแล้ว — ติดตั้งใหม่ไม่ต้องรันไฟล์อื่นใน `database\migrations\` · `20261207` ใส่รายชื่อเวรกิจกรรมเริ่มต้น)

### อัปเดตระบบภายหลัง
เมื่อมีไฟล์ใหม่ใน `database\migrations\` (ดูตารางใน `docs/MIGRATION.md`) ให้แปลงแล้วรันเฉพาะไฟล์ใหม่:
```bat
C:\PHP\php.exe tools\sql_use_db.php database\migrations\ชื่อไฟล์ใหม่.sql
mysql -u root -p --default-character-set=utf8mb4 < storage\sql\ชื่อไฟล์ใหม่.sql
```

## 7. ตรวจหลังติดตั้ง

| ตรวจ | ผลที่ถูกต้อง |
|---|---|
| เปิด `http://…/nsokhkaen/` และเข้าสู่ระบบ | หน้าแรกแสดงระบบงาน |
| เปิด `…/modules/calendar/plan` | แสดงแผนการปฏิบัติงาน (URL ไม่มี .php ใช้ได้) |
| เปิด `…/modules/calendar/plan.php` | ถูกพาไป `…/plan` อัตโนมัติ |
| เปิด `…/config.php` `…/docs/IIS.md` `…/database/schema.sql` `…/uploads/payslip/` | **404** (เปิดไม่ได้) |
| อัปโหลดรูปโปรไฟล์ / แนบไฟล์ในข้อความ | สำเร็จ (สิทธิ์โฟลเดอร์ถูกต้อง) |
| พิมพ์ QR Code วัสดุ · นำเข้า Excel สลิปเงินเดือน · อ่าน PDF ใบยืม | ใช้ได้ (gd / zlib / pdf.js) |
| `storage\logs\php_error.log` | ไม่มีข้อผิดพลาดใหม่ (มีคำเตือน Deprecated ของ PHP 8.5 ได้ ไม่กระทบการใช้งาน) |

## 8. อื่น ๆ
- เครื่องผู้ใช้ต้องเข้าอินเทอร์เน็ตได้ที่ `cdn.jsdelivr.net` `cdnjs.cloudflare.com` `code.jquery.com` `fonts.googleapis.com` `fonts.gstatic.com`
  (Bootstrap ไอคอน ฟอนต์ ตัวอ่าน PDF) — อย่าบล็อกที่ firewall/proxy
- URL ระบบเดิมและสติ๊กเกอร์ QR ที่พิมพ์ไว้ (`/nsokhkaen_inventory/public/scan.php?qr=…`) — ตัว redirect ใน `legacy-redirects` เขียนสำหรับ Apache
  ถ้าย้ายมาเครื่องนี้ ให้ตั้ง HTTP Redirect ใน IIS จาก `/nsokhkaen_inventory/public/scan.php` ไป `/nsokhkaen/modules/inventory/scan.php` (ส่งต่อ query string)
