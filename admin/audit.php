<?php
/** บันทึกการใช้งาน (ผู้ดูแลระบบกลาง) */
require_once __DIR__ . '/../core/bootstrap.php';
require_superadmin();

$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE (a.action LIKE ? OR a.detail LIKE ? OR u.full_name LIKE ? OR u.username LIKE ? OR a.ip LIKE ?)';
    $params = array_fill(0, 5, "%$q%");
}
$st = db()->prepare("SELECT a.*, u.full_name, u.username FROM core_audit_log a LEFT JOIN core_users u ON u.id = a.user_id
                     $where ORDER BY a.id DESC LIMIT 300");
$st->execute($params);
$rows = $st->fetchAll();
$labels = [
    'login' => 'เข้าสู่ระบบ', 'login_pin' => 'เข้าใช้ด้วย PIN', 'logout' => 'ออกจากระบบ',
    'login_failed' => 'เข้าสู่ระบบไม่สำเร็จ', 'login_pin_failed' => 'PIN ไม่ถูกต้อง',
    'user_create' => 'เพิ่มผู้ใช้', 'user_update' => 'แก้ไขผู้ใช้/สิทธิ์', 'user_delete' => 'ลบผู้ใช้',
    'user_toggle_active' => 'เปิด/ปิดบัญชี', 'change_password' => 'เปลี่ยนรหัสผ่าน', 'set_pin' => 'ตั้ง PIN',
    'remove_pin' => 'ลบ PIN', 'settings_update' => 'แก้ไขค่าระบบ',
];

$pageTitle = 'บันทึกการใช้งาน';
$active = 'audit';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="card">
  <div class="card-header">
    <form class="row g-2"><div class="col-md-5"><input name="q" class="form-control" placeholder="ค้นหาผู้ใช้ / การกระทำ / IP" value="<?= h($q) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <div class="col-auto ms-auto small text-muted align-self-center">แสดง 300 รายการล่าสุด</div></form>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>ระบบ</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= str_contains($r['action'], 'failed') ? 'table-warning' : '' ?>">
          <td class="text-nowrap small"><?= h(thai_date($r['created_at'])) ?> <?= h(substr($r['created_at'], 11, 5)) ?></td>
          <td><?= h($r['full_name'] ?? '-') ?></td>
          <td class="small"><?= h($r['module_key'] === 'core' ? 'ระบบกลาง' : (module_def($r['module_key'])['short'] ?? $r['module_key'])) ?></td>
          <td><?= h($labels[$r['action']] ?? $r['action']) ?></td>
          <td class="small text-muted"><?= h($r['detail']) ?></td>
          <td class="small text-muted"><?= h($r['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
