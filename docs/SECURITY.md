# รายงานการตรวจสอบความปลอดภัยและความน่าเชื่อถือ
ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น (`nsokhkaen`)

| รายการ | รายละเอียด |
|---|---|
| วันที่ตรวจ | 4 พฤศจิกายน 2569 |
| เวอร์ชันโค้ดที่ตรวจ | git `8b3f42f` ขึ้นไป (สาขา `main`) |
| ขอบเขต | โค้ดทั้งระบบ (ระบบกลาง, ปฏิทิน/แผนการปฏิบัติงาน, วัสดุและครุภัณฑ์, ศูนย์ดิจิทัลชุมชน, สลิปเงินเดือน, พิกัด EA) + การตั้งค่าเว็บเซิร์ฟเวอร์ (`.htaccess`) |
| มาตรฐานอ้างอิง | OWASP Top 10 : 2021 |
| เครื่องมือ | OWASP ZAP 2.17.0 (Web Scan — Baseline และ Full/Active Scan แบบ login), Semgrep 1.183.0 (SAST — กฎ PHP 260 กฎ), การตรวจโค้ดด้วยมือ, ทดสอบด้วย Playwright/curl |
| สภาพแวดล้อมทดสอบ | Apache 2.4.58 + PHP 8.3 + MariaDB 10.11 (สำเนาทดสอบ ไม่ใช่เครื่องจริง) — ผลส่วน "เซิร์ฟเวอร์จริง" ดูหัวข้อ 4 และ 7 |

---

## 1. สรุปผล

| ผลการตรวจ | จำนวน |
|---|---|
| ช่องโหว่ระดับสูง/วิกฤตในโค้ดของระบบ (หลังแก้ไข) | **0** |
| ช่องโหว่ที่พบและแก้ไขแล้วในรอบนี้ | 10 รายการ (หัวข้อ 6) — รวม XSS 2 รายการที่พบจาก ZAP Full Scan แบบ login |
| OWASP ZAP | Baseline: FAIL **0** · Full Scan แบบ login (3,419 URL, active scan 90 นาที): แจ้ง High 3 ประเภท — XSS **2 รายการเป็นช่องโหว่จริง แก้แล้ว**, SQL Injection 114 รายการ **พิสูจน์แล้วเป็น false positive** (หัวข้อ 3.3) |
| Semgrep — ผลที่เป็นช่องโหว่จริง | **0** จาก 51 รายการที่แจ้งเตือน (ตรวจยืนยันทุกรายการ หัวข้อ 3.2) |
| ความเสี่ยงคงเหลือที่ยอมรับได้ (มีมาตรการลดความเสี่ยง) | 3 รายการ (หัวข้อ 8) |
| สิ่งที่ต้องดำเนินการที่เครื่องเซิร์ฟเวอร์จริง | 8 รายการ (หัวข้อ 7) — **วิกฤต: PHP 8.0.30 บนเครื่องจริงมีช่องโหว่ PHP2Shell (CVE-2026-109618 / CVE-2026-109619) ซึ่งถูกโจมตีจริงแล้ว และไม่มีแพตช์สำหรับ PHP 8.0 — ต้องอัปเกรด PHP เป็น 8.3.36 ขึ้นไปโดยเร็วที่สุด** · และปิด `php-cgi` ของ XAMPP (CVE-2024-4577) |

---

## 2. การตรวจตามมาตรฐาน OWASP Top 10 : 2021

| # | หมวด | มาตรการในระบบ | ผล |
|---|---|---|---|
| A01 | Broken Access Control | ทุกหน้า/ทุก API ตรวจสิทธิ์ก่อนทำงาน (`require_login`, `require_module` แยกบทบาทรายระบบ, `require_superadmin`, `require_module_api`) · ข้อมูลส่วนบุคคล (สลิป, สมุดบันทึก, กล่องจดหมาย, ไฟล์แนบ) ตรวจความเป็นเจ้าของทุกครั้ง · ไฟล์แนบ/ลายมือชื่อ/ใบยืม อยู่ในโฟลเดอร์ปิด เปิดผ่านสคริปต์ที่ตรวจสิทธิ์ · ทดสอบเรียกหน้าที่ต้อง login โดยไม่ login → ถูกส่งไปหน้าเข้าสู่ระบบทุกหน้า | ผ่าน |
| A02 | Cryptographic Failures | รหัสผ่าน/PIN เก็บแบบ `password_hash` (bcrypt) + rehash อัตโนมัติ · สมุดบันทึกเข้ารหัส AES-256-GCM กุญแจแยกรายผู้ใช้ (HMAC-SHA256) · CSRF token จาก `random_bytes(32)` · cookie `HttpOnly`, `SameSite=Lax`, `Secure` เมื่อเป็น HTTPS · ส่ง HSTS เมื่อใช้ HTTPS | ผ่าน — **ต้องเปิด HTTPS ที่เครื่องจริง** (หัวข้อ 7) |
| A03 | Injection (SQL / XSS / Command) | SQL ทั้งหมดใช้ PDO prepared statement (`ATTR_EMULATE_PREPARES = false`) — กรณีต่อ `IN (…)` ใช้ `intval` / placeholder · ชื่อคอลัมน์มาจากรายการคงที่ในโค้ด · แสดงผลผ่าน `h()` (htmlspecialchars) · ข้อความจัดรูปแบบ (Quill) กรอง HTML แบบ allowlist ฝั่งเซิร์ฟเวอร์ทั้งก่อนบันทึกและก่อนแสดง · **ไม่มีการเรียก** `eval / system / exec / shell_exec / passthru / popen / proc_open / unserialize / extract` และไม่มี `include` จากข้อมูลผู้ใช้ | ผ่าน |
| A04 | Insecure Design | ป้องกันเดารหัสผ่าน/PIN: ผิด 10 ครั้งใน 15 นาที (ต่อ IP และต่อชื่อผู้ใช้) ระงับชั่วคราว นับจากบันทึกการใช้งาน (ลบ cookie แล้วไม่ช่วย) + หน่วงเวลาตอบกลับ · การทำงานสำคัญยืนยันซ้ำ (ล้างข้อมูลระบบต้องใส่ PIN, ลบต้องยืนยัน) · ลบข้อมูลที่ถูกอ้างอิงไม่ได้ (โครงการ/รหัสงาน) | ผ่าน |
| A05 | Security Misconfiguration | `.htaccess` ปิดการแสดงรายการไฟล์ (`Options -Indexes`), ปิดไฟล์ตั้งค่า, โฟลเดอร์ `core/ database/ libs/ tools/ docs/ storage/` และ **ไฟล์ซ่อน/`.git`/เอกสาร/สำรอง/log** · ไม่แสดง error บนหน้าเว็บ (บันทึกลงไฟล์แทน) · ซ่อน `X-Powered-By` · Security headers: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy | ผ่าน — ค่าระดับเซิร์ฟเวอร์ดูหัวข้อ 7 |
| A06 | Vulnerable & Outdated Components | ไลบรารีฝั่งเบราว์เซอร์ระบุเวอร์ชันชัดเจน (Bootstrap 5.3.3, jQuery 3.7.1, Leaflet 1.9.4, flatpickr 4.6.13, Quill 2.0.3, Cropper 1.6.2) — ZAP Retire.js ไม่พบไลบรารีที่มีช่องโหว่ | โค้ดผ่าน — **เซิร์ฟเวอร์ไม่ผ่าน (วิกฤต): PHP 8.0.30 มีช่องโหว่ PHP2Shell และ EOL แล้ว (หัวข้อ 4.1), MariaDB 10.4 EOL** |
| A07 | Identification & Authentication Failures | `session_regenerate_id` เมื่อ login/logout · session strict mode, รับ session id จาก cookie เท่านั้น · หมดเวลาเมื่อไม่ใช้งาน 120 นาที · ข้อมูลผู้ใช้/สิทธิ์โหลดใหม่ทุก request (ปิดบัญชีมีผลทันที) · จำกัดการเดารหัสผ่าน (A04) | ผ่าน — ข้อเสนอ: เพิ่มความยาวรหัสผ่านขั้นต่ำจาก 6 เป็น 8 ตัว |
| A08 | Software & Data Integrity Failures | ไฟล์จาก CDN ทุกไฟล์มี **Subresource Integrity (SHA-384)** + `crossorigin` (คำนวณจากแพ็กเกจ npm รุ่นเดียวกัน และตรวจเทียบค่า SRI ทางการของ jQuery ตรงกัน) · ทุกฟอร์ม POST ตรวจ CSRF token (`hash_equals`) — API ตรวจ header `X-CSRF-Token` | ผ่าน — ยกเว้น Google Fonts (หัวข้อ 8) |
| A09 | Security Logging & Monitoring Failures | บันทึกการใช้งาน (`core_audit_log`) ทุกการกระทำสำคัญ ~50 ประเภท พร้อมผู้ใช้/IP/เวลา รวม login สำเร็จ/ไม่สำเร็จ/ถูกระงับ · หน้าสถิติรายบุคคล + ส่งออก CSV · log ข้อผิดพลาดของแอปพลิเคชันและ PHP (หัวข้อ 5) | ผ่าน |
| A10 | Server-Side Request Forgery | ฝั่งเซิร์ฟเวอร์**ไม่มี**การเรียก URL ภายนอก (ไม่มี `curl`, `file_get_contents(http…)`, `fsockopen`) · ลิงก์ที่ผู้ดูแลกรอกอนุญาตเฉพาะ `http(s)://` หรือ path ภายใน | ผ่าน (ไม่มีจุดเสี่ยง) |

---

## 3. ผลการตรวจสอบด้วยโปรแกรม (Web Scan / Code Scan)

### 3.1 OWASP ZAP 2.17.0 (Dynamic Application Security Testing)

| การสแกน | ขอบเขต | FAIL | WARN | PASS |
|---|---|---|---|---|
| Baseline (passive) — **ก่อนแก้ไข** | ไม่ login | 0 | 12 | 50 |
| Baseline (passive) — **หลังแก้ไข** | ไม่ login | 0 | 8 | 54 |
| Full Scan แบบ login เป็นผู้ดูแลระบบ (Automation Framework: spider 3,419 URL + active scan ทุกกฎ 90 นาที: SQLi, XSS, Path Traversal, Command/Code Injection, RFI, SSTI, XXE ฯลฯ) | ทุกระบบ | High: XSS (Persistent) 6, XSS (Reflected) 34, SQL Injection 114 → ตรวจยืนยันในหัวข้อ 3.3 | Medium/Low ตามตารางด้านล่าง | Path Traversal, Remote File Inclusion, OS Command Injection, Server Side Code/Template Injection, XXE, CRLF ฯลฯ **ไม่พบ** |
| สแกนซ้ำเฉพาะกฎ XSS (strength high / threshold low) หลังแก้ไข | หน้าที่เกี่ยวข้อง | ZAP ยังแจ้งจากข้อมูลทดสอบที่ ZAP บันทึกไว้เอง — ตรวจบริบทในหน้าเว็บและทดสอบในเบราว์เซอร์แล้ว **ไม่ทำงาน** (หัวข้อ 3.3) | | |

รายการ WARN ที่เหลือ (ไม่มีรายการใดเป็นช่องโหว่ที่ใช้โจมตีได้):

| รายการ ZAP | คำอธิบาย / การจัดการ |
|---|---|
| CSP: `unsafe-inline` | ระบบใช้ inline script — ยอมรับความเสี่ยง (หัวข้อ 8) |
| SRI Attribute Missing | เฉพาะ Google Fonts (สร้าง CSS ต่างกันตามเบราว์เซอร์ ใส่ SRI ไม่ได้) — หัวข้อ 8 |
| CSP Header Not Set | เฉพาะ `/`, `/robots.txt`, `/sitemap.xml` ของเว็บเซิร์ฟเวอร์ทดสอบ (นอกโฟลเดอร์ระบบ) |
| Hidden File Found `/server-status` | หน้าสถานะของ Apache บนเครื่องทดสอบ (Ubuntu) — เครื่องจริงต้องปิด/จำกัด (หัวข้อ 7) |
| Timestamp Disclosure | เลขเวอร์ชันไฟล์ `?v=…` สำหรับล้าง cache — ข้อมูลทั่วไป ไม่ใช่ความลับ |
| User Controllable HTML Attribute | ค่าค้นหาที่ผู้ใช้พิมพ์แสดงกลับในช่องค้นหา — ผ่าน `h()` แล้ว (Informational) |
| Authentication / Session Request Identified, Modern Web Application | ข้อมูลประกอบ (Informational) |

รายการที่แก้ไขแล้ว (WARN ก่อนแก้ → PASS หลังแก้): CSP Header Not Set, Missing Anti-clickjacking Header, X-Content-Type-Options Missing, Server Leaks Version (Server header), In Page Banner Information Leak, Cross-Domain JS (ลดความเสี่ยงด้วย SRI)

ไฟล์รายงานฉบับเต็ม: `docs/security/zap_before_baseline.html`, `zap_after_baseline.html`, `zap_full_auth_before_xss_fix.html`, `zap_xss_rescan_after_fix.html`, `sqli_verification.txt`, `semgrep_summary.txt`

> หมายเหตุ: การสแกนแบบ active ส่งข้อมูลโจมตีและกดปุ่มต่าง ๆ จริง (เช่น เพิ่ม/ปิดใช้งานข้อมูล) — ทำบน**สำเนาทดสอบ**เท่านั้น ห้ามรันกับเครื่องจริงที่มีข้อมูลใช้งาน

### 3.3 การตรวจยืนยันผลระดับ High จาก ZAP Full Scan
| ผล ZAP | การตรวจยืนยัน | สรุป |
|---|---|---|
| **XSS — ระบบวัสดุ (Stored, DOM-based)** | รายการวัสดุ/ใบเบิก/รายงาน/หน้าสแกน สร้าง HTML ด้วย JavaScript จากข้อมูล API โดยไม่ escape — เจ้าหน้าที่ใส่ HTML ใน "วัตถุประสงค์" ของใบเบิก แล้วสคริปต์ทำงานในเบราว์เซอร์ของผู้ดูแลที่เปิดรายการ (ยกระดับสิทธิ์ได้) · **ทดสอบจริงก่อนแก้: สคริปต์ทำงาน 4 หน้า / หลังแก้: ไม่ทำงาน** | **ช่องโหว่จริง — แก้แล้ว** (`escapeHtml()`/`jsStr()` ใน `assets/js/app.js`) |
| **XSS — ปุ่มยืนยันลบ (Stored)** | ตัวย่อโครงการ / ชื่อลิงก์ อยู่ในโค้ด JavaScript ของ `onsubmit="confirm('…')"` — `h()` กันเฉพาะ HTML ค่าที่มี `'` จึงหลุดออกจาก string ได้ (ผู้บันทึกต้องเป็นผู้ดูแล) | **ช่องโหว่จริง — แก้แล้ว** (`js_attr()` ใน `core/helpers.php` ใช้ 5 จุด) |
| XSS ที่ ZAP ยังแจ้งหลังแก้ | หลักฐานคือข้อความทดสอบที่ ZAP บันทึกไว้เอง (`;alert(1)`, `<script>…`) ปรากฏในหน้า: ตรวจ HTML แล้วอยู่ในรูป `&lt;script&gt;` หรืออยู่ใน string `confirm(&quot;…;alert(1)…&quot;)` · เปิดหน้าที่มีข้อมูลโจมตี ~50 รายการในเบราว์เซอร์จริง (ดัก `alert`) → **ไม่ถูกเรียกเลย** | false positive (ZAP ตรวจแบบข้อความตรงกัน) |
| SQL Injection 114 รายการ | เปิด MariaDB general log แล้วส่งการโจมตีทั้ง 114 รายการซ้ำ: คำสั่ง SQL 1,962 คำสั่ง **ไม่มี payload ในโครงสร้าง SQL เลย** — payload อยู่เฉพาะค่าที่ bind (Execute) เก็บเป็นข้อความธรรมดา | false positive (หน้าเว็บมี CSRF token/เวลาเปลี่ยนทุกครั้ง ทำให้การเปรียบเทียบหน้าเว็บของ ZAP คลาดเคลื่อน) — หลักฐาน `docs/security/sqli_verification.txt` |


### 3.2 Semgrep 1.183.0 (Static Application Security Testing)
กฎ PHP ของ semgrep-rules (php/lang: security, audit) + generic secrets — **260 กฎ, 191 ไฟล์** — แจ้งเตือน 51 รายการ ตรวจยืนยันด้วยมือทุกรายการ:

| กฎ | จำนวน | ผลการตรวจยืนยัน |
|---|---|---|
| tainted-sql-string / tainted-callable | 28 | **ไม่ใช่ช่องโหว่** — ค่าใน `IN (…)` ผ่าน `array_map('intval')` หรือเป็น `?` placeholder; ชื่อคอลัมน์มาจาก array คงที่ในโค้ด ไม่ใช่จากผู้ใช้ |
| echoed-request | 13 | **ไม่ใช่ช่องโหว่** — ผ่าน `h()` / `(int)` ทุกจุด; `scan.php` ใช้ `json_encode` (escape `</`) และเพิ่ม `JSON_HEX_*` แล้ว |
| weak-crypto (md5) | 5 | **ไม่ใช่ช่องโหว่** — ใช้ทำ key/ล้าง cache ไม่ได้ใช้กับรหัสผ่าน |
| unlink-use | 4 | **ไม่ใช่ช่องโหว่** — path มาจากฐานข้อมูล/ชื่อที่ระบบสุ่ม มีตรวจ `..` · เพิ่มกรองชื่อไฟล์รูปวัสดุแล้ว |
| openssl-decrypt-validate | 1 | **ไม่ใช่ช่องโหว่** — AES-GCM ตรวจ tag และคืน null เมื่อถอดรหัสไม่ได้ |

---

## 4. ช่องโหว่ระดับวิกฤตของ PHP ที่นำไปสู่การยึดเครื่อง (PHP → Shell)

### 4.1 PHP2Shell — CVE-2026-109618 และ CVE-2026-109619 (วิกฤต, ถูกโจมตีจริงแล้ว)
| รายการ | รายละเอียด |
|---|---|
| ลักษณะ | ช่องโหว่ในตัว PHP เอง 2 รายการ ให้ผู้โจมตีรันโค้ดบนเซิร์ฟเวอร์ได้จากระยะไกลโดย**ไม่ต้อง login** — CVE-2026-109618: RCE จากการจัดการหน่วยความจำไม่พอ (OOM) ขณะข้อมูล request ยังตั้งค่าไม่ครบ · CVE-2026-109619: RCE จากสถานะภายในของ request ที่ค้างหลังหน่วยความจำถูกคืนแล้ว |
| ความรุนแรง | CVSS v4.0 9.2 (PHP) · watchTowr ให้ 10.0 · CISA ใส่ใน Known Exploited Vulnerabilities (26 ต.ค. 2569) |
| รุ่นที่ได้รับผลกระทบ | PHP 5.4 – 8.5 ที่ให้บริการเว็บ |
| รุ่นที่แก้ไขแล้ว | **8.2.35, 8.3.36, 8.4.27, 8.5.12** (21 ต.ค. 2569) — PHP 8.0 / 8.1 หมดระยะสนับสนุน **ไม่มีแพตช์** |
| เครื่องจริงของระบบ | XAMPP **PHP 8.0.30 → ได้รับผลกระทบ และแก้ด้วยการตั้งค่า/โค้ดไม่ได้** ผู้ดูแล PHP ระบุว่าไม่มีมาตรการลดผลกระทบที่สมบูรณ์ ต้องอัปเกรดเท่านั้น |
| โค้ดของระบบ | ทดสอบบน PHP 8.3 แล้วทำงานได้ — อัปเกรดเป็น 8.3.36 ได้โดยไม่ต้องแก้โค้ด (ขั้นตอนหัวข้อ 7.2 ข้อ 1) |
| ระหว่างรออัปเกรด | ให้เครื่องเปิดบริการเฉพาะเครือข่ายภายใน (ไม่เปิดสู่อินเทอร์เน็ต/ไม่ทำ port forward) เพื่อลดผู้ที่เข้าถึงได้ — แต่ไม่ได้ปิดช่องโหว่ |

อ้างอิง: [Help Net Security](https://www.helpnetsecurity.com/2026/10/21/php2shell-php-vulnerabilities-patched-cve-2026-109618-cve-2026-109619/) · [Qualys](https://blog.qualys.com/vulnerabilities-threat-research/2026/10/20/php-announces-critical-security-release-for-two-vulnerabilities) · [Unit 42](https://unit42.paloaltonetworks.com/cve-2026-109618-cve-2026-109619/) · [The Hacker News](https://thehackernews.com/2026/10/php2shell-two-unpatched-critical-php.html) · [watchTowr](https://watchtowr.com/intelligence/php-cve-2026-109618-faq/)

### 4.2 CVE-2024-4577 (PHP-CGI Argument Injection บน Windows, CVSS 9.8)
- XAMPP for Windows ตั้งค่าเริ่มต้นเปิด `/php-cgi/php-cgi.exe` ไว้ (`ScriptAlias /php-cgi/` ใน `httpd-xampp.conf`) ผู้โจมตีส่งคำสั่ง PHP มาทาง URL แล้วรันบนเครื่องได้ทันที (ไม่ต้อง login)
- แก้ไขใน PHP 8.1.29 / 8.2.20 / 8.3.8 ขึ้นไป — PHP 8.0.30 ไม่มีแพตช์ (การอัปเกรดตาม 4.1 แก้ข้อนี้ด้วย)
- ระบบนี้**ไม่ได้ใช้** php-cgi (รันผ่าน Apache module) จึงปิดได้ทันทีโดยไม่กระทบระบบ — หัวข้อ 7.2 ข้อ 2

### 4.3 ระดับโค้ด: ช่องทางวาง/รันโค้ด PHP (web shell) ผ่านระบบ — **ไม่พบ**
| ช่องทาง | ผลการตรวจ |
|---|---|
| ฟังก์ชันรันคำสั่ง/โค้ด (`eval`, `system`, `exec`, `shell_exec`, `passthru`, `popen`, `proc_open`, `assert`, `create_function`, backtick) | ไม่มีในโค้ดของระบบ |
| `include/require` จากข้อมูลผู้ใช้ (LFI/RFI) | ไม่มี — include 3 จุดใช้ path ที่ระบบหาเองจาก `glob(modules/*/…)` |
| `unserialize` / `extract` / ตัวแปรแบบ `$$` | ไม่มี |
| อัปโหลดไฟล์ (รูป, ลายมือชื่อ, ใบยืม, ไฟล์แนบข้อความ) | ตรวจชนิดจากเนื้อไฟล์ (`finfo`/`getimagesize`) หรือ allowlist นามสกุล · **ตั้งชื่อไฟล์ใหม่แบบสุ่ม** นามสกุลมาจากระบบ · จำกัดขนาด |
| รัน PHP ในโฟลเดอร์อัปโหลด | `uploads/.htaccess` ห้ามรัน `.php .phtml .php* .phar .pl .py .cgi .sh` + `php_flag engine off` · **ทดสอบจริง:** วาง `x.php`, `x.phtml` → 403, `x.php.jpg` → ส่งเป็นข้อความ ไม่ถูกรัน |

---

## 5. ความน่าเชื่อถือในการใช้งาน

| รายการ | การดำเนินการในระบบ | อ้างอิง |
|---|---|---|
| การเชื่อมต่อฐานข้อมูลแบบ Pooling | ใช้การเชื่อมต่อเดียวต่อ request (ใช้ซ้ำทั้ง request) และรองรับ **connection pooling ของ PDO (persistent connection)** ตั้งค่า `DB_PERSISTENT = true` ใน `config.local.php` — ก่อนคืน connection เข้า pool ระบบ rollback ธุรกรรมที่ค้างเสมอ · ค่าเริ่มต้นปิดไว้ เพราะ Apache บน Windows มีได้ถึง 150 thread (1 connection ต่อ thread) ขณะที่ MariaDB รับได้ 151 connection — ถ้าเปิดให้ตั้ง `max_connections = 300` ใน `my.ini` ก่อน | `core/db.php`, `config.php` |
| Close Connection หลังใช้งานทุกครั้ง | `db_close()` ลงทะเบียนด้วย `register_shutdown_function` ปิดการเชื่อมต่อเมื่อจบทุก request (รวมกรณีเกิดข้อผิดพลาด) และ rollback ธุรกรรมที่ค้าง | `core/db.php` |
| การจัดการ Session | cookie `HttpOnly` + `SameSite=Lax` + `Secure` (HTTPS) · strict mode · รับ id จาก cookie เท่านั้น · เปลี่ยน session id เมื่อ login/logout · หมดเวลาเมื่อไม่ใช้งาน `SESSION_IDLE_MINUTES` (120 นาที) แล้วแจ้งในหน้าเข้าสู่ระบบ · session เก็บเฉพาะ id ผู้ใช้ สิทธิ์โหลดจากฐานข้อมูลทุก request | `core/auth.php` |
| ป้องกัน Access เข้าตรงยัง Folder | `.htaccess` ระดับระบบ + รายโฟลเดอร์ — **ทดสอบจริงผ่าน Apache:** `config*.php`, `core/ database/ libs/ tools/ docs/ storage/ uploads/` → 403 · `.git/` → 404 · `.md .sql .log` → 403 · ไม่แสดงรายการไฟล์ทุกโฟลเดอร์ | `.htaccess`, `*/.htaccess` |
| Log ของ Application Server | Apache access log / error log (XAMPP: `C:\xampp\apache\logs\access.log`, `error.log`) และ MariaDB error log (`C:\xampp\mysql\data\*.err`) — ข้อเสนอการเก็บรักษาในหัวข้อ 7 | การตั้งค่าเซิร์ฟเวอร์ |
| Log ของ Application เอง | (1) **บันทึกการใช้งาน** `core_audit_log`: ผู้ใช้, ระบบ, การกระทำ, รายละเอียด, IP, เวลา — ดูได้ที่ ผู้ดูแลระบบ → บันทึกการใช้งาน (สถิติรายบุคคล / log / ส่งออก CSV) (2) `storage/logs/app_error.log`: ข้อผิดพลาดที่ไม่คาดคิด พร้อมรหัสอ้างอิงที่แสดงให้ผู้ใช้ URL ผู้ใช้ และ IP (3) `storage/logs/php_error.log`: PHP warning/error ทั้งหมด — โฟลเดอร์ `storage/` เปิดจากเว็บไม่ได้ | `core/helpers.php`, `core/security.php` |

---

## 6. รายการที่แก้ไขในรอบการตรวจนี้

| # | ปัญหาที่พบ | ระดับ | การแก้ไข |
|---|---|---|---|
| 1 | เปิดไฟล์ `.git/config` และไฟล์ใน `.git/` จากเว็บได้ (เครื่องจริงติดตั้งด้วย git) → ดาวน์โหลดซอร์สโค้ดได้ | สูง | ปิด `.git` และไฟล์ซ่อนทั้งหมด (`RedirectMatch 404`) |
| 2 | ไม่มี Security headers (CSP, X-Frame-Options, nosniff, Referrer-Policy) | ปานกลาง | ส่ง header จาก `core/security.php` และ `.htaccess` (หน้า .html) — ทดสอบ 33 หน้าไม่มี CSP violation |
| 3 | ป้องกันเดารหัสผ่าน/PIN นับใน session (ลบ cookie แล้วเดาต่อได้) | ปานกลาง | นับตาม IP และชื่อผู้ใช้จากบันทึกการใช้งาน ระงับ 15 นาที — ทดสอบด้วย cookie ใหม่ทุกครั้ง ถูกระงับตามกำหนด |
| 4 | ไฟล์จาก CDN ไม่มี SRI | ปานกลาง | SRI SHA-384 ทุกไฟล์ (36 แท็ก + Quill) |
| 5 | session ไม่มีวันหมดอายุเมื่อไม่ใช้งาน / ไม่เปิด strict mode | ปานกลาง | idle timeout 120 นาที + strict mode + only cookies |
| 6 | แสดงรายละเอียดข้อผิดพลาดบนหน้าเว็บได้ถ้าเซิร์ฟเวอร์ตั้ง `display_errors=On` (ค่าเริ่มต้น XAMPP) | ต่ำ–ปานกลาง | บังคับไม่แสดง บันทึกลง `storage/logs/` + หน้าแจ้งข้อผิดพลาดพร้อมรหัสอ้างอิง |
| 7 | อ่านไฟล์ `README.md`, `CLAUDE.md`, `.gitignore` จากเว็บได้ | ต่ำ | ปิด `.md .sql .log .bak .py .sh .ini` และไฟล์ซ่อน |
| 8 | **Stored XSS (DOM) ในระบบวัสดุ** — เจ้าหน้าที่ฝังสคริปต์ในใบเบิก/ข้อมูลวัสดุ ทำงานในเบราว์เซอร์ผู้ดูแล | **สูง** | escape ทุกค่าที่สร้าง HTML ใน JavaScript (`escapeHtml`, `jsStr`) — ทดสอบก่อน/หลังแก้ |
| 9 | Stored XSS ในปุ่มยืนยันลบ (โครงการ/ลิงก์/แผน/ใบเบิก) | ปานกลาง | `js_attr()` สำหรับค่าใน JavaScript ภายใน attribute |
| 10 | ลิงก์แบบ path ภายในรับอักขระ HTML ได้ (แสดงผลถูก escape แล้ว) | ต่ำ | จำกัดอักขระ path ภายใน `[A-Za-z0-9/_.~%?=&#+-]` |

---

## 7. ตารางวิเคราะห์ Service Port และสิ่งที่ต้องทำที่เซิร์ฟเวอร์จริง (XAMPP for Windows)

### 7.1 Service Port

| Port | Protocol | บริการ (โปรแกรม) | ระบบใช้หรือไม่ | การเปิดใช้ที่แนะนำ |
|---|---|---|---|---|
| 80 | TCP / HTTP | Apache — เว็บระบบ | ใช้ | เปิดเฉพาะเครือข่ายภายในสำนักงาน · เปลี่ยนเส้นทางไป 443 เมื่อเปิด HTTPS |
| 443 | TCP / HTTPS | Apache (mod_ssl) | **ควรใช้** | เปิดเฉพาะเครือข่ายภายใน · ใช้ใบรับรองของหน่วยงาน (แทน self-signed ของ XAMPP) |
| 3306 | TCP / MySQL | MariaDB | ใช้ — **ภายในเครื่องเท่านั้น** | `bind-address = 127.0.0.1` ใน `my.ini` + Windows Firewall ปิดขาเข้า 3306 |
| 21, 14147 | TCP | FileZilla FTP Server (XAMPP) | ไม่ใช้ | ไม่ติดตั้ง/ไม่เปิด service |
| 25, 79, 105, 106, 110, 143, 2224 | TCP | Mercury Mail (XAMPP) | ไม่ใช้ | ไม่ติดตั้ง/ไม่เปิด service |
| 8080, 8005, 8009 | TCP | Tomcat (XAMPP) | ไม่ใช้ | ไม่ติดตั้ง/ไม่เปิด service |
| 3389 / 445 | TCP | Remote Desktop / SMB ของ Windows | ไม่เกี่ยวกับระบบ | จำกัดเฉพาะเครื่องผู้ดูแล |
| ขาออก 443 (จากเบราว์เซอร์ผู้ใช้) | HTTPS | cdn.jsdelivr.net, code.jquery.com, fonts.googleapis.com / fonts.gstatic.com, \*.tile.openstreetmap.org, server.arcgisonline.com, app.powerbi.com | ใช้ (ฝั่งผู้ใช้) | ตัวเซิร์ฟเวอร์**ไม่ต้อง**ออกอินเทอร์เน็ต |

ตรวจ port ที่เปิดจริงบนเครื่อง: `netstat -ano | findstr LISTENING`

### 7.2 สิ่งที่ต้องดำเนินการที่เครื่องเซิร์ฟเวอร์ (โค้ดแก้ให้ไม่ได้)

1. **อัปเกรด PHP เป็น 8.3.36 ขึ้นไป (PHP2Shell) — วิกฤต ทำโดยเร็วที่สุด**
   XAMPP ยังไม่มีชุดติดตั้งที่มี PHP รุ่นแก้ไข จึงเปลี่ยนเฉพาะโฟลเดอร์ PHP:
   1. สำรองฐานข้อมูล แล้วหยุด Apache ใน XAMPP Control Panel
   2. ดาวน์โหลด **PHP 8.3.36 (หรือใหม่กว่า) VS16 x64 Thread Safe (zip)** จาก `https://windows.php.net/download/` (ต้องเป็น *Thread Safe* จึงมีไฟล์ `php8apache2_4.dll` สำหรับ Apache)
   3. เปลี่ยนชื่อ `C:\xampp\php` เป็น `C:\xampp\php_8030_old` แล้วแตก zip ไปที่ `C:\xampp\php`
   4. คัดลอก `php.ini-production` เป็น `php.ini` แล้วตั้งค่า: `extension_dir = "C:\xampp\php\ext"`, เปิด `extension=pdo_mysql`, `mysqli`, `mbstring`, `gd`, `fileinfo`, `openssl`, `zip`, `curl`, `intl` · `upload_max_filesize = 10M`, `post_max_size = 64M`, `date.timezone = Asia/Bangkok`, `expose_php = Off`, `display_errors = Off`
   5. คัดลอกโฟลเดอร์ที่ XAMPP ใช้จากของเดิม (`C:\xampp\php_8030_old\pear`, `extras`) มาไว้ที่โฟลเดอร์ใหม่ถ้าต้องการใช้ phpMyAdmin/PEAR
   6. ตรวจ `C:\xampp\apache\conf\extra\httpd-xampp.conf`: `LoadModule php_module "C:/xampp/php/php8apache2_4.dll"` และ `PHPIniDir "C:/xampp/php"` แล้วเริ่ม Apache
   7. ตรวจ: `C:\xampp\php\php.exe -v` ต้องได้ 8.3.36 ขึ้นไป · เปิดระบบ login ทดสอบทุกระบบ · ผู้ดูแลระบบ → ตั้งค่าระบบ → "ข้อมูลตรวจสอบการติดตั้ง" แสดงเวอร์ชัน PHP
   (ทางเลือกระยะยาว: ย้ายไปใช้ชุดเซิร์ฟเวอร์ที่ยังได้รับอัปเดต)
2. **ปิด php-cgi (CVE-2024-4577)**
   เปิด `C:\xampp\apache\conf\extra\httpd-xampp.conf` ใส่ `#` หน้าบรรทัด `ScriptAlias /php-cgi/ "C:/xampp/php/"` และบล็อก `<Directory "C:/xampp/php">…</Directory>` แล้วรีสตาร์ต Apache · ตรวจ: เปิด `http://<เครื่อง>/php-cgi/php-cgi.exe` ต้องได้ 403/404
4. **เปิด HTTPS** (XAMPP มี mod_ssl) และเปลี่ยนเส้นทาง HTTP → HTTPS — cookie จะได้ `Secure` และ HSTS อัตโนมัติ
5. **ซ่อนเวอร์ชันเซิร์ฟเวอร์** ใน `httpd.conf`: `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off` · `php.ini`: `expose_php = Off`, `display_errors = Off`
6. **จำกัด phpMyAdmin และ server-status** ให้เข้าได้เฉพาะเครื่อง (`Require local`) และไม่เปิด `httpd-info.conf`
7. **MariaDB**: `bind-address=127.0.0.1`, ตั้งรหัสผ่าน root และสร้างผู้ใช้ฐานข้อมูลเฉพาะระบบ (สิทธิ์เฉพาะฐาน `nsokhkaen`) ใส่ใน `config.local.php`
8. **Log ของเซิร์ฟเวอร์**: เก็บ `apache\logs` และ `storage\logs` อย่างน้อย 90 วัน (ตามแนวปฏิบัติ พ.ร.บ.คอมพิวเตอร์ เก็บข้อมูลจราจร ≥ 90 วัน) — ตั้ง Task Scheduler หมุนไฟล์/สำรองรายเดือน · ทำ Windows Service ให้ Apache/MySQL ปิดเปิดอย่างถูกวิธี (ป้องกันฐานข้อมูลเสียหายเหมือนเหตุการณ์ 30 ต.ค. 2569)

---

## 8. ความเสี่ยงคงเหลือที่ยอมรับได้

| รายการ | เหตุผล | มาตรการลดความเสี่ยง |
|---|---|---|
| CSP อนุญาต `'unsafe-inline'` | หน้าเว็บใช้ inline script/handler จำนวนมาก การเลิกใช้ต้องปรับโครงสร้างทุกหน้า | แสดงผลผ่าน `h()` ทุกจุด, ตัวกรอง HTML แบบ allowlist, CSP จำกัดแหล่งไฟล์/ฟอร์ม/เฟรม/`object-src 'none'`/`base-uri 'self'` |
| Google Fonts ไม่มี SRI | Google สร้าง CSS ต่างกันตามเบราว์เซอร์ จึงใส่ SRI ไม่ได้ | เป็น CSS/ฟอนต์เท่านั้น (ไม่ใช่สคริปต์) · CSP จำกัดโดเมน · (ทางเลือก: เก็บฟอนต์ไว้ในระบบเอง) |
| ความยาวรหัสผ่านขั้นต่ำ 6 ตัวอักษร | ผู้ใช้ส่วนใหญ่ login ผ่านรหัสจากส่วนกลาง | จำกัดการเดารหัสผ่าน 10 ครั้ง/15 นาที · ข้อเสนอ: ปรับ `PASSWORD_MIN_LENGTH` เป็น 8 |

---

## 9. วิธีตรวจซ้ำ
- **Web Scan:** `docker run --rm -t zaproxy/zap-stable zap-baseline.py -t https://<เครื่อง>/nsokhkaen/` (ผล FAIL ต้องเป็น 0)
- **Code Scan:** `semgrep scan --config <semgrep-rules>/php/lang --exclude libs .`
- **Folder access:** `curl -I https://<เครื่อง>/nsokhkaen/.git/config` (ต้อง 404) · `…/config.local.php`, `…/storage/`, `…/database/schema.sql` (ต้อง 403)
- **Headers:** `curl -I https://<เครื่อง>/nsokhkaen/index.php` ต้องมี `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`
