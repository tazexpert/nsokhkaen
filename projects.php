<?php
/** (เดิม) โครงการที่รับผิดชอบ — ย้ายไปอยู่ในระบบบริหารโครงการ (modules/ea/manage_projects.php) */
require_once __DIR__ . '/core/bootstrap.php';
redirect(module_url('ea', 'manage_projects.php' . (isset($_GET['edit']) ? '?edit=' . (int)$_GET['edit'] : '')));
