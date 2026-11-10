<?php
/** ดาวน์โหลด "งานของฉัน" เป็นไฟล์ .ics (ปุ่ม "ส่งออกไปยัง Google Calendar" ในมุมมองเฉพาะงานของฉัน) */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ical_lib.php';
$me = require_module(CAL_MODULE);
cal_ical_send(cal_ical_build(cal_ical_events((int)$me['id']), 'งานของฉัน — ปฏิทินการปฏิบัติงาน'));
