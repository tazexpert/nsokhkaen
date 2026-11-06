<?php
/**
 * โครงการที่รับผิดชอบ + EA ของโครงการ (หน้าเดียว) — ระบบบริหารโครงการ
 * - ทุกคนดูรายการโครงการ ความก้าวหน้า EA/สถานประกอบการ และเปิดดู EA ของแต่ละโครงการ
 * - ผู้ดูแลระบบกลาง / ผู้ดูแลปฏิทิน / ผู้ดูแลระบบบริหารโครงการ เพิ่ม แก้ไข (ฟอร์มใน popup) ปิดใช้งาน ลบ (เฉพาะที่ยังไม่ถูกใช้)
 * - ตัวย่อโครงการ = รหัสงานในแผนการปฏิบัติงาน
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(EA_MODULE, 'viewer');
$canManage = projects_can_manage($me);
$pdo = db();
$errors = [];
$thisFy = (int)date('Y') + 543 + ((int)date('n') >= 10 ? 1 : 0);      // ปีงบประมาณปัจจุบัน (พ.ศ.)

$find = function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM core_projects WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
$editId = $canManage ? int_param('edit') : 0;
$form = ['code' => '', 'name' => '', 'fiscal_year' => $thisFy, 'survey_period' => '', 'start_date' => '', 'end_date' => '', 'work_group_id' => '',
         'owner_id' => '', 'description' => '', 'color' => '#0d6efd', 'sort_order' => '', 'is_active' => 1];
if ($editId && ($row = $find($editId))) {
    $form = $row;
} else {
    $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$canManage) {
        forbidden(null, '');
    }
    $action = post('action');
    $id = (int)post('id');
    if ($action === 'save') {
        $date = fn(string $v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $form = [
            'code' => trim(post('code')), 'name' => trim(post('name')),
            'fiscal_year' => post('fiscal_year') !== '' ? (int)post('fiscal_year') : null,
            'survey_period' => isset(PROJECT_PERIODS[post('survey_period')]) ? post('survey_period') : '',
            'start_date' => $date(post('start_date')), 'end_date' => $date(post('end_date')),
            'work_group_id' => (int)post('work_group_id') ?: null, 'owner_id' => (int)post('owner_id') ?: null,
            'description' => trim(post('description')),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', post('color')) ? strtolower(post('color')) : '#0d6efd',
            'sort_order' => post('sort_order') !== '' ? (int)post('sort_order') : null, 'is_active' => post('is_active') ? 1 : 0,
        ];
        $editId = $id && $find($id) ? $id : 0;
        if ($form['code'] === '' || mb_strlen($form['code']) > 20 || preg_match('/[\s,+\/*]/u', $form['code']) || $form['code'] === 'เวร') {
            $errors[] = 'ตัวย่อต้องไม่ว่าง ไม่เกิน 20 ตัวอักษร ไม่มีช่องว่างหรือเครื่องหมาย , + / * และห้ามใช้คำว่า "เวร" (สงวนไว้สำหรับเวรสำนักงาน)';
        } else {
            $dup = $pdo->prepare('SELECT id FROM core_projects WHERE code = ? AND id <> ?');
            $dup->execute([$form['code'], $editId]);
            if ($dup->fetchColumn()) {
                $errors[] = 'มีโครงการตัวย่อ "' . $form['code'] . '" อยู่แล้ว';
            }
        }
        if ($form['name'] === '') {
            $errors[] = 'กรุณาระบุชื่อโครงการ';
        }
        if ($form['fiscal_year'] !== null && ($form['fiscal_year'] < 2500 || $form['fiscal_year'] > 2700)) {
            $errors[] = 'ปีงบประมาณต้องเป็น พ.ศ. เช่น ' . $thisFy;
        }
        if ($form['start_date'] && $form['end_date'] && $form['end_date'] < $form['start_date']) {
            $errors[] = 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มต้น';
        }
        if (!$errors) {
            $vals = [$form['code'], mb_substr($form['name'], 0, 255), $form['fiscal_year'], $form['start_date'], $form['end_date'],
                     $form['work_group_id'], $form['owner_id'], $form['description'] !== '' ? $form['description'] : null, $form['color']];
            if ($editId) {
                $pdo->prepare('UPDATE core_projects SET code = ?, name = ?, fiscal_year = ?, start_date = ?, end_date = ?, work_group_id = ?, owner_id = ?,
                               description = ?, color = ?, sort_order = ?, is_active = ?, survey_period = ? WHERE id = ?')
                    ->execute([...$vals, $form['sort_order'] ?? 0, $form['is_active'], $form['survey_period'], $editId]);
                audit_log('project_update', $form['code'] . ' — ' . $form['name']);
                flash('บันทึกโครงการ "' . $form['code'] . '" แล้ว');
            } else {
                $order = $form['sort_order'] ?? (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM core_projects')->fetchColumn();
                $pdo->prepare('INSERT INTO core_projects (code, name, fiscal_year, start_date, end_date, work_group_id, owner_id, description, color, sort_order, is_active, survey_period)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$vals, $order, $form['is_active'], $form['survey_period']]);
                audit_log('project_create', $form['code'] . ' — ' . $form['name']);
                flash('เพิ่มโครงการ "' . $form['code'] . '" แล้ว');
            }
            redirect('projects.php');
        }
    } elseif ($action === 'delete' && ($row = $find($id))) {
        $n = $pdo->prepare('SELECT COUNT(*) FROM cal_plan_work WHERE project_id = ?');
        $n->execute([$id]);
        $e = $pdo->prepare('SELECT COUNT(*) FROM ea_project_eas WHERE project_id = ?');
        $e->execute([$id]);
        if ($n->fetchColumn()) {
            flash('โครงการ "' . $row['code'] . '" ถูกใช้ในแผนการปฏิบัติงานแล้ว ลบไม่ได้ — ปิดใช้งานแทน', 'warning');
        } elseif ($e->fetchColumn()) {
            flash('โครงการ "' . $row['code'] . '" มี EA ของโครงการอยู่ ลบไม่ได้ — ปิดใช้งานแทน หรือล้าง EA ของโครงการก่อน', 'warning');
        } else {
            $pdo->prepare('DELETE FROM core_projects WHERE id = ?')->execute([$id]);
            audit_log('project_delete', $row['code'] . ' — ' . $row['name']);
            flash('ลบโครงการ "' . $row['code'] . '" แล้ว');
        }
        redirect('projects.php');
    }
}

// ---------- รายการ ----------
$fy = $_GET['fy'] ?? '';
$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$groups = work_groups();
$staff = array_values(sort_staff($pdo->query("SELECT id, username, sort_order, full_name, position FROM core_users WHERE is_active = 1 AND username REGEXP '^[0-9]+$'")->fetchAll()));
$names = array_column($pdo->query('SELECT id, full_name FROM core_users')->fetchAll(), 'full_name', 'id');
$usage = array_column($pdo->query('SELECT project_id, COUNT(*) n FROM cal_plan_work GROUP BY project_id')->fetchAll(), 'n', 'project_id');
// ความก้าวหน้า EA / สถานประกอบการของแต่ละโครงการ
$eaStat = $pdo->query("SELECT project_id, COUNT(*) n, SUM(status = 'done') done, SUM(status = 'in_progress') doing, COUNT(DISTINCT round_label) rounds
                       FROM ea_project_eas GROUP BY project_id")->fetchAll(PDO::FETCH_UNIQUE);
$unitStat = $pdo->query("SELECT project_id, COUNT(*) n, SUM(status = 'done') done FROM ea_project_units GROUP BY project_id")->fetchAll(PDO::FETCH_UNIQUE);
$eaUsage = array_map(fn($r) => (int)$r['n'], $eaStat);
$years = array_values(array_unique(array_filter(array_column(projects_all(), 'fiscal_year'))));
rsort($years);
$rows = array_filter(projects_all(), function ($p) use ($fy, $status, $q) {
    if ($fy !== '' && (string)$p['fiscal_year'] !== $fy) {
        return false;
    }
    if ($status !== '' && project_status($p)[0] !== $status) {
        return false;
    }
    return $q === '' || mb_stripos($p['code'] . ' ' . $p['name'] . ' ' . $p['description'], $q) !== false;
});

$pageTitle = 'โครงการที่รับผิดชอบ';
$headExtra = $canManage ? '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">'
    . '<link href="' . h(asset_v('modules/calendar/assets/css/calendar.css')) . '" rel="stylesheet">' : '';
$active = 'projects';
$showForm = $canManage && ($editId || isset($_GET['add']) || $errors);      // เปิด popup ฟอร์มทันที
$pageActions = '<a href="' . h(module_url('calendar', 'plan.php')) . '" class="btn btn-outline-secondary"><i class="bi bi-grid-3x3"></i> แผนการปฏิบัติงาน</a>'
    . ($canManage ? ' <a href="projects.php?add=1" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal" data-project-add><i class="bi bi-plus-lg"></i> เพิ่มโครงการ</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="row g-3 align-items-start" data-reveal>
  <div class="col-12 reveal-item">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center">
          <div class="col-md-4"><input name="q" class="form-control form-control-sm" placeholder="ค้นหาตัวย่อ / ชื่อโครงการ" value="<?= h($q) ?>"></div>
          <div class="col-auto">
            <select name="fy" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="ปีงบประมาณ">
              <option value="">ทุกปีงบประมาณ</option>
              <?php foreach ($years as $yv): ?><option value="<?= (int)$yv ?>"<?= $fy === (string)$yv ? ' selected' : '' ?>>ปีงบประมาณ <?= (int)$yv ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="สถานะ">
              <option value="">ทุกสถานะ</option>
              <?php foreach (['ดำเนินการ', 'ยังไม่เริ่ม', 'สิ้นสุดแล้ว', 'ปิดใช้งาน'] as $sv): ?><option<?= $status === $sv ? ' selected' : '' ?>><?= $sv ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></div>
          <div class="col-auto ms-auto small text-muted"><?= count($rows) ?> โครงการ</div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>ตัวย่อ</th><th>ชื่อโครงการ</th><th class="text-center">ปีงบฯ</th><th>คาบการสำรวจ</th><th>ช่วงปฏิบัติงาน</th><th>ผู้รับผิดชอบ</th><th>สถานะ</th><th style="min-width:180px">EA ของโครงการ</th>
            <?php if ($canManage): ?><th class="text-center" title="จำนวนช่อง (คน-วัน) ในแผนการปฏิบัติงาน">ใช้ในแผน</th><?php endif; ?><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $p): [$st, $sc] = project_status($p); ?>
            <tr class="<?= (int)$p['id'] === $editId ? 'table-primary' : '' ?><?= $p['is_active'] ? '' : ' text-muted' ?>">
              <td><span class="proj-chip" style="--c:<?= h($p['color']) ?>"><?= h($p['code']) ?></span></td>
              <td><div class="fw-semibold"><?= h($p['name']) ?></div>
                <?php if ($p['description']): ?><div class="small text-muted"><?= nl2br(h(mb_strimwidth($p['description'], 0, 200, '…'))) ?></div><?php endif; ?></td>
              <td class="text-center"><?= $p['fiscal_year'] ? (int)$p['fiscal_year'] : '-' ?></td>
              <td class="small text-nowrap"><?= h(project_period_label($p['survey_period'] ?? '') ?: '-') ?></td>
              <td class="small text-nowrap"><?= h(project_period_text($p)) ?></td>
              <td class="small"><?= $p['owner_id'] && isset($names[$p['owner_id']]) ? h(preg_replace('/\s+/u', ' ', $names[$p['owner_id']])) : '' ?>
                <?php if ($p['work_group_id'] && isset($groups[$p['work_group_id']])): ?><div class="text-muted"><?= h($groups[$p['work_group_id']]) ?></div><?php endif; ?>
                <?= !$p['owner_id'] && !$p['work_group_id'] ? '<span class="text-muted">-</span>' : '' ?></td>
              <td><span class="badge text-bg-<?= $sc ?>"><?= $st ?></span></td>
              <td class="small"><?php $es = $eaStat[(int)$p['id']] ?? null; $us = $unitStat[(int)$p['id']] ?? null; ?>
                <?php if ($es): ?>
                  <a href="project_eas.php?id=<?= (int)$p['id'] ?>" class="text-decoration-none d-block">
                    <span class="d-flex"><span><?= number_format((int)$es['n']) ?> EA<?= $es['rounds'] > 1 ? ' · ' . (int)$es['rounds'] . ' รอบ' : '' ?></span>
                      <span class="ms-auto text-muted">เสร็จ <?= round($es['done'] / $es['n'] * 100) ?>%</span></span>
                    <span class="ea-progress mt-1"><span class="done" style="width:<?= $es['done'] / $es['n'] * 100 ?>%"></span><span class="doing" style="width:<?= $es['doing'] / $es['n'] * 100 ?>%"></span></span></a>
                <?php else: ?><span class="text-muted">ยังไม่มี EA</span><?php endif; ?>
                <?php if ($us): ?><a href="project_units.php?id=<?= (int)$p['id'] ?>" class="d-block mt-1 text-decoration-none"><i class="bi bi-shop"></i> สถานประกอบการ <?= number_format((int)$us['n']) ?> · เสร็จ <?= number_format((int)$us['done']) ?></a><?php endif; ?>
              </td>
              <?php if ($canManage): ?>
                <td class="text-center small"><?= !empty($usage[$p['id']]) ? number_format((int)$usage[$p['id']]) : '-' ?></td>
              <?php endif; ?>
                <td class="text-end text-nowrap">
                  <a href="project_eas.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-success" title="EA ของโครงการ" aria-label="EA ของโครงการ"><i class="bi bi-map"></i></a>
              <?php if ($canManage): ?>
                  <a href="<?= h('projects.php?edit=' . (int)$p['id']) ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข" aria-label="แก้ไข"><i class="bi bi-pencil"></i></a>
                  <?php if (empty($usage[$p['id']]) && empty($eaUsage[$p['id']])): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm(<?= js_attr('ลบโครงการ ' . $p['code'] . '?') ?>)"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" title="ลบ" aria-label="ลบ"><i class="bi bi-trash"></i></button></form>
                  <?php endif; ?>
              <?php endif; ?>
                </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-5">ไม่พบโครงการ</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted"><i class="bi bi-info-circle"></i> ตัวย่อใช้เป็นรหัสงานในแผนการปฏิบัติงาน · แถบเครื่องมือของแผนแสดงเฉพาะโครงการที่ "ใช้งาน" และมีช่วงปฏิบัติงานคาบเกี่ยวเดือนนั้น (ไม่ระบุช่วง = ทุกเดือน)
        <?= $canManage ? ' · โครงการที่ถูกใช้ในแผนหรือมี EA แล้วลบไม่ได้ (ปิดใช้งานแทน)' : '' ?>
        · <span class="ea-legend"><i style="background:#22c55e"></i>สำรวจเสร็จ <i style="background:#f59e0b" class="ms-2"></i>กำลังสำรวจ</span></div>
    </div>
  </div>

</div>

  <?php if ($canManage): ?>
<div class="modal fade" id="projectModal" tabindex="-1" aria-labelledby="projectModalTitle" aria-hidden="true"<?= $showForm ? ' data-show' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" class="modal-content" id="projectForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
      <div class="modal-header"><h5 class="modal-title" id="projectModalTitle"><i class="bi <?= $editId ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i> <?= $editId ? 'แก้ไขโครงการ ' . h($form['code']) : 'เพิ่มโครงการ' ?></h5>
        <a href="projects.php" class="btn-close" aria-label="ปิด"<?= $editId || $errors ? '' : ' data-bs-dismiss="modal"' ?>></a></div>
      <div class="modal-body">
        <?php if ($errors): ?><div class="alert alert-danger py-2 small"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
        <div class="row g-2 mb-2">
          <div class="col-5">
            <label class="form-label" for="pjCode">ตัวย่อ <span class="text-danger">*</span></label>
            <input name="code" id="pjCode" class="form-control fw-semibold" maxlength="20" required value="<?= h($form['code']) ?>" placeholder="เช่น สรง">
          </div>
          <div class="col-4">
            <label class="form-label" for="pjFy">ปีงบประมาณ</label>
            <input type="number" name="fiscal_year" id="pjFy" class="form-control" min="2500" max="2700" value="<?= h((string)($form['fiscal_year'] ?? '')) ?>" placeholder="<?= $thisFy ?>">
          </div>
          <div class="col-3">
            <label class="form-label" for="pjColor">สี</label>
            <input type="color" name="color" id="pjColor" class="form-control form-control-color w-100" value="<?= h($form['color']) ?>">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="pjName">ชื่อโครงการ <span class="text-danger">*</span></label>
          <input name="name" id="pjName" class="form-control" maxlength="255" required value="<?= h($form['name']) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label" for="pjPeriod">คาบการสำรวจ</label>
          <select name="survey_period" id="pjPeriod" class="form-select">
            <option value="">— ไม่ระบุ (กำหนดจากไฟล์นำเข้า EA) —</option>
            <?php foreach (PROJECT_PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= ($form['survey_period'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text">ไม่ระบุ = ระบบกำหนดให้ตอนนำเข้า EA ของโครงการ (Q1–Q4 = รายไตรมาส · Month = รายเดือน · ไม่มีเดือน = รายปี)</div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label" for="pjStart">เริ่มปฏิบัติงาน</label><input type="text" name="start_date" id="pjStart" class="form-control thai-date" value="<?= h((string)$form['start_date']) ?>"></div>
          <div class="col-6"><label class="form-label" for="pjEnd">สิ้นสุด</label><input type="text" name="end_date" id="pjEnd" class="form-control thai-date" value="<?= h((string)$form['end_date']) ?>"></div>
          <div class="col-12 form-text mt-0">ไม่ระบุ = แสดงในแผนทุกเดือน (ขณะที่ "ใช้งาน")</div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="pjGroup">กลุ่มงานที่รับผิดชอบ</label>
          <select name="work_group_id" id="pjGroup" class="form-select">
            <option value="">-</option>
            <?php foreach ($groups as $gid => $gn): ?><option value="<?= (int)$gid ?>"<?= (int)$form['work_group_id'] === (int)$gid ? ' selected' : '' ?>><?= h($gn) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label" for="pjOwner">ผู้รับผิดชอบโครงการ</label>
          <select name="owner_id" id="pjOwner" class="form-select">
            <option value="">-</option>
            <?php foreach ($staff as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$form['owner_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= h(preg_replace('/\s+/u', ' ', $u['full_name'])) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label" for="pjDesc">รายละเอียด</label>
          <textarea name="description" id="pjDesc" class="form-control" rows="3" maxlength="2000"><?= h((string)$form['description']) ?></textarea>
        </div>
        <div class="row g-2 align-items-center">
          <div class="col-5"><label class="form-label small mb-0" for="pjOrder">ลำดับ</label>
            <input type="number" name="sort_order" id="pjOrder" class="form-control form-control-sm" value="<?= h((string)($form['sort_order'] ?? '')) ?>" placeholder="อัตโนมัติ"></div>
          <div class="col-7 pt-3"><div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="pjActive"<?= $form['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="pjActive">ใช้งาน</label></div></div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="projects.php" class="btn btn-outline-secondary"<?= $editId || $errors ? '' : ' data-bs-dismiss="modal"' ?>>ยกเลิก</a>
        <button class="btn btn-primary"><i class="bi bi-save"></i> <?= $editId ? 'บันทึกการแก้ไข' : 'เพิ่มโครงการ' ?></button>
      </div>
    </form>
  </div>
</div>
  <?php endif; ?>
<style>
  .proj-chip { display: inline-block; min-width: 3rem; text-align: center; padding: .1rem .5rem; border-radius: .35rem; font-weight: 700;
               color: var(--c); background: color-mix(in srgb, var(--c) 15%, white); }
</style>
<?php if ($canManage): ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js" integrity="sha384-E6OVFg1zUc/pckGxry0YZ6VeZ1E17l7Jl8ZQx58jbEfxxkz9LqknQhiIrA9zg/8b" crossorigin="anonymous"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // popup ฟอร์มโครงการ: เปิดทันทีเมื่อแก้ไข / เพิ่ม / บันทึกไม่ผ่าน · ปุ่มเพิ่มโครงการขณะแก้ไขอยู่ = ไปหน้าเพิ่มใหม่
  const el = document.getElementById('projectModal');
  if (!el || !window.bootstrap) { return; }
  const modal = bootstrap.Modal.getOrCreateInstance(el, { backdrop: <?= $editId || $errors ? "'static'" : 'true' ?> });
  if (el.hasAttribute('data-show')) { modal.show(); }
  <?php if ($editId || $errors): ?>
  document.querySelectorAll('[data-project-add]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); location.href = 'projects.php?add=1'; }, true));
  <?php endif; ?>
  el.addEventListener('shown.bs.modal', () => document.getElementById('pjCode').focus());
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
