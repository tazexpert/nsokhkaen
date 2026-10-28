<?php
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'editor');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    check_csrf();
    dcc_require('admin');              // ลบข้อมูลได้เฉพาะผู้ดูแลระบบศูนย์ฯ
    $id = int_param('id');
    $st = $pdo->prepare('SELECT cover_image FROM dcc_activities WHERE id = ?');
    $st->execute([$id]);
    $files = [$st->fetchColumn()];
    $st = $pdo->prepare('SELECT file FROM dcc_activity_images WHERE activity_id = ?');
    $st->execute([$id]);
    $files = array_merge($files, $st->fetchAll(PDO::FETCH_COLUMN));
    $pdo->prepare('DELETE FROM dcc_activities WHERE id = ?')->execute([$id]);
    array_map('delete_upload', array_filter($files));
    flash('ลบกิจกรรมเรียบร้อยแล้ว');
    redirect('activities.php' . (int_param('center_id') ? '?center_id=' . int_param('center_id') : ''));
}

$centerId = int_param('center_id');
$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($centerId) {
    $where[] = 'a.center_id = ?';
    $params[] = $centerId;
}
if ($q !== '') {
    $where[] = '(a.title LIKE ? OR a.organizer LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
$st = $pdo->prepare(
    "SELECT a.*, c.name AS center_name, c.district,
            (SELECT v.id FROM dcc_visits v WHERE v.activity_id = a.id LIMIT 1) AS visit_id,
            (SELECT COUNT(*) FROM dcc_activity_images i WHERE i.activity_id = a.id) AS image_count,
            (SELECT file FROM dcc_activity_images i WHERE i.activity_id = a.id ORDER BY i.id LIMIT 1) AS first_image
     FROM dcc_activities a JOIN dcc_centers c ON c.id = a.center_id" .
    ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
    ' ORDER BY a.activity_date IS NULL, a.activity_date DESC, a.id DESC'
);
$st->execute($params);
$rows = $st->fetchAll();
$centers = $pdo->query('SELECT id, name, district FROM dcc_centers ORDER BY district, name')->fetchAll();

$pageTitle = 'กิจกรรมที่จัดร่วมกับศูนย์ฯ';
$active = 'activities';
require __DIR__ . '/header.php';
?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center">
      <div class="col-md-5">
        <select name="center_id" class="form-select" onchange="this.form.submit()">
          <option value="0">ทุกศูนย์</option>
          <?php foreach ($centers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $centerId ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['district']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><input name="q" class="form-control" placeholder="ค้นหาชื่อกิจกรรม / ผู้จัด" value="<?= h($q) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <div class="col-auto ms-auto"><a href="activity_form.php<?= $centerId ? '?center_id=' . $centerId : '' ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th style="width:72px"></th><th>กิจกรรม</th><th>ศูนย์</th><th>วันที่</th><th class="text-center">ผู้เข้าร่วม</th><th class="text-center">ภาพ</th><th class="text-end">จัดการ</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $thumb = $r['cover_image'] ?: $r['first_image']; ?>
        <tr>
          <td><?php if ($thumb): ?><img src="<?= h(upload_url($thumb)) ?>" class="thumb" alt=""><?php else: ?><span class="thumb thumb-empty"><i class="bi bi-image"></i></span><?php endif; ?></td>
          <td><a href="activity_form.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h($r['title']) ?></a>
            <?php if ($r['visit_id']): ?> <a href="visit_view.php?id=<?= (int)$r['visit_id'] ?>" class="badge text-bg-success text-decoration-none"><i class="bi bi-clipboard-check"></i> แบบตรวจเยี่ยม</a><?php endif; ?>
            <div class="small text-muted"><?= h($r['organizer']) ?></div></td>
          <td><?= h($r['center_name']) ?><div class="small text-muted">อ.<?= h($r['district']) ?></div></td>
          <td class="text-nowrap"><?= thai_date($r['activity_date']) ?></td>
          <td class="text-center"><?= number_format((int)$r['participants']) ?></td>
          <td class="text-center"><?= (int)$r['image_count'] + ($r['cover_image'] ? 1 : 0) ?></td>
          <td class="text-end text-nowrap">
            <a href="activity_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
            <?php if (dcc_can('admin')): ?>
            <form method="post" class="d-inline" data-confirm="ลบกิจกรรม &quot;<?= h($r['title']) ?>&quot; และภาพทั้งหมด?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีกิจกรรม</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">ทั้งหมด <?= count($rows) ?> กิจกรรม · ผู้เข้าร่วมรวม <?= number_format(array_sum(array_column($rows, 'participants'))) ?> คน</div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
