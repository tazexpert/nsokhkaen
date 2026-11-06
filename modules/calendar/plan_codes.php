<?php
/** (เดิม) รหัสงานในแผน — ย้ายไปเป็น "โครงการที่รับผิดชอบ" (ระบบบริหารโครงการ) */
require_once __DIR__ . '/bootstrap.php';
redirect(module_url('ea', 'projects.php'));
