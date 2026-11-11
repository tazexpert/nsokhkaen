<?php
/**
 * การจัดเวรกิจกรรม (ผู้ดูแลปฏิทิน)
 *   แสดงว่าถึงคิวข้าราชการคนใด และกลุ่มกิจกรรมใด → กรอกชื่อกิจกรรม วันที่ สถานที่ การแต่งกาย แล้วยืนยัน
 *   ยืนยัน = เพิ่มกิจกรรมลงปฏิทิน (ผู้รับผิดชอบ = ข้าราชการ + สมาชิกกลุ่มที่มีบัญชี → ได้รับการแจ้งเตือน)
 *   ข้าราชการข้ามได้ 1–3 คิว (คนที่ถูกข้ามได้คิวถัดไป) · ลบกิจกรรมในปฏิทิน = คืนคิว
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rota_lib.php';
$me = require_module(CAL_MODULE, 'admin');
$pdo = db();

$f = ['title' => '', 'event_date' => date('Y-m-d'), 'start_time' => '08:30', 'end_time' => '16:30', 'location' => '', 'dress' => '', 'pick' => ''];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)post($k));
    }
    $state = rota_state();
    $choices = array_slice($state['officials'], 0, ROTA_MAX_SKIP + 1);
    $pickIdx = array_search($f['pick'], array_column($choices, 'key'), true);
    $groupNo = $state['groups'][0] ?? null;
    if ($f['title'] === '') {
        $errors[] = 'กรุณาระบุชื่อกิจกรรม';
    }
    if ($f['dress'] === '') {
        $errors[] = 'กรุณาระบุการแต่งกาย';
    }
    if (!DateTime::createFromFormat('!Y-m-d', $f['event_date'])) {
        $errors[] = 'กรุณาเลือกวันที่';
    }
    $st = cal_parse_time($f['start_time']);
    $et = cal_parse_time($f['end_time']);
    if ($st === false || $et === false) {
        $errors[] = 'รูปแบบเวลาไม่ถูกต้อง (เช่น 08.30)';
    } elseif ($st && $et && $et < $st) {
        $errors[] = 'เวลาสิ้นสุดต้องไม่ก่อนเวลาเริ่ม';
    }
    if ($pickIdx === false) {
        $errors[] = 'คิวข้าราชการเปลี่ยนไปแล้ว — กรุณาเลือกข้าราชการอีกครั้ง';
    }
    if ($groupNo === null) {
        $errors[] = 'ยังไม่มีกลุ่มกิจกรรม — เพิ่มที่ "รายชื่อและกลุ่ม"';
    }
    if (!$errors) {
        $official = $choices[$pickIdx];
        $skipped = array_column(array_slice($choices, 0, $pickIdx), 'name');
        $group = $state['members']['groups'][$groupNo];
        $note = 'การแต่งกาย: ' . $f['dress'];             // หมายเหตุในตาราง: การแต่งกายอย่างเดียว (รายชื่ออยู่ในผู้รับผิดชอบ)
        $users = array_values(array_unique(array_filter(array_merge([$official['user_id']], array_column($group, 'user_id')))));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO cal_events (kind, visibility, event_date, start_time, end_time, title, location, note, created_by)
                VALUES ('event', 'public', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$f['event_date'], $st ?: null, $et ?: null, mb_substr($f['title'], 0, 255), $f['location'] ?: null, $note, $me['id']]);
            $eid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT IGNORE INTO cal_event_users (event_id, user_id) VALUES (?, ?)');   // ผู้รับผิดชอบ → แจ้งเตือน
            foreach ($users as $uid) {
                $ins->execute([$eid, $uid]);
            }
            $pdo->prepare('INSERT INTO cal_rota_turns (event_id, official_user_id, official_name, skipped_names, dress, group_no, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$eid, $official['user_id'], $official['name'], $skipped ? implode(', ', $skipped) : null, $f['dress'] ?: null, $groupNo, $me['id']]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        audit_log('cal_rota', '#' . $eid . ' ' . $f['title'] . ' · ' . $official['name'] . ($skipped ? ' (ข้าม ' . implode(', ', $skipped) . ')' : '') . ' · กลุ่ม ' . $groupNo, CAL_MODULE);
        flash('จัดเวรกิจกรรม "' . $f['title'] . '" แล้ว: ' . $official['name'] . ' และกลุ่มที่ ' . $groupNo . ' — เพิ่มลงปฏิทินและแจ้งเตือน ' . count($users) . ' คน');
        redirect('rota');
    }
}

$state = rota_state();
$members = $state['members'];
$turns = array_reverse(rota_turns());
$choices = array_slice($state['officials'], 0, ROTA_MAX_SKIP + 1);
$f['pick'] = in_array($f['pick'], array_column($choices, 'key'), true) ? $f['pick'] : ($choices[0]['key'] ?? '');
$groupNo = $state['groups'][0] ?? null;
$doneOfficials = array_filter($members['officials'], fn($m) => !in_array($m['key'], array_column($state['officials'], 'key'), true));

$active = 'rota';
$pageTitle = 'เวรกิจกรรม';
$pageActions = '<a href="rota_members" class="btn btn-outline-secondary"><i class="bi bi-people"></i> รายชื่อและกลุ่ม</a>';
$headExtra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<div class="row g-3 mb-3" data-reveal>
  <div class="col-lg-6 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><strong><i class="bi bi-person-badge text-primary"></i> ลำดับข้าราชการ</strong>
        <span class="badge text-bg-light border ms-auto">รอบที่ <?= (int)$state['official_round'] ?></span></div>
      <ol class="list-group list-group-flush list-group-numbered rota-list">
        <?php foreach ($state['officials'] as $i => $m): ?>
          <li class="list-group-item d-flex align-items-center<?= $i === 0 ? ' rota-next' : '' ?>">
            <span class="ms-2"><?= h($m['name']) ?></span>
            <?php if ($i === 0): ?><span class="badge text-bg-primary ms-auto">ถึงคิว</span>
            <?php elseif ($i <= ROTA_MAX_SKIP): ?><span class="small text-muted ms-auto">คิวถัดไป</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php foreach ($doneOfficials as $m): ?>
          <li class="list-group-item d-flex align-items-center text-muted rota-done"><span class="ms-2 text-decoration-line-through"><?= h($m['name']) ?></span>
            <span class="small ms-auto"><i class="bi bi-check2"></i> ได้แล้วในรอบนี้</span></li>
        <?php endforeach; ?>
        <?php if (!$members['officials']): ?><li class="list-group-item text-muted">ยังไม่มีรายชื่อ — เพิ่มที่ "รายชื่อและกลุ่ม"</li><?php endif; ?>
      </ol>
    </div>
  </div>
  <div class="col-lg-6 reveal-item" style="--d:70ms">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><strong><i class="bi bi-people-fill text-success"></i> กลุ่มกิจกรรม</strong>
        <span class="badge text-bg-light border ms-auto">รอบที่ <?= (int)$state['group_round'] ?></span></div>
      <div class="card-body">
        <?php foreach ($members['groups'] as $g => $list): $pos = array_search($g, $state['groups'], true); ?>
          <div class="rota-group mb-2<?= $pos === 0 ? ' rota-next' : ($pos === false ? ' rota-done' : '') ?>">
            <div class="d-flex align-items-center mb-1"><strong>กลุ่มที่ <?= (int)$g ?></strong>
              <?= $pos === 0 ? '<span class="badge text-bg-success ms-auto">ถึงคิว</span>' : ($pos === false ? '<span class="small text-muted ms-auto"><i class="bi bi-check2"></i> ได้แล้วในรอบนี้</span>' : '<span class="small text-muted ms-auto">คิวที่ ' . ($pos + 1) . '</span>') ?></div>
            <div class="small"><?php foreach ($list as $m): ?><span class="rota-chip"><?= h($m['name']) ?><?= $m['user_id'] ? '' : ' <i class="bi bi-person-dash" title="ไม่มีบัญชีในระบบ (ไม่ได้รับแจ้งเตือน)"></i>' ?></span><?php endforeach; ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$members['groups']): ?><div class="text-muted">ยังไม่มีกลุ่มกิจกรรม — เพิ่มที่ "รายชื่อและกลุ่ม"</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($choices && $groupNo !== null): ?>
<form method="post" class="card mb-3" id="rotaForm">
  <?= csrf_field() ?>
  <div class="card-header"><strong><i class="bi bi-calendar-plus"></i> จัดเวรกิจกรรม</strong></div>
  <div class="card-body row g-3">
    <div class="col-lg-6">
      <div class="row g-3">
        <div class="col-12"><label class="form-label">ชื่อกิจกรรม <span class="text-danger">*</span></label>
          <input name="title" class="form-control" maxlength="255" required value="<?= h($f['title']) ?>" placeholder="เช่น กิจกรรมจิตอาสา"></div>
        <div class="col-sm-6"><label class="form-label">วันที่ <span class="text-danger">*</span></label>
          <input type="text" name="event_date" class="form-control thai-date" required value="<?= h($f['event_date']) ?>"></div>
        <div class="col-6 col-sm-3"><label class="form-label">เวลาเริ่ม</label><input type="text" name="start_time" class="form-control thai-time" value="<?= h(str_replace(':', '.', substr($f['start_time'], 0, 5))) ?>"></div>
        <div class="col-6 col-sm-3"><label class="form-label">เวลาสิ้นสุด</label><input type="text" name="end_time" class="form-control thai-time" value="<?= h(str_replace(':', '.', substr($f['end_time'], 0, 5))) ?>"></div>
        <div class="col-12"><label class="form-label">สถานที่ <span class="text-danger">*</span></label>
          <input name="location" class="form-control" maxlength="255" required value="<?= h($f['location']) ?>"></div>
        <div class="col-12"><label class="form-label">การแต่งกาย <span class="text-danger">*</span></label>
          <input name="dress" class="form-control" maxlength="255" required value="<?= h($f['dress']) ?>" placeholder="เช่น ชุดกีฬาสีขาว / เครื่องแบบปกติขาว"></div>
      </div>
    </div>
    <div class="col-lg-6">
      <label class="form-label">ข้าราชการ <span class="small text-muted">— เลือกข้ามได้ 1–<?= ROTA_MAX_SKIP ?> คิว (คนที่ถูกข้ามได้คิวถัดไป)</span></label>
      <div class="list-group mb-3">
        <?php foreach ($choices as $i => $m): ?>
          <label class="list-group-item d-flex align-items-center gap-2">
            <input class="form-check-input m-0" type="radio" name="pick" value="<?= h($m['key']) ?>" data-name="<?= h($m['name']) ?>" data-skip="<?= h(implode(', ', array_column(array_slice($choices, 0, $i), 'name'))) ?>" <?= $f['pick'] === $m['key'] ? 'checked' : '' ?>>
            <span><?= h($m['name']) ?></span>
            <span class="ms-auto small <?= $i ? 'text-warning-emphasis' : 'text-primary fw-semibold' ?>"><?= $i ? 'ข้าม ' . $i . ' คิว' : 'ถึงคิว' ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="form-label">กลุ่มกิจกรรม</label>
      <div class="border rounded p-2 mb-2"><strong>กลุ่มที่ <?= (int)$groupNo ?></strong>
        <div class="small"><?= h(rota_group_names($members, $groupNo)) ?></div></div>
      <div class="small text-muted">ยืนยันแล้วเพิ่มกิจกรรมลงปฏิทิน ผู้รับผิดชอบ = ข้าราชการ + สมาชิกกลุ่ม และแจ้งเตือนแต่ละคน
        · ยกเลิกกิจกรรม (ลบในปฏิทิน) = คืนคิว</div>
    </div>
  </div>
  <div class="card-footer text-end"><button class="btn btn-primary"><i class="bi bi-check2-circle"></i> ยืนยันจัดเวรและเพิ่มลงปฏิทิน</button></div>
</form>
<?php endif; ?>

<div class="card">
  <div class="card-header"><strong><i class="bi bi-clock-history"></i> เวรกิจกรรมที่จัดแล้ว (<?= count($turns) ?>)</strong></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>วันที่</th><th>กิจกรรม</th><th>สถานที่</th><th>การแต่งกาย</th><th>ข้าราชการ</th><th>กลุ่ม</th></tr></thead>
      <tbody>
      <?php foreach ($turns as $t): ?>
        <tr>
          <td class="text-nowrap"><?= h(cal_date_text($t['event_date'])) ?><?= $t['start_time'] ? '<div class="small text-muted">' . h(cal_time_text($t)) . '</div>' : '' ?></td>
          <td><a href="event_view?id=<?= (int)$t['event_id'] ?>"><?= h($t['title']) ?></a></td>
          <td class="small"><?= h($t['location'] ?: '-') ?></td>
          <td class="small"><?= h($t['dress'] ?: '-') ?></td>
          <td><?= h($t['official_name']) ?><?= $t['skipped_names'] ? '<div class="small text-warning-emphasis">ข้าม: ' . h($t['skipped_names']) . '</div>' : '' ?></td>
          <td class="text-nowrap">กลุ่มที่ <?= (int)$t['group_no'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$turns): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีการจัดเวรกิจกรรม</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<style>
.rota-list .rota-next{background:var(--bs-primary-bg-subtle);font-weight:600}
.rota-group{border:1px solid var(--bs-border-color);border-radius:.5rem;padding:.5rem .75rem}
.rota-group.rota-next{border-color:var(--bs-success);background:var(--bs-success-bg-subtle)}
.rota-group.rota-done{opacity:.6}
.rota-chip{display:inline-block;background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:1rem;padding:0 .55rem;margin:0 .25rem .25rem 0}
</style>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js" integrity="sha384-E6OVFg1zUc/pckGxry0YZ6VeZ1E17l7Jl8ZQx58jbEfxxkz9LqknQhiIrA9zg/8b" crossorigin="anonymous"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<script>
document.getElementById('rotaForm')?.addEventListener('submit', e => {
  const p = document.querySelector('[name=pick]:checked');
  const msg = 'ยืนยันจัดเวรกิจกรรม "' + document.querySelector('[name=title]').value + '"\n'
    + 'ข้าราชการ: ' + p.dataset.name + (p.dataset.skip ? '\n(ข้าม: ' + p.dataset.skip + ' — ได้คิวถัดไป)' : '')
    + '\nกลุ่มที่ <?= (int)$groupNo ?>\n\nเพิ่มลงปฏิทินและแจ้งเตือนผู้รับผิดชอบ?';
  if (!confirm(msg)) e.preventDefault();
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
