<?php
/**
 * เพิ่ม/แก้ไขตัวชี้วัด (ผู้ควบคุมตัวชี้วัด / ผู้ดูแล)
 *   ข้อมูลตัวชี้วัด · ผู้ปฏิบัติ (ช่องเลือกแบบปฏิทิน) · เกณฑ์การให้คะแนน: ระดับ → กิจกรรม/หลักฐาน (มอบผู้ปฏิบัติรายกิจกรรม · วันที่ต้องส่ง)
 *   แก้ไข: ระดับ/กิจกรรมเดิมคงรหัสไว้ (บันทึกความก้าวหน้า/ไฟล์ยังอยู่) · ลบระดับ/กิจกรรมออกจากฟอร์ม = ลบพร้อมหลักฐานของกิจกรรมนั้น
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user_picker.php';
$me = require_module(KPI_MODULE, 'controller');
$pdo = db();
$id = int_param('id');
$ind = $id ? kpi_get($id) : null;
if ($id && !$ind) {
    flash('ไม่พบตัวชี้วัด', 'danger');
    redirect('./');
}
if ($ind && !kpi_can_manage($ind)) {
    forbidden(KPI_MODULE, 'admin');
}
[$curFy, $curR] = kpi_period_param();
$f = $ind ?: ['fiscal_year' => $curFy, 'round_no' => $curR, 'code' => '', 'name' => '', 'unit' => 'คะแนน', 'weight' => '', 'description' => '',
              'work_group_id' => $me['work_group_id'] ?? null, 'note' => '', 'controller_id' => $me['id'], 'collector_id' => null, 'sort_order' => 0];
$users = $id ? (kpi_indicator_users([$id])[$id] ?? []) : [];
$levels = $id ? kpi_structure($id, $users) : [];
$errors = [];

$dec = fn($v) => ($v = trim((string)$v)) === '' ? null : round((float)str_replace(',', '', $v), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'delete' && $ind) {
        kpi_delete_files_of('a.indicator_id = ?', [$id]);
        $pdo->prepare('DELETE FROM kpi_indicators WHERE id = ?')->execute([$id]);
        audit_log('kpi_delete', '#' . $id . ' ' . $ind['name'], KPI_MODULE);
        flash('ลบตัวชี้วัด "' . $ind['name'] . '" แล้ว');
        redirect('./?' . http_build_query(['fy' => $ind['fiscal_year'], 'r' => $ind['round_no']]));
    }
    $f = ['fiscal_year' => int_param('fiscal_year'), 'round_no' => int_param('round_no'), 'code' => trim(post('code')), 'name' => trim(post('name')),
          'unit' => trim(post('unit')), 'weight' => $dec(post('weight')), 'description' => trim(post('description')),
          'work_group_id' => int_param('work_group_id') ?: null, 'note' => trim(post('note')),
          'controller_id' => int_param('controller_id') ?: null, 'collector_id' => int_param('collector_id') ?: null, 'sort_order' => int_param('sort_order')];
    $users = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['users'] ?? [])))));
    if ($f['name'] === '') {
        $errors[] = 'กรุณาระบุชื่อตัวชี้วัด';
    }
    if ($f['fiscal_year'] < 2500 || !in_array($f['round_no'], [1, 2], true)) {
        $errors[] = 'กรุณาเลือกปีงบประมาณและรอบ';
    }
    // ระดับ/กิจกรรมจากฟอร์ม (ลำดับตามที่แสดง)
    $postLevels = [];
    foreach ((array)($_POST['lv'] ?? []) as $l) {
        $acts = [];
        foreach ((array)($l['act'] ?? []) as $a) {
            if (trim((string)($a['title'] ?? '')) === '') {
                continue;
            }
            $due = trim((string)($a['due'] ?? ''));
            $acts[] = ['id' => (int)($a['id'] ?? 0), 'title' => trim($a['title']), 'rule' => trim((string)($a['rule'] ?? '')),
                       'max' => $dec($a['max'] ?? ''), 'due' => DateTime::createFromFormat('!Y-m-d', $due) ? $due : null,
                       'users' => array_values(array_intersect(array_map('intval', array_filter(explode(',', (string)($a['users'] ?? '')))), $users))];
        }
        if (trim((string)($l['issue'] ?? '')) === '' && !$acts) {
            continue;
        }
        if (trim((string)($l['issue'] ?? '')) === '') {
            $errors[] = 'กรุณาระบุประเด็นการประเมินของระดับที่ ' . (int)($l['no'] ?? 0);
        }
        $postLevels[] = ['id' => (int)($l['id'] ?? 0), 'no' => max(1, (int)($l['no'] ?? 1)), 'issue' => trim((string)($l['issue'] ?? '')), 'max' => $dec($l['max'] ?? ''), 'acts' => $acts];
    }
    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $cols = ['fiscal_year', 'round_no', 'code', 'name', 'unit', 'weight', 'description', 'work_group_id', 'note', 'controller_id', 'collector_id', 'sort_order'];
            $vals = array_map(fn($k) => $f[$k] === '' ? null : $f[$k], $cols);
            if ($id) {
                $pdo->prepare('UPDATE kpi_indicators SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            } else {
                $pdo->prepare('INSERT INTO kpi_indicators (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols) + 1, '?')) . ')')
                    ->execute([...$vals, $me['id']]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM kpi_indicator_users WHERE indicator_id = ?')->execute([$id]);
            $insU = $pdo->prepare('INSERT INTO kpi_indicator_users (indicator_id, user_id) VALUES (?, ?)');
            foreach ($users as $u) {
                $insU->execute([$id, $u]);
            }
            // ระดับ/กิจกรรมเดิมที่ไม่อยู่ในฟอร์มแล้ว → ลบ (พร้อมไฟล์หลักฐาน)
            $oldLevels = array_map('intval', $pdo->query('SELECT id FROM kpi_levels WHERE indicator_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN));
            $oldActs = array_map('intval', $pdo->query('SELECT id FROM kpi_activities WHERE indicator_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN));
            $keepL = array_intersect(array_column($postLevels, 'id'), $oldLevels);
            $keepA = array_intersect(array_merge([], ...array_map(fn($l) => array_column($l['acts'], 'id'), $postLevels)), $oldActs);
            if ($dropA = array_diff($oldActs, $keepA)) {
                kpi_delete_files_of('a.id IN (' . implode(',', $dropA) . ')', []);
                $pdo->exec('DELETE FROM kpi_activities WHERE id IN (' . implode(',', $dropA) . ')');
            }
            if ($dropL = array_diff($oldLevels, $keepL)) {
                $pdo->exec('DELETE FROM kpi_levels WHERE id IN (' . implode(',', $dropL) . ')');
            }
            $updL = $pdo->prepare('UPDATE kpi_levels SET level_no = ?, issue = ?, max_score = ? WHERE id = ?');
            $insL = $pdo->prepare('INSERT INTO kpi_levels (indicator_id, level_no, issue, max_score) VALUES (?, ?, ?, ?)');
            $updA = $pdo->prepare('UPDATE kpi_activities SET level_id = ?, title = ?, score_rule = ?, max_score = ?, due_date = ?, sort_order = ? WHERE id = ?');
            $insA = $pdo->prepare('INSERT INTO kpi_activities (indicator_id, level_id, title, score_rule, max_score, due_date, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $delAU = $pdo->prepare('DELETE FROM kpi_activity_users WHERE activity_id = ?');
            $insAU = $pdo->prepare('INSERT INTO kpi_activity_users (activity_id, user_id) VALUES (?, ?)');
            $sort = 0;
            foreach ($postLevels as $l) {
                if (in_array($l['id'], $keepL, true)) {
                    $updL->execute([$l['no'], $l['issue'], $l['max'], $l['id']]);
                    $lid = $l['id'];
                } else {
                    $insL->execute([$id, $l['no'], $l['issue'], $l['max']]);
                    $lid = (int)$pdo->lastInsertId();
                }
                foreach ($l['acts'] as $a) {
                    $sort++;
                    if (in_array($a['id'], $keepA, true)) {
                        $updA->execute([$lid, $a['title'], $a['rule'] ?: null, $a['max'], $a['due'], $sort, $a['id']]);
                        $aid = $a['id'];
                    } else {
                        $insA->execute([$id, $lid, $a['title'], $a['rule'] ?: null, $a['max'], $a['due'], $sort]);
                        $aid = (int)$pdo->lastInsertId();
                    }
                    $delAU->execute([$aid]);
                    foreach ($a['users'] as $u) {
                        $insAU->execute([$aid, $u]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        audit_log($ind ? 'kpi_update' : 'kpi_create', '#' . $id . ' ' . $f['name'], KPI_MODULE);
        flash('บันทึกตัวชี้วัด "' . $f['name'] . '" แล้ว');
        redirect('indicator?id=' . $id);
    }
    // แสดงฟอร์มเดิมพร้อมข้อผิดพลาด
    $levels = array_map(fn($l) => ['id' => $l['id'], 'level_no' => $l['no'], 'issue' => $l['issue'], 'max_score' => $l['max'],
        'activities' => array_map(fn($a) => ['id' => $a['id'], 'title' => $a['title'], 'score_rule' => $a['rule'], 'max_score' => $a['max'],
            'due_date' => $a['due'], 'own_users' => $a['users']], $l['acts'])], $postLevels);
}

$staff = cal_users();
$names = kpi_user_names();
$groups = $pdo->query('SELECT id, name FROM core_work_groups ORDER BY sort_order, id')->fetchAll();
$pageTitle = $ind ? 'แก้ไขตัวชี้วัด' : 'เพิ่มตัวชี้วัด';
$active = $ind ? 'dashboard' : 'add';
$headExtra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">';
require __DIR__ . '/header.php';
$num = fn($v) => $v === null || $v === '' ? '' : kpi_num($v);

/** แถวกิจกรรม 1 แถว (ใช้ทั้งข้อมูลเดิมและแม่แบบ __L__/__A__) */
$actRow = function (string $L, string $A, array $a) use ($num): string {
    $p = "lv[$L][act][$A]";
    return '<div class="kpi-act" data-a="' . h($A) . '">'
        . '<input type="hidden" name="' . $p . '[id]" value="' . (int)($a['id'] ?? 0) . '">'
        . '<input type="hidden" name="' . $p . '[users]" class="act-users" value="' . h(implode(',', $a['own_users'] ?? [])) . '">'
        . '<div class="row g-2">'
        . '<div class="col-lg-5"><label class="form-label small mb-0">กิจกรรม / หลักฐาน-เอกสารประกอบ <span class="text-danger">*</span></label>'
        . '<textarea name="' . $p . '[title]" class="form-control form-control-sm" rows="2" placeholder="เช่น ส่งรายงานความก้าวหน้าเดือน พ.ค. 69">' . h($a['title'] ?? '') . '</textarea></div>'
        . '<div class="col-lg-4"><label class="form-label small mb-0">เงื่อนไขคะแนน</label>'
        . '<textarea name="' . $p . '[rule]" class="form-control form-control-sm" rows="2" placeholder="เช่น ส่งภายในวันที่ 2 มิ.ย. 69 ได้ 0.5 คะแนน">' . h($a['score_rule'] ?? '') . '</textarea></div>'
        . '<div class="col-4 col-lg-1"><label class="form-label small mb-0">คะแนน</label><input name="' . $p . '[max]" class="form-control form-control-sm" inputmode="decimal" value="' . h($num($a['max_score'] ?? null)) . '"></div>'
        . '<div class="col-8 col-lg-2"><label class="form-label small mb-0">วันที่ต้องส่ง</label><input type="text" name="' . $p . '[due]" class="form-control form-control-sm thai-date" value="' . h($a['due_date'] ?? '') . '" placeholder="ไม่ระบุ"></div>'
        . '<div class="col-12 d-flex align-items-center gap-2 flex-wrap"><span class="small text-muted"><i class="bi bi-person-check"></i> ผู้ปฏิบัติ:</span>'
        . '<span class="act-chips d-flex flex-wrap gap-1"></span>'
        . '<button type="button" class="btn btn-sm btn-link text-danger ms-auto" data-del-act><i class="bi bi-trash"></i> ลบกิจกรรม</button></div>'
        . '</div></div>';
};
$levelBox = function (string $L, array $l, string $acts) use ($num): string {
    $p = "lv[$L]";
    return '<div class="card kpi-level mb-3" data-l="' . h($L) . '"><div class="card-header d-flex align-items-center gap-2">'
        . '<strong>ระดับที่</strong><input name="' . $p . '[no]" class="form-control form-control-sm lv-no" style="width:4.5rem" inputmode="numeric" value="' . h((string)($l['level_no'] ?? '')) . '">'
        . '<span class="ms-auto small">คะแนนเต็มของระดับ</span><input name="' . $p . '[max]" class="form-control form-control-sm" style="width:5.5rem" inputmode="decimal" value="' . h($num($l['max_score'] ?? null)) . '" placeholder="รวมจากกิจกรรม">'
        . '<button type="button" class="btn btn-sm btn-light" data-del-level title="ลบระดับนี้"><i class="bi bi-trash"></i></button></div>'
        . '<div class="card-body"><input type="hidden" name="' . $p . '[id]" value="' . (int)($l['id'] ?? 0) . '">'
        . '<label class="form-label">ประเด็นการประเมิน <span class="text-danger">*</span></label>'
        . '<textarea name="' . $p . '[issue]" class="form-control mb-3" rows="2" placeholder="เช่น การรายงานความก้าวหน้าผลการดำเนินงานแบบออนไลน์">' . h($l['issue'] ?? '') . '</textarea>'
        . '<div class="kpi-acts">' . $acts . '</div>'
        . '<button type="button" class="btn btn-sm btn-outline-primary" data-add-act><i class="bi bi-plus"></i> เพิ่มกิจกรรม/หลักฐาน</button>'
        . '</div></div>';
};
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" id="kpiForm">
  <?= csrf_field() ?>
  <div class="row g-3 mb-3">
    <div class="col-xl-7">
      <div class="card h-100">
        <div class="card-header"><strong><i class="bi bi-bullseye"></i> ข้อมูลตัวชี้วัด</strong></div>
        <div class="card-body row g-3">
          <div class="col-sm-4"><label class="form-label">ปีงบประมาณ</label>
            <select name="fiscal_year" class="form-select"><?php foreach (array_unique(array_merge(kpi_years(), [(int)$f['fiscal_year']])) as $y): ?><option value="<?= $y ?>" <?= (int)$f['fiscal_year'] === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-8"><label class="form-label">รอบที่</label>
            <div class="btn-group w-100" role="group">
              <?php foreach ([1 => '1 ต.ค. – 31 มี.ค.', 2 => '1 เม.ย. – 30 ก.ย.'] as $r => $txt): ?>
                <input type="radio" class="btn-check" name="round_no" id="round<?= $r ?>" value="<?= $r ?>" <?= (int)$f['round_no'] === $r ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="round<?= $r ?>">รอบที่ <?= $r ?> <span class="small">(<?= $txt ?>)</span></label>
              <?php endforeach; ?>
            </div></div>
          <div class="col-sm-3"><label class="form-label">ตัวชี้วัดที่</label><input name="code" class="form-control" maxlength="20" value="<?= h($f['code']) ?>" placeholder="เช่น 2 หรือ 1.1"></div>
          <div class="col-sm-9"><label class="form-label">ชื่อตัวชี้วัด <span class="text-danger">*</span></label>
            <textarea name="name" class="form-control" rows="2" maxlength="500" required><?= h($f['name']) ?></textarea></div>
          <div class="col-sm-4"><label class="form-label">หน่วยวัด</label><input name="unit" class="form-control" maxlength="100" value="<?= h($f['unit']) ?>"></div>
          <div class="col-sm-4"><label class="form-label">น้ำหนัก (ร้อยละ)</label><input name="weight" class="form-control" inputmode="decimal" value="<?= h($num($f['weight'])) ?>"></div>
          <div class="col-sm-4"><label class="form-label">ลำดับการแสดง</label><input name="sort_order" type="number" class="form-control" value="<?= (int)$f['sort_order'] ?>"></div>
          <div class="col-12"><label class="form-label">คำอธิบาย</label><textarea name="description" class="form-control" rows="6"><?= h($f['description']) ?></textarea></div>
          <div class="col-sm-6"><label class="form-label">กลุ่มงานที่รับผิดชอบ</label>
            <select name="work_group_id" class="form-select"><option value="">— ไม่ระบุ —</option>
              <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$f['work_group_id'] === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label">ผู้ควบคุมตัวชี้วัด</label>
            <select name="controller_id" class="form-select"><option value="">— ไม่ระบุ —</option>
              <?php foreach ($staff as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$f['controller_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label">ผู้จัดเก็บข้อมูล</label>
            <select name="collector_id" class="form-select"><option value="">— ไม่ระบุ —</option>
              <?php foreach ($staff as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$f['collector_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label">หมายเหตุ</label><textarea name="note" class="form-control" rows="3"><?= h($f['note']) ?></textarea></div>
        </div>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="card h-100">
        <div class="card-header"><strong><i class="bi bi-people"></i> ผู้ปฏิบัติ</strong></div>
        <div class="card-body">
          <?= kpi_user_picker('users', $users, 'kpiUsers') ?>
          <div class="form-text">มอบผู้ปฏิบัติรายกิจกรรมได้ในเกณฑ์การให้คะแนนด้านล่าง (เลือกจากผู้ปฏิบัติที่นี่) · กิจกรรมที่ไม่เลือก = ผู้ปฏิบัติทุกคน</div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex align-items-center mb-2">
    <h2 class="h5 mb-0"><i class="bi bi-list-ol"></i> เกณฑ์การให้คะแนน</h2>
    <span class="ms-auto small text-muted">คะแนนรวม <b id="sumMax">0</b> คะแนน</span>
  </div>
  <div id="levels">
    <?php foreach ($levels as $li => $l): ?>
      <?= $levelBox('o' . $li, $l, implode('', array_map(fn($ai, $a) => $actRow('o' . $li, 'o' . $ai, $a), array_keys($l['activities']), $l['activities']))) ?>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn btn-outline-primary mb-4" id="addLevel"><i class="bi bi-plus-lg"></i> เพิ่มระดับ</button>

  <div class="sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึก</button>
    <a href="<?= $ind ? 'indicator?id=' . $id : './' ?>" class="btn btn-light btn-lg">ยกเลิก</a>
  </div>
</form>
<?php if ($ind): ?>
  <form method="post" class="mt-4" data-confirm="ลบตัวชี้วัด &quot;<?= h($ind['name']) ?>&quot; พร้อมบันทึกความก้าวหน้าและไฟล์หลักฐานทั้งหมด? (กู้คืนไม่ได้)">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> ลบตัวชี้วัดนี้</button></form>
<?php endif; ?>

<template id="tplLevel"><?= $levelBox('__L__', [], '') ?></template>
<template id="tplAct"><?= $actRow('__L__', '__A__', []) ?></template>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js" integrity="sha384-E6OVFg1zUc/pckGxry0YZ6VeZ1E17l7Jl8ZQx58jbEfxxkz9LqknQhiIrA9zg/8b" crossorigin="anonymous"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<script>
(() => {
  const levels = document.getElementById('levels');
  const picker = document.getElementById('kpiUsers');
  let people = [];                 // ผู้ปฏิบัติที่เลือกในตัวชี้วัด [{id, name}]
  let seq = 0;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const first = n => n.replace(/^(นางสาว|นาง|นาย|ว่าที่ร้อยตรี|ดร\.)\s*/, '').split(/\s+/)[0];

  // ปุ่มเลือกผู้ปฏิบัติรายกิจกรรม (จากผู้ปฏิบัติของตัวชี้วัด) — ไม่เลือก = ทุกคน
  const chips = act => {
    const hidden = act.querySelector('.act-users');
    const sel = hidden.value.split(',').filter(Boolean).filter(id => people.some(p => p.id === id));
    hidden.value = sel.join(',');
    const box = act.querySelector('.act-chips');
    box.innerHTML = people.length ? people.map(p => `<button type="button" class="btn btn-sm kpi-chip${sel.includes(p.id) ? ' on' : ''}" data-uid="${esc(p.id)}" title="${esc(p.name)}">${esc(first(p.name))}</button>`).join('')
      + `<span class="small text-muted ms-1">${sel.length ? 'เลือก ' + sel.length + ' คน' : '(ทุกคน)'}</span>` : '<span class="small text-muted">เลือกผู้ปฏิบัติของตัวชี้วัดก่อน</span>';
  };
  levels.addEventListener('click', e => {
    const chip = e.target.closest('.kpi-chip');
    if (chip) {
      const act = chip.closest('.kpi-act'), hidden = act.querySelector('.act-users');
      let sel = hidden.value.split(',').filter(Boolean);
      sel = sel.includes(chip.dataset.uid) ? sel.filter(x => x !== chip.dataset.uid) : [...sel, chip.dataset.uid];
      hidden.value = sel.join(',');
      chips(act);
      return;
    }
    if (e.target.closest('[data-add-act]')) addAct(e.target.closest('.kpi-level'));
    if (e.target.closest('[data-del-act]') && confirm('ลบกิจกรรมนี้? (ถ้ามีบันทึกความก้าวหน้า/ไฟล์หลักฐาน จะถูกลบเมื่อกดบันทึก)')) { e.target.closest('.kpi-act').remove(); sum(); }
    if (e.target.closest('[data-del-level]') && confirm('ลบระดับนี้พร้อมกิจกรรมทั้งหมดในระดับ?')) { e.target.closest('.kpi-level').remove(); sum(); }
  });
  const addAct = lv => {
    const html = document.getElementById('tplAct').innerHTML.replaceAll('__L__', lv.dataset.l).replaceAll('__A__', 'n' + (++seq));
    lv.querySelector('.kpi-acts').insertAdjacentHTML('beforeend', html);
    const act = lv.querySelector('.kpi-acts').lastElementChild;
    act.querySelectorAll('.thai-date').forEach(el => window.thaiDatePicker && window.thaiDatePicker(el));
    chips(act);
    act.querySelector('textarea').focus();
  };
  document.getElementById('addLevel').addEventListener('click', () => {
    const nos = [...levels.querySelectorAll('.lv-no')].map(i => parseInt(i.value, 10) || 0);
    levels.insertAdjacentHTML('beforeend', document.getElementById('tplLevel').innerHTML.replaceAll('__L__', 'n' + (++seq)));
    const lv = levels.lastElementChild;
    lv.querySelector('.lv-no').value = (nos.length ? Math.max(...nos) : 0) + 1;
    addAct(lv);
  });
  // คะแนนรวม: คะแนนเต็มของระดับ (ไม่ระบุ = รวมคะแนนกิจกรรม)
  const sum = () => {
    let t = 0;
    levels.querySelectorAll('.kpi-level').forEach(lv => {
      const m = parseFloat(lv.querySelector('[name$="[max]"]').value);
      t += !isNaN(m) ? m : [...lv.querySelectorAll('.kpi-act [name$="[max]"]')].reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
    });
    document.getElementById('sumMax').textContent = Math.round(t * 100) / 100;
  };
  levels.addEventListener('input', sum);
  picker.addEventListener('pickchange', e => { people = e.detail; levels.querySelectorAll('.kpi-act').forEach(chips); });
  if (!levels.children.length) document.getElementById('addLevel').click();
  sum();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
