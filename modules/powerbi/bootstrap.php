<?php
/** จุดเริ่มต้นของระบบพิกัด EA ตัวอย่างโครงการ (powerbi — แสดง Power BI ใน iframe) */
require_once __DIR__ . '/../../core/bootstrap.php';

const PBI_MODULE = 'powerbi';
/** ลิงก์เริ่มต้น (Publish to web / view?r=...) — แก้ได้ที่หน้าตั้งค่าแดชบอร์ด (เก็บใน core_settings) */
const PBI_DEFAULT_URL = 'https://app.powerbi.com/view?r=eyJrIjoiMjkyODliM2MtN2VmMy00YWFmLTlmOTEtMTI5NTM1OTcwZjc0IiwidCI6ImY2NGQzMTgzLTc2OTEtNGZjYi1hNWVmLTM5ZWJjMDgyYmZmOSIsImMiOjEwfQ%3D%3D';
const PBI_DEFAULT_TITLE = 'พิกัด EA ตัวอย่างโครงการ';

function pbi_url(): string
{
    return setting('powerbi_url') ?: PBI_DEFAULT_URL;
}

function pbi_title(): string
{
    return setting('powerbi_title') ?: PBI_DEFAULT_TITLE;
}

/** ลิงก์ที่ใช้ใน iframe ได้: https:// เท่านั้น */
function pbi_valid_url(string $url): bool
{
    return (bool)preg_match('#^https://[^\s"<>]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL);
}
