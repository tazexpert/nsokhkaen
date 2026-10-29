<?php
/** เพิ่ม/แก้ไข/ลบกิจกรรม (ผู้บันทึกกิจกรรมขึ้นไป) — ผู้รับผิดชอบได้รับการแจ้งเตือน */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(CAL_MODULE, 'editor');
$pdo = db();
$id = int_param('id');

$e = ['event_date' => $_GET['date'] ?? date('Y-m-d'), 'end_date' => null, 'start_time' => '09:00:00', 'end_time' => '16:30:00',
      'title' => '', 'location' => '', 'link' => '', 'note' => '', 'created_by' => $me['id']];
$assigned = [];
if (!DateTime::createFromFormat('!Y-m-d', (string)$e['event_date'])) {
    $e['event_date'] = date('Y-m-d');
}
if ($id) {
    $st = $pdo->prepare('SELECT * FROM cal_events WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e || !cal_can_edit($e)) {
        flash('ไม่พบกิจกรรม หรือไม่มีสิทธิ์แก้ไข', 'danger');
        redirect('index.php');
    }
    $assigned = cal_assignees([$id])[$id] ?? [];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'delete' && $id) {
        $pdo->prepare('DELETE FROM cal_events WHERE id = ?')->execute([$id]);
        audit_log('cal_delete', '#' . $id . ' ' . $e['title'], CAL_MODULE);
        flash('ลบกิจกรรมแล้ว');
        redirect('index.php?m=' . substr($e['event_date'], 0, 7));
    }
    $before = $e;
    $allDay = !empty($_POST['all_day']);
    $e = array_merge($e, [
        'event_date' => post('event_date'), 'end_date' => post('end_date') ?: null,
        'start_time' => $allDay ? null : (post('start_time') ?: null), 'end_time' => $allDay ? null : (post('end_time') ?: null),
        'title' => post('title'), 'location' => post('location'), 'link' => post('link'), 'note' => trim((string)($_POST['note'] ?? '')),
    ]);
    $users = cal_users();
    $assigned = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['users'] ?? [])), fn($u) => isset($users[$u]))));

    if ($e['title'] === '') {
        $errors[] = 'กรุณากรอกชื่อกิจกรรม';
    }
    if (!DateTime::createFromFormat('!Y-m-d', $e['event_date'])) {
        $errors[] = 'กรุณาเลือกวันที่';
    }
    if ($e['end_date'] && (!DateTime::createFromFormat('!Y-m-d', $e['end_date']) || $e['end_date'] < $e['event_date'])) {
        $errors[] = 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่ม';
    }
    if ($e['end_date'] === $e['event_date']) {
        $e['end_date'] = null;
    }
    foreach (['start_time', 'end_time'] as $k) {          // เวลาไทย "09.00" → เวลาในฐานข้อมูล "09:00:00"
        $t = cal_parse_time($e[$k]);
        if ($t === false) {
            $errors[] = 'รูปแบบเวลาไม่ถูกต้อง (เช่น 09.00)';
        } else {
            $e[$k] = $t;
        }
    }
    if ($e['start_time'] && $e['end_time'] && !$e['end_date'] && $e['end_time'] < $e['start_time']) {
        $errors[] = 'เวลาสิ้นสุดต้องไม่ก่อนเวลาเริ่ม';
    }
    if ($e['link'] !== '' && !cal_safe_link($e['link'])) {
        $errors[] = 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://';
    }
    if (!$assigned) {
        $errors[] = 'กรุณาเลือกผู้รับผิดชอบอย่างน้อย 1 คน';
    }

    if (!$errors) {
        $data = ['event_date' => $e['event_date'], 'end_date' => $e['end_date'], 'start_time' => $e['start_time'],
                 'end_time' => $e['end_time'], 'title' => mb_substr($e['title'], 0, 255), 'location' => $e['location'] ?: null,
                 'link' => $e['link'] ?: null, 'note' => $e['note'] ?: null];
        $pdo->beginTransaction();
        if ($id) {
            $cols = array_keys($data);
            $pdo->prepare('UPDATE cal_events SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                ->execute([...array_values($data), $id]);
            // เปลี่ยนวัน/เวลา/ชื่อกิจกรรม → แจ้งผู้รับผิดชอบเดิมอีกครั้ง
            $norm = fn($k, $v) => in_array($k, ['start_time', 'end_time'], true) ? substr((string)$v, 0, 5) : (string)$v;
            $changed = false;
            foreach (['event_date', 'end_date', 'start_time', 'end_time', 'title', 'location'] as $k) {
                $changed = $changed || $norm($k, $before[$k]) !== $norm($k, $data[$k]);
            }
            $old = cal_assignees([$id])[$id] ?? [];
            $removed = array_diff($old, $assigned);
            if ($removed) {
                $pdo->exec("DELETE FROM cal_event_users WHERE event_id = $id AND user_id IN (" . implode(',', $removed) . ')');
            }
            if ($changed) {
                $pdo->exec("UPDATE cal_event_users SET assigned_at = NOW() WHERE event_id = $id");
            }
        } else {
            $data['created_by'] = $me['id'];
            $cols = array_keys($data);
            $pdo->prepare('INSERT INTO cal_events (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($data));
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO cal_event_users (event_id, user_id) VALUES (?, ?)');
        foreach ($assigned as $uid) {
            $ins->execute([$id, $uid]);
        }
        $pdo->commit();
        audit_log('cal_save', '#' . $id . ' ' . $data['title'] . ' (' . count($assigned) . ' คน)', CAL_MODULE);
        flash('บันทึกกิจกรรมแล้ว — แจ้งเตือนผู้รับผิดชอบ ' . count($assigned) . ' คน');
        redirect('event_view.php?id=' . $id);
    }
}

$users = cal_users();
$active = 'add';
$pageTitle = $id ? 'แก้ไขกิจกรรม' : 'เพิ่มกิจกรรม';
$pageActions = $id ? '' : '<a href="import.php" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> นำเข้าหลายรายการจาก CSV</a>';
$allDay = $e['start_time'] === null && $e['end_time'] === null;
$headExtra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" class="row g-3" id="evForm">
  <?= csrf_field() ?>
  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header"><strong><i class="bi bi-calendar-plus"></i> รายละเอียดกิจกรรม</strong></div>
      <div class="card-body row g-3">
        <div class="col-12"><label class="form-label">กิจกรรม <span class="text-danger">*</span></label>
          <input name="title" class="form-control" required maxlength="255" value="<?= h($e['title']) ?>" placeholder="เช่น ประชุมประจำเดือน / ลงพื้นที่สำรวจ"></div>
        <div class="col-sm-6"><label class="form-label">วันที่ <span class="text-danger">*</span></label>
          <input type="text" name="event_date" class="form-control thai-date" required value="<?= h($e['event_date']) ?>" placeholder="เลือกวันที่"></div>
        <div class="col-sm-6"><label class="form-label">ถึงวันที่ <small class="text-muted">(ถ้าหลายวัน)</small></label>
          <div class="input-group"><input type="text" name="end_date" class="form-control thai-date" value="<?= h($e['end_date']) ?>" placeholder="-">
            <button type="button" class="btn btn-outline-secondary" data-clear-date="end_date" title="ล้าง"><i class="bi bi-x-lg"></i></button></div></div>
        <div class="col-12">
          <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="all_day" value="1" id="allDay" <?= $allDay ? 'checked' : '' ?>>
            <label class="form-check-label" for="allDay">ทั้งวัน (ไม่ระบุเวลา)</label></div>
          <div class="row g-2" id="timeRow">
            <div class="col-6"><label class="form-label">เวลาเริ่ม</label><div class="input-group"><input type="text" name="start_time" class="form-control thai-time" inputmode="decimal" value="<?= h(cal_time_thai($e['start_time'])) ?>" placeholder="เช่น 09.00"><span class="input-group-text">น.</span></div></div>
            <div class="col-6"><label class="form-label">เวลาสิ้นสุด</label><div class="input-group"><input type="text" name="end_time" class="form-control thai-time" inputmode="decimal" value="<?= h(cal_time_thai($e['end_time'])) ?>" placeholder="เช่น 16.30"><span class="input-group-text">น.</span></div></div>
          </div></div>
        <div class="col-12"><label class="form-label">สถานที่</label><input name="location" class="form-control" maxlength="255" value="<?= h($e['location']) ?>"></div>
        <div class="col-12"><label class="form-label">ลิงก์ประกอบ</label><input name="link" type="url" class="form-control" maxlength="500" value="<?= h($e['link']) ?>" placeholder="https://..."></div>
        <div class="col-12"><label class="form-label">หมายเหตุ</label><textarea name="note" class="form-control" rows="3"><?= h($e['note']) ?></textarea></div>
      </div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><strong><i class="bi bi-people"></i> ผู้รับผิดชอบ <span class="text-danger">*</span></strong>
        <span class="badge text-bg-primary ms-2" id="selCount">0</span></div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2">
          <button type="button" class="btn btn-sm btn-outline-primary" data-add="*"><i class="bi bi-people-fill"></i> เพิ่มทั้งหมด</button>
          <button type="button" class="btn btn-sm btn-outline-primary" data-add="gov"><i class="bi bi-person-badge"></i> เพิ่มข้าราชการ</button>
          <button type="button" class="btn btn-sm btn-outline-primary" data-add="emp"><i class="bi bi-person-vcard"></i> เพิ่มพนักงาน</button>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-clear><i class="bi bi-x-lg"></i> ล้าง</button>
        </div>
        <input type="search" class="form-control form-control-sm mb-2" id="userSearch" placeholder="ค้นหาชื่อ / ตำแหน่ง">
        <div class="cal-userpick border rounded" id="userPick">
          <?php foreach (['gov', 'emp', ''] as $t): $list = array_filter($users, fn($u) => (string)$u['type'] === $t); if (!$list) continue; ?>
            <div class="grp"><?= CAL_STAFF_LABEL[$t] ?> <span class="text-muted fw-normal">(<?= count($list) ?>)</span></div>
            <?php foreach ($list as $u): ?>
              <label class="opt" data-type="<?= h((string)$u['type']) ?>" data-text="<?= h(mb_strtolower($u['full_name'] . ' ' . $u['position'])) ?>">
                <input type="checkbox" class="form-check-input" name="users[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $assigned, true) ? 'checked' : '' ?>>
                <span><?= h($u['full_name']) ?><span class="d-block small text-muted"><?= h($u['position'] ?: '-') ?></span></span>
              </label>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
        <div class="form-text">ข้าราชการ = สถิติจังหวัด / นักวิชาการ… / ผู้อำนวยการ… / เจ้าพนักงาน… · พนักงาน = พนักงานสถิติ / ผู้ช่วยพนักงานสถิติ (แยกตามตำแหน่งในบัญชีผู้ใช้)</div>
      </div>
    </div>
  </div>
  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึก</button>
    <a href="<?= $id ? 'event_view.php?id=' . $id : 'index.php' ?>" class="btn btn-light btn-lg">ยกเลิก</a>
  </div>
</form>
<?php if ($id): ?>
  <form method="post" class="mt-3" data-confirm="ลบกิจกรรม &quot;<?= h($e['title']) ?>&quot;?"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> ลบกิจกรรมนี้</button></form>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<script>
(() => {
  const boxes = () => [...document.querySelectorAll('#userPick input[type=checkbox]')];
  const count = () => {
    document.getElementById('selCount').textContent = boxes().filter(b => b.checked).length + ' คน';
    document.querySelectorAll('#userPick .opt').forEach(o => o.classList.toggle('on', o.querySelector('input').checked));
  };
  document.querySelectorAll('[data-add]').forEach(btn => btn.addEventListener('click', () => {
    const t = btn.dataset.add;
    document.querySelectorAll('#userPick .opt').forEach(o => { if (t === '*' || o.dataset.type === t) o.querySelector('input').checked = true; });
    count();
  }));
  document.querySelector('[data-clear]').addEventListener('click', () => { boxes().forEach(b => b.checked = false); count(); });
  document.getElementById('userPick').addEventListener('change', count);
  document.getElementById('userSearch').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('#userPick .opt').forEach(o => o.hidden = q !== '' && !o.dataset.text.includes(q));
  });
  const allDay = document.getElementById('allDay'), row = document.getElementById('timeRow');
  const sync = () => { row.hidden = allDay.checked; };
  allDay.addEventListener('change', sync); sync(); count();
  document.getElementById('evForm').addEventListener('submit', ev => {
    if (!boxes().some(b => b.checked)) { ev.preventDefault(); alert('กรุณาเลือกผู้รับผิดชอบอย่างน้อย 1 คน'); }
  });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
