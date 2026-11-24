<?php
/**
 * การจัดเวรกิจกรรม (ผู้ดูแลปฏิทิน)
 *   แสดงว่าถึงคิวข้าราชการคนใด และกลุ่มกิจกรรมใด → กรอกชื่อกิจกรรม วันที่ สถานที่ การแต่งกาย แล้วยืนยัน
 *   ยืนยัน = เพิ่มกิจกรรมลงปฏิทิน (ผู้รับผิดชอบ = ข้าราชการ + สมาชิกกลุ่มที่มีบัญชี → ได้รับการแจ้งเตือน)
 *   ข้าราชการข้ามได้ 1–3 คิว (คนที่ถูกข้ามได้คิวถัดไป) · ลบกิจกรรมในปฏิทิน = คืนคิว
 *   กล่องแรก: สถิติจังหวัดเข้าร่วมด้วยหรือไม่ — ผู้รักษาการที่ถึงคิวข้าราชการพอดี = นับเป็นคิวแล้ว
 *            สถิติจังหวัดเข้าร่วม = ไม่ต้องมีข้าราชการไปด้วยก็ได้ (pick "-") คิวข้าราชการคงเดิม (ยกเว้นผู้รักษาการถึงคิวพอดี = นับเป็นคิว)
 *   รูปแบบ (mode): one = ข้าราชการ 1 คน + กลุ่มกิจกรรม (เดิม) · multi = เฉพาะข้าราชการ เลือกได้หลายคน (นับคิวทุกคนที่เลือก)
 *                  all = ข้าราชการทุกคน (ไม่เลือกกลุ่ม · ไม่กระทบคิว) — multi/all ต้องรัน migration 20261214
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rota_lib.php';
$me = require_module(CAL_MODULE, 'editor');           // ผู้บันทึกกิจกรรมจัดเวรได้ · แก้รายชื่อ/กลุ่ม (rota_members) เฉพาะผู้ดูแลปฏิทิน
$pdo = db();

$f = ['title' => '', 'event_date' => date('Y-m-d'), 'start_time' => '08:30', 'end_time' => '16:30', 'location' => '', 'dress' => '', 'pick' => '', 'chief' => '', 'mode' => 'one'];
$errors = [];
$hasMulti = rota_has_multi();
$picks = [];
$hasChief = rota_has_chief();
$chief = $hasChief ? rota_chief() : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)post($k));
    }
    $f['mode'] = $hasMulti && in_array($f['mode'], ['multi', 'all'], true) ? $f['mode'] : 'one';
    $picks = array_values(array_unique(array_map('strval', (array)($_POST['picks'] ?? []))));
    $state = rota_state();
    $chiefJoins = $chief && $f['chief'] === '1';
    [$choices, $chiefCounted] = rota_choices($state['officials'], $chief, $chiefJoins);
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
    $byKey = array_column($state['members']['officials'], null, 'key');
    $chiefKey = $chiefJoins ? rota_key($chief['user_id'], $chief['name']) : '';
    $offList = [];                                    // ข้าราชการของเวรนี้ (multi/all)
    $noOfficial = $f['mode'] === 'one' && $chiefJoins && $f['pick'] === '-';   // สถิติจังหวัดไปโดยไม่มีข้าราชการ
    if ($f['mode'] === 'one') {
        if ($pickIdx === false && !$noOfficial) {
            $errors[] = 'คิวข้าราชการเปลี่ยนไปแล้ว — กรุณาเลือกข้าราชการอีกครั้ง';
        }
        if ($groupNo === null) {
            $errors[] = 'ยังไม่มีกลุ่มกิจกรรม — เพิ่มที่ "รายชื่อและกลุ่ม"';
        }
    } elseif ($f['mode'] === 'multi') {
        foreach ($picks as $k) {
            if (isset($byKey[$k]) && $k !== $chiefKey) {
                $offList[] = $byKey[$k];
            }
        }
        if (!$offList) {
            $errors[] = 'กรุณาเลือกข้าราชการอย่างน้อย 1 คน';
        }
    } else {
        $offList = array_values(array_filter($state['members']['officials'], fn($m) => $m['key'] !== $chiefKey));
        $chiefCounted = false;                        // ข้าราชการทุกคน = ไม่กระทบคิว
    }
    if (!$errors) {
        $one = $f['mode'] === 'one';
        $official = $noOfficial ? ['user_id' => null, 'name' => ''] : ($one ? $choices[$pickIdx] : ($f['mode'] === 'multi' ? $offList[0] : ['user_id' => null, 'name' => 'ข้าราชการทุกคน']));
        $skipped = $one && !$noOfficial ? array_column(array_slice($choices, 0, $pickIdx), 'name') : [];
        $groupNo = $one ? $groupNo : 0;
        $group = $one ? $state['members']['groups'][$groupNo] : [];
        $note = 'การแต่งกาย: ' . $f['dress'];             // หมายเหตุในตาราง: การแต่งกายอย่างเดียว (รายชื่ออยู่ในผู้รับผิดชอบ)
        $users = array_values(array_unique(array_filter(array_merge([$chiefJoins ? $chief['user_id'] : null, $official['user_id']], array_column($offList, 'user_id'), array_column($group, 'user_id')))));
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
            $tid = (int)$pdo->lastInsertId();
            if ($chiefJoins) {
                $pdo->prepare('UPDATE cal_rota_turns SET chief_user_id = ?, chief_name = ?, chief_counted = ? WHERE event_id = ?')
                    ->execute([$chief['user_id'], $chief['name'], $chiefCounted ? 1 : 0, $eid]);
            }
            if (!$one) {                                  // ข้าราชการหลายคน (นับคิว) / ทุกคน (ไม่นับคิว)
                $insO = $pdo->prepare('INSERT INTO cal_rota_turn_officials (turn_id, sort_order, user_id, name, counted) VALUES (?, ?, ?, ?, ?)');
                foreach ($offList as $i => $o) {
                    $insO->execute([$tid, $i + 1, $o['user_id'], $o['name'], $f['mode'] === 'multi' ? 1 : 0]);
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        $chiefText = $chiefJoins ? ' · สถิติจังหวัด ' . $chief['name'] . ($chiefCounted ? ' (นับเป็นคิวข้าราชการแล้ว)' : '') : '';
        $offText = $noOfficial ? '- (ไม่มีข้าราชการไปด้วย)' : ($one ? $official['name'] : ($f['mode'] === 'all' ? 'ทุกคน (' . count($offList) . ' คน)' : implode(', ', array_column($offList, 'name'))));
        audit_log('cal_rota', '#' . $eid . ' ' . $f['title'] . $chiefText . ' · ' . $offText . ($skipped ? ' (ข้าม ' . implode(', ', $skipped) . ')' : '') . ($groupNo ? ' · กลุ่ม ' . $groupNo : ' · ไม่เลือกกลุ่ม'), CAL_MODULE);
        flash('จัดเวรกิจกรรม "' . $f['title'] . '" แล้ว: ' . ($chiefJoins ? ltrim($chiefText, ' ·') . ' · ' : '') . 'ข้าราชการ ' . $offText . ($groupNo ? ' และกลุ่มที่ ' . $groupNo : '') . ' — เพิ่มลงปฏิทินและแจ้งเตือน ' . count($users) . ' คน');
        redirect('rota?line=' . $eid);                    // เปิดข้อความไลน์ของเวรที่เพิ่งจัดให้ทันที
    }
}

$state = rota_state();
$members = $state['members'];
$turns = array_reverse(rota_turns());
$chiefKey = $chief ? rota_key($chief['user_id'], $chief['name']) : '';
$chiefPos = $chief ? array_search($chiefKey, array_column($state['officials'], 'key'), true) : false;
$choices = array_slice($state['officials'], 0, ROTA_MAX_SKIP + 2);       // +1 เผื่อผู้รักษาการถูกตัดออกเมื่อเข้าร่วมในฐานะสถิติจังหวัด (JS เลือกแสดง)
[$visible] = rota_choices($state['officials'], $chief, $chief && $f['chief'] === '1');
$f['pick'] = in_array($f['pick'], array_column($visible, 'key'), true) || ($f['pick'] === '-' && $f['chief'] === '1') ? $f['pick'] : ($visible[0]['key'] ?? '');
$groupNo = $state['groups'][0] ?? null;
$doneOfficials = array_filter($members['officials'], fn($m) => !in_array($m['key'], array_column($state['officials'], 'key'), true));

$active = 'rota';
$pageTitle = 'เวรกิจกรรม';
$pageActions = !cal_can('admin') ? '' : '<a href="rota_members" class="btn btn-outline-secondary"><i class="bi bi-people"></i> รายชื่อและกลุ่ม</a>';
$headExtra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php ob_start(); // ลำดับข้าราชการ + กลุ่มกิจกรรม — แสดงในแบบฟอร์มจัดเวรกิจกรรม (ไม่มีรายชื่อข้าราชการ = แสดงลำพัง) ?>
<div class="row g-3 rota-queue" data-reveal>
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
<?php $queueHtml = ob_get_clean(); ?>
<?php if (!$members['officials']): ?><div class="mb-3"><?= $queueHtml ?></div><?php endif; ?>

<?php if ($members['officials']): ?>
<?= cal_letter_reader_box('rotaForm', [], true, 'อ่านได้: ชื่อกิจกรรม ("เข้าร่วม…" / "๑. …") · วันที่ · เวลา · สถานที่ ("ณ …") · การแต่งกาย — ผู้ร่วมกิจกรรมจัดตามคิวเวรด้านล่าง') ?>
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
          <textarea name="dress" class="form-control" rows="3" maxlength="255" required placeholder="เช่น ชุดกีฬาสีขาว / เครื่องแบบปกติขาว (ขึ้นบรรทัดใหม่ได้)"><?= h($f['dress']) ?></textarea></div>
        <div class="col-12"><label class="form-label">ลำดับคิว</label>
          <div class="row g-2 rota-mini" data-reveal>
            <div class="col-sm-6 reveal-item"><div class="border rounded p-2 h-100">
              <div class="d-flex align-items-center mb-1"><i class="bi bi-person-badge text-primary me-1"></i><strong>ข้าราชการ</strong>
                <span class="text-muted ms-auto">รอบที่ <?= (int)$state['official_round'] ?></span></div>
              <ol class="mb-0 ps-3">
                <?php foreach ($state['officials'] as $i => $m): ?>
                  <li<?= $i === 0 ? ' class="fw-semibold text-primary"' : '' ?>><?= h($m['name']) ?><?= $i === 0 ? ' <span class="badge text-bg-primary">ถึงคิว</span>' : '' ?></li>
                <?php endforeach; ?>
                <?php foreach ($doneOfficials as $m): ?>
                  <li class="text-muted" title="ได้แล้วในรอบนี้"><s><?= h($m['name']) ?></s> <i class="bi bi-check2"></i></li>
                <?php endforeach; ?>
              </ol>
            </div></div>
            <div class="col-sm-6 reveal-item" style="--d:70ms"><div class="border rounded p-2 h-100">
              <div class="d-flex align-items-center mb-1"><i class="bi bi-people-fill text-success me-1"></i><strong>กลุ่มกิจกรรม</strong>
                <span class="text-muted ms-auto">รอบที่ <?= (int)$state['group_round'] ?></span></div>
              <?php foreach ($members['groups'] as $g => $list): $pos = array_search($g, $state['groups'], true); ?>
                <div class="d-flex align-items-center<?= $pos === 0 ? ' fw-semibold text-success' : ($pos === false ? ' text-muted' : '') ?>" title="<?= h(implode(', ', array_column($list, 'name'))) ?>">
                  <span>กลุ่มที่ <?= (int)$g ?> <span class="fw-normal text-muted">(<?= count($list) ?> คน)</span></span>
                  <span class="ms-auto"><?= $pos === 0 ? '<span class="badge text-bg-success">ถึงคิว</span>' : ($pos === false ? '<i class="bi bi-check2"></i> ได้แล้ว' : 'คิวที่ ' . ($pos + 1)) ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (!$members['groups']): ?><div class="text-muted">ยังไม่มีกลุ่มกิจกรรม</div><?php endif; ?>
            </div></div>
          </div></div>
      </div>
    </div>
    <div class="col-lg-6">
      <?php if ($chief): ?>
      <label class="form-label">สถิติจังหวัด <span class="small text-muted">— เข้าร่วมกิจกรรมด้วยหรือไม่</span></label>
      <div class="border rounded p-2 mb-3 rota-chief">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <div class="me-auto"><strong><?= h($chief['name']) ?></strong>
            <div class="small text-muted"><?= h($chief['label']) ?></div></div>
          <div class="btn-group btn-group-sm" role="group">
            <input type="radio" class="btn-check" name="chief" id="chief0" value="" <?= $f['chief'] === '1' ? '' : 'checked' ?>>
            <label class="btn btn-outline-secondary" for="chief0">ไม่เข้าร่วม</label>
            <input type="radio" class="btn-check" name="chief" id="chief1" value="1" <?= $f['chief'] === '1' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="chief1"><i class="bi bi-check2"></i> เข้าร่วมด้วย</label>
          </div>
        </div>
        <?php if ($chiefPos === 0): ?>
          <div class="small text-success mt-2 d-none" id="chiefNote"><i class="bi bi-info-circle"></i> <?= h($chief['name']) ?> ถึงคิวข้าราชการพอดี
            — เข้าร่วมในฐานะ<?= $chief['acting'] ? 'รักษาการ' : '' ?>สถิติจังหวัด นับเป็นคิวแล้ว · ไม่ต้องมีข้าราชการไปด้วยก็ได้ (คิวต่อไปเป็นคนถัดไป)</div>
        <?php elseif ($chiefPos !== false): ?>
          <div class="small text-muted mt-2 d-none" id="chiefNote"><i class="bi bi-info-circle"></i> เข้าร่วมในฐานะสถิติจังหวัด ไม่ต้องมีข้าราชการไปด้วยก็ได้ · คิวข้าราชการคงเดิม (ยังไม่ถึงคิวของ<?= h($chief['name']) ?>)</div>
        <?php else: ?>
          <div class="small text-muted mt-2 d-none" id="chiefNote"><i class="bi bi-info-circle"></i> เข้าร่วมในฐานะสถิติจังหวัด ไม่ต้องมีข้าราชการไปด้วยก็ได้ · คิวข้าราชการคงเดิม</div>
        <?php endif; ?>
      </div>
      <?php elseif (!$hasChief): ?>
      <div class="alert alert-warning small py-2">ตัวเลือก "สถิติจังหวัดเข้าร่วมด้วย" ต้องรัน migration <code>20261211_cal_rota_chief.sql</code> ก่อน</div>
      <?php endif; ?>
      <?php if ($hasMulti): ?>
      <label class="form-label">รูปแบบ</label>
      <div class="btn-group w-100 mb-3 flex-wrap" role="group" id="rotaMode">
        <?php foreach (['one' => ['bi-person-plus', 'ข้าราชการ 1 คน + กลุ่มกิจกรรม'], 'multi' => ['bi-people', 'เฉพาะข้าราชการ (หลายคน)'], 'all' => ['bi-people-fill', 'ข้าราชการทุกคน']] as $k => [$ic, $lb]): ?>
          <input type="radio" class="btn-check" name="mode" id="mode_<?= $k ?>" value="<?= $k ?>" <?= $f['mode'] === $k ? 'checked' : '' ?><?= $k === 'one' && $groupNo === null ? ' disabled' : '' ?>>
          <label class="btn btn-outline-primary btn-sm" for="mode_<?= $k ?>"><i class="bi <?= $ic ?>"></i> <?= $lb ?></label>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="alert alert-warning small py-2">เลือกข้าราชการหลายคน / ข้าราชการทุกคน ต้องรัน migration <code>20261214_cal_rota_multi.sql</code> ก่อน</div>
      <?php endif; ?>
      <div data-mode="one">
      <label class="form-label">ข้าราชการ <span class="small text-muted">— เลือกข้ามได้ 1–<?= ROTA_MAX_SKIP ?> คิว (คนที่ถูกข้ามได้คิวถัดไป)</span></label>
      <div class="list-group mb-3" id="officialChoices">
        <?php if ($chief): ?>
          <label class="list-group-item d-flex align-items-center gap-2 d-none" data-none="1">
            <input class="form-check-input m-0" type="radio" name="pick" value="-" data-name="" <?= $f['pick'] === '-' ? 'checked' : '' ?>>
            <span>ไม่มีข้าราชการไปด้วย <span class="small text-muted">(สถิติจังหวัดไปแทน)</span></span>
            <span class="ms-auto small text-success"><?= $chiefPos === 0 ? 'นับเป็นคิวของ' . h(rota_first_name($chief['name'])) : 'คิวข้าราชการคงเดิม' ?></span>
          </label>
        <?php endif; ?>
        <?php foreach ($choices as $i => $m): ?>
          <label class="list-group-item d-flex align-items-center gap-2" data-key="<?= h($m['key']) ?>"<?= $m['key'] === $chiefKey ? ' data-chief="1"' : '' ?>>
            <input class="form-check-input m-0" type="radio" name="pick" value="<?= h($m['key']) ?>" data-name="<?= h($m['name']) ?>" <?= $f['pick'] === $m['key'] ? 'checked' : '' ?>>
            <span><?= h($m['name']) ?></span>
            <span class="ms-auto small rota-q"></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="form-label">กลุ่มกิจกรรม</label>
      <?php if ($groupNo !== null): ?>
      <div class="border rounded p-2 mb-2"><strong>กลุ่มที่ <?= (int)$groupNo ?></strong>
        <div class="small"><?= h(rota_group_names($members, $groupNo)) ?></div></div>
      <?php else: ?><div class="text-muted small mb-2">ยังไม่มีกลุ่มกิจกรรม — เพิ่มที่ "รายชื่อและกลุ่ม"</div><?php endif; ?>
      </div>
      <?php if ($hasMulti): $doneKeys = array_column($doneOfficials, 'key'); ?>
      <div data-mode="multi" class="d-none">
        <label class="form-label">ข้าราชการ <span class="small text-muted">— เลือกได้หลายคน (นับเป็นคิวของทุกคนที่เลือก) · ไม่เลือกกลุ่มเจ้าหน้าที่</span></label>
        <div class="list-group mb-3" id="multiChoices">
          <?php foreach (array_merge($state['officials'], $doneOfficials) as $i => $m): $done = in_array($m['key'], $doneKeys, true); ?>
            <label class="list-group-item d-flex align-items-center gap-2" data-key="<?= h($m['key']) ?>"<?= $m['key'] === $chiefKey ? ' data-chief="1"' : '' ?>>
              <input class="form-check-input m-0" type="checkbox" name="picks[]" value="<?= h($m['key']) ?>" data-name="<?= h($m['name']) ?>" <?= in_array($m['key'], $picks, true) ? 'checked' : '' ?>>
              <span><?= h($m['name']) ?></span>
              <span class="ms-auto small <?= $done ? 'text-muted' : ($i === 0 ? 'text-primary fw-semibold' : 'text-muted') ?>"><?= $done ? 'ได้แล้วในรอบนี้' : ($i === 0 ? 'ถึงคิว' : 'คิวที่ ' . ($i + 1)) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div data-mode="all" class="d-none">
        <label class="form-label">ข้าราชการทุกคน <span class="small text-muted">— ไม่เลือกกลุ่มเจ้าหน้าที่ · ไม่กระทบลำดับคิว</span></label>
        <div class="border rounded p-2 mb-3 small"><?php foreach ($members['officials'] as $m): ?><span class="rota-chip"><?= h($m['name']) ?></span><?php endforeach; ?></div>
      </div>
      <?php endif; ?>
      <div class="small text-muted">ยืนยันแล้วเพิ่มกิจกรรมลงปฏิทิน ผู้รับผิดชอบ = ข้าราชการ (+ สมาชิกกลุ่ม) และแจ้งเตือนแต่ละคน
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
      <thead><tr><th>วันที่</th><th>กิจกรรม</th><th>สถานที่</th><th>การแต่งกาย</th><th>สถิติจังหวัด</th><th>ข้าราชการ</th><th>กลุ่ม</th><th class="text-end">ไลน์กลุ่ม</th></tr></thead>
      <tbody>
      <?php foreach ($turns as $t): ?>
        <tr>
          <td class="text-nowrap"><?= h(cal_date_text($t['event_date'])) ?><?= $t['start_time'] ? '<div class="small text-muted">' . h(cal_time_text($t)) . '</div>' : '' ?></td>
          <td><a href="event_view?id=<?= (int)$t['event_id'] ?>"><?= h($t['title']) ?></a></td>
          <td class="small"><?= h($t['location'] ?: '-') ?></td>
          <td class="small"><?= h($t['dress'] ?: '-') ?></td>
          <td class="small"><?= !empty($t['chief_name']) ? h($t['chief_name']) . (!empty($t['chief_counted']) ? '<div class="text-success">นับเป็นคิวข้าราชการแล้ว</div>' : '') : '<span class="text-muted">-</span>' ?></td>
          <td><?php if (!empty($t['all_officials'])): ?>ข้าราชการทุกคน <span class="small text-muted">(<?= count($t['officials']) ?> คน · ไม่นับคิว)</span>
            <?php elseif (!empty($t['officials'])): ?><?= h(implode(', ', array_column($t['officials'], 'name'))) ?>
            <?php elseif ($t['official_name'] === ''): ?><span class="text-muted">- ไม่มีข้าราชการ</span>
            <?php else: ?><?= h($t['official_name']) ?><?php endif; ?><?= $t['skipped_names'] ? '<div class="small text-warning-emphasis">ข้าม: ' . h($t['skipped_names']) . '</div>' : '' ?></td>
          <td class="text-nowrap"><?= (int)$t['group_no'] ? 'กลุ่มที่ ' . (int)$t['group_no'] : '<span class="text-muted">ไม่เลือกกลุ่ม</span>' ?></td>
          <td class="text-end"><button type="button" class="btn btn-sm btn-success text-nowrap" data-event="<?= (int)$t['event_id'] ?>" data-line="<?= h(rota_line_text($t, $members)) ?>" data-title="<?= h($t['title']) ?>"><i class="bi bi-chat-dots"></i> ข้อความไลน์</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$turns): ?><tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีการจัดเวรกิจกรรม</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<!-- ข้อความสำหรับวางในไลน์กลุ่ม -->
<div class="modal fade" id="lineModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-chat-dots text-success"></i> ข้อความไลน์กลุ่ม</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
  <div class="modal-body"><div class="small text-muted mb-1" id="lineTitle"></div>
    <textarea class="form-control" id="lineText" rows="14" style="font-size:.95rem"></textarea>
    <div class="small text-muted mt-1">แก้ไขข้อความได้ก่อนคัดลอก</div></div>
  <div class="modal-footer"><span class="small text-success me-auto d-none" id="lineDone"><i class="bi bi-check2-circle"></i> คัดลอกแล้ว — ไปวางในไลน์กลุ่มได้เลย</span>
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
    <button type="button" class="btn btn-success" id="lineCopy"><i class="bi bi-clipboard"></i> คัดลอกข้อความ</button></div>
</div></div></div>
<style>
.rota-list .rota-next{background:var(--bs-primary-bg-subtle);font-weight:600}
.rota-group{border:1px solid var(--bs-border-color);border-radius:.5rem;padding:.5rem .75rem}
.rota-group.rota-next{border-color:var(--bs-success);background:var(--bs-success-bg-subtle)}
.rota-group.rota-done{opacity:.6}
#officialChoices>.d-none:first-child+.list-group-item,#officialChoices>.d-none:first-child+.d-none+.list-group-item{border-top-width:var(--bs-list-group-border-width);border-top-left-radius:inherit;border-top-right-radius:inherit}
.rota-chief:has(#chief1:checked){border-color:var(--bs-primary)!important;background:var(--bs-primary-bg-subtle)}
.rota-chip{display:inline-block;background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:1rem;padding:0 .55rem;margin:0 .25rem .25rem 0}
</style>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js" integrity="sha384-E6OVFg1zUc/pckGxry0YZ6VeZ1E17l7Jl8ZQx58jbEfxxkz9LqknQhiIrA9zg/8b" crossorigin="anonymous"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/letter-reader.js')) ?>"></script>
<script>
// สถิติจังหวัดเข้าร่วม → ตัดผู้รักษาการออกจากตัวเลือกข้าราชการ (ถึงคิวพอดี = นับเป็นคิวแล้ว) · แสดงถึงคิว + ข้ามได้ <?= ROTA_MAX_SKIP ?> คิว
const rotaChoices = ev => {
  const joins = document.getElementById('chief1')?.checked;
  document.getElementById('chiefNote')?.classList.toggle('d-none', !joins);
  const none = document.querySelector('#officialChoices [data-none]');   // ไม่มีข้าราชการไปด้วย (เฉพาะสถิติจังหวัดเข้าร่วม)
  if (none) {
    none.classList.toggle('d-none', !joins);
    const r = none.querySelector('input');
    if (!joins) r.checked = false; else if (ev) r.checked = true;      // กดเลือก "เข้าร่วมด้วย" = ค่าเริ่มต้นไม่มีข้าราชการ
  }
  let n = 0;
  const shown = [];
  document.querySelectorAll('#officialChoices [data-key]').forEach(el => {
    const show = !(joins && el.dataset.chief) && n <= <?= ROTA_MAX_SKIP ?>;
    el.classList.toggle('d-none', !show);
    const q = el.querySelector('.rota-q'), r = el.querySelector('input');
    if (show) {
      q.textContent = n ? 'ข้าม ' + n + ' คิว' : 'ถึงคิว';
      q.className = 'ms-auto small rota-q ' + (n ? 'text-warning-emphasis' : 'text-primary fw-semibold');
      r.dataset.skip = shown.join(', ');
      shown.push(r.dataset.name);
      n++;
    } else if (r.checked) {
      r.checked = false;
    }
  });
  if (!document.querySelector('#officialChoices input:checked')) document.querySelector('#officialChoices [data-key]:not(.d-none) input')?.click();
};
// ปุ่ม "ข้อความไลน์" → หน้าต่างข้อความ + คัดลอก (clipboard API · ไม่รองรับ = เลือกข้อความแล้ว execCommand)
document.querySelectorAll('[data-line]').forEach(b => b.addEventListener('click', () => {
  document.getElementById('lineText').value = b.dataset.line;
  document.getElementById('lineTitle').textContent = b.dataset.title;
  document.getElementById('lineDone').classList.add('d-none');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('lineModal')).show();
}));
document.getElementById('lineCopy').addEventListener('click', async () => {
  const ta = document.getElementById('lineText');
  try { await navigator.clipboard.writeText(ta.value); } catch (e) { ta.select(); document.execCommand('copy'); }
  document.getElementById('lineDone').classList.remove('d-none');
});
const lineEv = new URLSearchParams(location.search).get('line');
if (lineEv) document.querySelector('[data-line][data-event="' + CSS.escape(lineEv) + '"]')?.click();
document.querySelectorAll('[name=chief]').forEach(r => r.addEventListener('change', rotaChoices));
rotaChoices();
// รูปแบบการจัดเวร: แสดงส่วนของรูปแบบที่เลือก · เฉพาะข้าราชการ: ซ่อนผู้รักษาการเมื่อเข้าร่วมในฐานะสถิติจังหวัด
const rotaMode = () => (document.querySelector('[name=mode]:checked') || { value: 'one' }).value;
const syncMode = () => {
  const m = rotaMode(), joins = document.getElementById('chief1')?.checked;
  document.querySelectorAll('[data-mode]').forEach(el => el.classList.toggle('d-none', el.dataset.mode !== m));
  document.querySelectorAll('#multiChoices [data-chief]').forEach(el => { el.classList.toggle('d-none', !!joins); if (joins) el.querySelector('input').checked = false; });
  document.querySelectorAll('#officialChoices input').forEach(r => r.required = m === 'one');
};
document.querySelectorAll('[name=mode], [name=chief]').forEach(r => r.addEventListener('change', syncMode));
syncMode();
document.getElementById('rotaForm')?.addEventListener('submit', e => {
  const m = rotaMode(), c = document.getElementById('chief1');
  let who;
  if (m === 'one') {
    const p = document.querySelector('[name=pick]:checked');
    who = p.value === '-' ? 'ข้าราชการ: - (ไม่มีข้าราชการไปด้วย · <?= $chiefPos === 0 ? 'นับเป็นคิวของ' . h(rota_first_name($chief['name'] ?? '')) : 'คิวคงเดิม' ?>)\nกลุ่มที่ <?= (int)$groupNo ?>' : 'ข้าราชการ: ' + p.dataset.name + (p.dataset.skip ? '\n(ข้าม: ' + p.dataset.skip + ' — ได้คิวถัดไป)' : '') + '\nกลุ่มที่ <?= (int)$groupNo ?>';
  } else if (m === 'multi') {
    const names = [...document.querySelectorAll('#multiChoices input:checked')].filter(x => !x.closest('.d-none')).map(x => x.dataset.name);
    if (!names.length) { e.preventDefault(); alert('กรุณาเลือกข้าราชการอย่างน้อย 1 คน'); return; }
    who = 'ข้าราชการ: ' + names.join(', ') + '\n(ไม่เลือกกลุ่มเจ้าหน้าที่)';
  } else {
    who = 'ข้าราชการทุกคน (ไม่เลือกกลุ่มเจ้าหน้าที่ · ไม่กระทบคิว)';
  }
  const msg = 'ยืนยันจัดเวรกิจกรรม "' + document.querySelector('[name=title]').value + '"\n'
    + (c?.checked ? 'สถิติจังหวัด: ' + <?= json_encode($chief['name'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '\n' : '')
    + who + '\n\nเพิ่มลงปฏิทินและแจ้งเตือนผู้รับผิดชอบ?';
  if (!confirm(msg)) e.preventDefault();
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
