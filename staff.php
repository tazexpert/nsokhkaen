<?php
/**
 * บุคลากรสถิติขอนแก่น (ทุกคนที่ login เห็นได้ — เมนูบัญชีผู้ใช้ ใต้ "บัญชีของฉัน"): ชื่อ-สกุล ชื่อเล่น ตำแหน่ง เบอร์โทรศัพท์
 * เฉพาะเจ้าหน้าที่ (ชื่อผู้ใช้เป็นตัวเลข) ที่เปิดใช้งาน · เรียงตามตำแหน่ง (staff_sort_key) แล้วตามชื่อผู้ใช้ · ค้นหา / พิมพ์ได้
 */
require_once __DIR__ . '/core/bootstrap.php';
require_login();
$q = trim($_GET['q'] ?? '');
$where = ['u.is_active = 1', "u.username REGEXP '^[0-9]+$'"];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.position LIKE ? OR u.phone LIKE ? OR u.nickname LIKE ?)';
    array_push($params, ...array_fill(0, 4, "%$q%"));
}
$st = db()->prepare('SELECT u.username, u.full_name, u.position, u.phone, u.nickname FROM core_users u WHERE ' . implode(' AND ', $where));
$st->execute($params);
$staff = array_values(sort_staff($st->fetchAll()));     // เรียงตามลำดับตำแหน่ง แล้วตามชื่อผู้ใช้ (ไม่แสดงชื่อผู้ใช้)

$pageTitle = 'บุคลากรสถิติขอนแก่น';
$active = 'staff';
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="card">
  <div class="card-header no-print">
    <form class="row g-2 align-items-center">
      <div class="col-md-5"><input name="q" class="form-control" placeholder="ค้นหาชื่อ / ตำแหน่ง / เบอร์โทร / ชื่อเล่น" value="<?= h($q) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <div class="col-auto ms-auto small text-muted"><?= count($staff) ?> คน</div>
    </form>
  </div>
  <h2 class="h5 text-center d-none d-print-block my-3">บุคลากร<?= h(ORG_NAME) ?></h2>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 staff-table">
      <thead><tr><th class="text-center" style="width:56px">ลำดับ</th><th>ชื่อ-สกุล</th><th>ชื่อเล่น</th><th>ตำแหน่ง</th><th>เบอร์โทรศัพท์</th></tr></thead>
      <tbody>
      <?php foreach ($staff as $i => $s): ?>
        <tr>
          <td class="text-center text-muted"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= h(preg_replace('/\s+/u', ' ', $s['full_name'])) ?></td>
          <td><?= $s['nickname'] ? h($s['nickname']) : '<span class="text-muted">-</span>' ?></td>
          <td><?= h($s['position'] ?: '-') ?></td>
          <td class="text-nowrap"><?= $s['phone'] ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $s['phone'])) . '" class="text-decoration-none">' . h($s['phone']) . '</a>' : '<span class="text-muted">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$staff): ?><tr><td colspan="5" class="text-center text-muted py-5">ไม่พบข้อมูล</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<style>
  @media print {
    .staff-table { font-size: 12pt; }
    .staff-table a { color: inherit; }
    .card { box-shadow: none !important; border: 0; }
  }
</style>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
