<?php
/** (เดิม) โครงการที่รับผิดชอบ — รวมอยู่ในหน้าโครงการ (projects.php) แล้ว */
require_once __DIR__ . '/bootstrap.php';
require_module(EA_MODULE, 'viewer');
redirect('projects.php' . (isset($_GET['edit']) ? '?edit=' . (int)$_GET['edit'] : ''));
