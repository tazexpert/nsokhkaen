<?php
/**
 * บุคลากรทั้งหมด (ผู้ดูแลระบบกลาง): ชื่อผู้ใช้ ชื่อ-สกุล ตำแหน่ง เบอร์โทรศัพท์ ชื่อเล่น
 * เฉพาะบัญชีที่เปิดใช้งาน · เรียงตามชื่อผู้ใช้ (ตัวเลขก่อน) · ค้นหา / พิมพ์ได้
 */
require_once __DIR__ . '/../core/bootstrap.php';
require_superadmin();
$q = trim($_GET['q'] ?? '');
$all = !empty($_GET['all']);       // รวมบัญชีที่ชื่อผู้ใช้ไม่ใช่ตัวเลข (ผู้ดูแลจากภายนอก)
$where = ['u.is_active = 1'];
$params = [];
if (!$all) {
    $where[] = "u.username REGEXP '^[0-9]+$'";
}
if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.position LIKE ? OR u.phone LIKE ? OR u.nickname LIKE ?)';
    array_push($params, ...array_fill(0, 5, "%$q%"));
}
$st = db()->prepare('SELECT u.username, u.full_name, u.position, u.phone, u.nickname FROM core_users u WHERE ' . implode(' AND ', $where)
    . " ORDER BY u.username IS NULL, u.username REGEXP '^[0-9]' DESC,
               CASE WHEN u.username REGEXP '^[0-9]+$' THEN CAST(u.username AS UNSIGNED) END, u.username, u.full_name");
$st->execute($params);
$staff = $st->fetchAll();

$pageTitle = 'บุคลากรทั้งหมด';
$active = 'users';
$pageActions = '<a href="' . h(url('admin/users.php')) . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> ผู้ใช้งานและสิทธิ์</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="card">
  <div class="card-header no-print">
    <form class="row g-2 align-items-center">
      <div class="col-md-5"><input name="q" class="form-control" placeholder="ค้นหาชื่อ / ชื่อผู้ใช้ / ตำแหน่ง / เบอร์โทร / ชื่อเล่น" value="<?= h($q) ?>"></div>
      <div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" <?= $all ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label" for="all">รวมบัญชีที่ไม่ใช่เจ้าหน้าที่ (ชื่อผู้ใช้ไม่ใช่ตัวเลข)</label></div></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <div class="col-auto ms-auto small text-muted"><?= count($staff) ?> คน</div>
    </form>
  </div>
  <h2 class="h5 text-center d-none d-print-block my-3">รายชื่อบุคลากร <?= h(ORG_NAME) ?></h2>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 staff-table">
      <thead><tr><th class="text-center" style="width:56px">ลำดับ</th><th>ชื่อผู้ใช้</th><th>ชื่อ-สกุล</th><th>ตำแหน่ง</th><th>เบอร์โทรศัพท์</th><th>ชื่อเล่น</th></tr></thead>
      <tbody>
      <?php foreach ($staff as $i => $s): ?>
        <tr>
          <td class="text-center text-muted"><?= $i + 1 ?></td>
          <td class="font-monospace"><?= h($s['username'] ?: '-') ?></td>
          <td class="fw-semibold"><?= h(preg_replace('/\s+/u', ' ', $s['full_name'])) ?></td>
          <td><?= h($s['position'] ?: '-') ?></td>
          <td class="text-nowrap"><?= $s['phone'] ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $s['phone'])) . '" class="text-decoration-none">' . h($s['phone']) . '</a>' : '<span class="text-muted">-</span>' ?></td>
          <td><?= $s['nickname'] ? h($s['nickname']) : '<span class="text-muted">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$staff): ?><tr><td colspan="6" class="text-center text-muted py-5">ไม่พบข้อมูล</td></tr><?php endif; ?>
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
