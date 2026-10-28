<?php
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'inspector');
$pdo = db();

// ลบศูนย์
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    check_csrf();
    dcc_require('admin');              // ลบข้อมูลได้เฉพาะผู้ดูแลระบบศูนย์ฯ
    $id = int_param('id');
    $files = [];
    $st = $pdo->prepare('SELECT cover_image FROM dcc_centers WHERE id = ?');
    $st->execute([$id]);
    $files[] = $st->fetchColumn();
    foreach ([
        'SELECT file FROM dcc_center_images WHERE center_id = ?',
        'SELECT photo FROM dcc_center_staff WHERE center_id = ?',
        'SELECT cover_image FROM dcc_activities WHERE center_id = ?',
        'SELECT ai.file FROM dcc_activity_images ai JOIN dcc_activities a ON a.id = ai.activity_id WHERE a.center_id = ?',
    ] as $sql) {
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        $files = array_merge($files, $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $pdo->prepare('DELETE FROM dcc_centers WHERE id = ?')->execute([$id]); // ลบตารางลูกด้วย ON DELETE CASCADE
    array_map('delete_upload', array_filter($files));
    flash('ลบศูนย์เรียบร้อยแล้ว');
    redirect('centers.php');
}

$q = trim($_GET['q'] ?? '');
$district = trim($_GET['district'] ?? '');
$noLoc = !empty($_GET['nolocation']);
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.code LIKE ? OR c.subdistrict LIKE ? OR c.address LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($district !== '') {
    $where[] = 'c.district = ?';
    $params[] = $district;
}
if ($noLoc) {
    $where[] = '(c.lat IS NULL OR c.lng IS NULL)';
}
$sql = "SELECT c.*,
          (SELECT COUNT(*) FROM dcc_center_staff s WHERE s.center_id = c.id) AS staff_count,
          (SELECT COUNT(*) FROM dcc_center_images i WHERE i.center_id = c.id) AS image_count,
          (SELECT COUNT(*) FROM dcc_activities a WHERE a.center_id = c.id) AS activity_count
        FROM dcc_centers c" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.district, c.name';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'ศูนย์ดิจิทัลชุมชน';
$active = 'centers';
require __DIR__ . '/header.php';
?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center">
      <div class="col-md-5"><input name="q" class="form-control" placeholder="ค้นหารหัส / ชื่อศูนย์ / ตำบล / ที่อยู่" value="<?= h($q) ?>"></div>
      <div class="col-md-3">
        <select name="district" class="form-select">
          <option value="">ทุกอำเภอ</option>
          <?php foreach (KK_DISTRICTS as $d): ?><option <?= $d === $district ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> ค้นหา</button></div>
      <?php if (dcc_can('editor')): ?>
      <div class="col-auto ms-auto"><a href="center_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มศูนย์ใหม่</a></div>
      <?php endif; ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th style="width:72px"></th><th>ชื่อศูนย์</th><th>อำเภอ</th><th class="text-center">พิกัด</th>
        <th class="text-center">ผู้ดูแล</th><th class="text-center">ภาพ</th><th class="text-center">กิจกรรม</th><th class="text-end">จัดการ</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['is_active'] ? '' : 'table-secondary' ?>">
          <td><?php if ($r['cover_image']): ?><img src="<?= h(upload_url($r['cover_image'])) ?>" class="thumb" alt=""><?php else: ?><span class="thumb thumb-empty"><i class="bi bi-image"></i></span><?php endif; ?></td>
          <td><a href="<?= dcc_can('editor') ? 'center_form.php?id=' . (int)$r['id'] : 'public/center.html?id=' . (int)$r['id'] . '" target="_blank' ?>" class="fw-semibold text-decoration-none"><?= h($r['name']) ?></a>
            <?php if (!$r['is_active']): ?><span class="badge text-bg-secondary">ซ่อน</span><?php endif; ?>
            <div class="small text-muted"><?= $r['code'] ? '<span class="badge text-bg-light border">' . h($r['code']) . '</span> ' : '' ?><?= h(trim('ต.' . $r['subdistrict'] . ' ' . $r['center_type'])) ?></div></td>
          <td><?= h($r['district']) ?></td>
          <td class="text-center"><?= $r['lat'] !== null && $r['lng'] !== null ? '<i class="bi bi-geo-alt-fill text-success" title="' . h($r['lat'] . ', ' . $r['lng']) . '"></i>' : '<i class="bi bi-geo-alt text-danger" title="ยังไม่ระบุพิกัด"></i>' ?></td>
          <td class="text-center"><?= (int)$r['staff_count'] ?></td>
          <td class="text-center"><?= (int)$r['image_count'] ?></td>
          <td class="text-center"><a href="activities.php?center_id=<?= (int)$r['id'] ?>"><?= (int)$r['activity_count'] ?></a></td>
          <td class="text-end text-nowrap">
            <a href="public/center.html?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-light" title="ดูหน้าเว็บ"><i class="bi bi-eye"></i></a>
            <?php if (dcc_can('editor')): ?>
            <a href="center_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <a href="visit_form.php?center_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light text-success" title="ตรวจเยี่ยม"><i class="bi bi-clipboard-check"></i></a>
            <?php if (dcc_can('editor')): ?>
            <a href="activity_form.php?center_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light" title="เพิ่มกิจกรรม"><i class="bi bi-calendar-plus"></i></a>
            <?php endif; ?>
            <?php if (dcc_can('admin')): ?>
            <form method="post" class="d-inline" data-confirm="ลบศูนย์ &quot;<?= h($r['name']) ?>&quot; รวมถึงผู้ดูแล ภาพ และกิจกรรมทั้งหมด?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">ไม่พบข้อมูล</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">ทั้งหมด <?= count($rows) ?> ศูนย์</div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
