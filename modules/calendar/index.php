<?php
/** ปฏิทินรายเดือน: กิจกรรมของทุกคนในสำนักงาน (กิจกรรมที่ตนรับผิดชอบเน้นสี) · การ์ดสรุปรายวันด้านขวา (ใครมีกิจกรรมบ้าง) แสดงวันนี้ตั้งแต่เปิดหน้า คลิกวันอื่นเพื่อเปลี่ยน */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/att_lib.php';
$me = require_module_or_guest(CAL_MODULE);
$m = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $m) || !checkdate((int)substr($m, 5, 2), 1, (int)substr($m, 0, 4))) {
    $m = date('Y-m');
}
$guest = !empty($me['is_guest']);            // ผู้เยี่ยมชม: ดูได้อย่างเดียว ไม่มี "งานของฉัน"
$mine = !$guest && !empty($_GET['mine']);
$first = $m . '-01';
$last = date('Y-m-t', strtotime($first));
$gridStart = date('Y-m-d', strtotime($first . ' -' . date('w', strtotime($first)) . ' days'));          // อาทิตย์ก่อนวันที่ 1
$gridEnd = date('Y-m-d', strtotime($last . ' +' . (6 - (int)date('w', strtotime($last))) . ' days'));    // เสาร์หลังวันสุดท้าย
$events = cal_events_between($gridStart, $gridEnd, $mine ? $me['id'] : null);
$assignees = cal_assignees(array_column($events, 'id'));
$byDay = [];
foreach ($events as $e) {
    $d = max($e['event_date'], $gridStart);
    $end = min($e['end_date'] ?: $e['event_date'], $gridEnd);
    for (; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $byDay[$d][] = $e;
    }
}
// เวลาปฏิบัติราชการของตนเอง (ส่วนตัว): มาสาย / ขาดราชการ / ลงเวลาไม่ครบ / รับรองแล้ว
$attItems = [];
if (!$guest) {
    try {
        $attItems = array_filter(att_user_statuses((int)$me['id'], $gridStart, $gridEnd), 'att_is_certify_item');
    } catch (PDOException $e) {
        // ยังไม่ได้รัน migration cal_attendance
    }
}
// สรุปรายวัน (คลิกวันที่ → แผงด้านขวา): 1. เวรสำนักงาน / เวรศาลากลาง · 2. กิจกรรมแยกตามคน (เรียงตามลำดับบุคลากร)
$staffOrder = array_flip(array_keys(cal_users()));
$nameOf = [];
if ($allIds = array_unique(array_merge([], ...array_values($assignees)))) {
    foreach (db()->query('SELECT id, full_name FROM core_users WHERE id IN (' . implode(',', array_map('intval', $allIds)) . ')') as $u) {
        $nameOf[(int)$u['id']] = preg_replace('/\s+/u', ' ', trim($u['full_name']));
    }
}
$dayInfo = [];
foreach ($byDay as $d => $list) {
    $info = ['duty' => [], 'guard' => [], 'people' => [], 'none' => []];
    usort($list, fn($a, $b) => [(string)$a['start_time'], (int)$a['id']] <=> [(string)$b['start_time'], (int)$b['id']]);
    foreach ($list as $e) {
        $ids = $assignees[(int)$e['id']] ?? [];
        $names = array_values(array_filter(array_map(fn($id) => $nameOf[$id] ?? null, $ids)));
        if (cal_is_duty($e) || cal_is_guard($e)) {
            $k = cal_is_duty($e) ? 'duty' : 'guard';
            $info[$k] = array_values(array_unique(array_merge($info[$k], $names ?: [$e['title']])));
            continue;
        }
        $item = ['id' => (int)$e['id'], 'title' => $e['title'], 'loc' => (string)$e['location'], 'priv' => cal_is_private($e),
                 't' => ($e['end_date'] && $e['end_date'] !== $e['event_date'] ? cal_range_text($e) . ' · ' : '') . cal_time_text($e)];
        if (!$ids) {
            $info['none'][] = $item;
        }
        foreach ($ids as $id) {
            $info['people'][$id]['name'] = $nameOf[$id] ?? ('#' . $id);
            $info['people'][$id]['items'][] = $item;
        }
    }
    uksort($info['people'], fn($a, $b) => [$staffOrder[$a] ?? PHP_INT_MAX, $a] <=> [$staffOrder[$b] ?? PHP_INT_MAX, $b]);
    $info['people'] = array_values($info['people']);
    $dayInfo[$d] = $info;
}

$ts = strtotime($first);
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$today = date('Y-m-d');
$qs = fn(array $p) => '?' . http_build_query(array_filter($p + ['m' => $m, 'mine' => $mine ? 1 : null]));

$active = $mine ? 'mine' : 'month';
$pageTitle = 'ปฏิทินการปฏิบัติงาน';
$pageActions = (cal_can('admin') ? '<a href="import" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> นำเข้า CSV</a>' : '')
    . (cal_can('editor') ? '<a href="event_form" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="row g-3">
<div class="col-xl-9">
<div class="card">
  <div class="card-header cal-month-head d-flex flex-wrap align-items-center gap-2">
    <a href="<?= h($qs(['m' => $prev])) ?>" class="btn btn-light btn-sm"><i class="bi bi-chevron-left"></i></a>
    <h2 class="h5 mb-0 mx-1"><?= h(thai_month_long((int)date('n', $ts))) ?> <?= (int)date('Y', $ts) + 543 ?></h2>
    <a href="<?= h($qs(['m' => $next])) ?>" class="btn btn-light btn-sm"><i class="bi bi-chevron-right"></i></a>
    <a href="<?= h($qs(['m' => date('Y-m')])) ?>" class="btn btn-outline-secondary btn-sm">เดือนนี้</a>
    <div class="btn-group btn-group-sm ms-auto<?= $guest ? ' invisible' : '' ?>">
      <a href="<?= h('?m=' . $m) ?>" class="btn btn-outline-primary<?= $mine ? '' : ' active' ?>">ทุกคน</a>
      <a href="<?= h('?m=' . $m . '&mine=1') ?>" class="btn btn-outline-primary<?= $mine ? ' active' : '' ?>">เฉพาะงานของฉัน</a>
    </div>
    <a href="list?from=<?= h($first) ?>&to=<?= h($last) ?><?= $mine ? '&mine=1' : '' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-task"></i> แบบตาราง</a>
    <?php if ($mine): ?><a href="ical?download=1" class="btn btn-outline-success btn-sm" title="ดาวน์โหลดไฟล์ .ics งานของฉัน (ย้อนหลัง 6 เดือน – ล่วงหน้า 1 ปี) แล้วนำเข้าใน Google Calendar: การตั้งค่า → นำเข้าและส่งออก"><i class="bi bi-google"></i> ส่งออกไปยัง Google Calendar</a><?php endif; ?>
  </div>
  <div class="cal-grid">
    <?php foreach (CAL_WEEKDAYS_SHORT as $i => $w): ?><div class="cal-wd<?= $i === 0 ? ' sun' : '' ?>"><?= $w ?></div><?php endforeach; ?>
    <?php for ($d = $gridStart; $d <= $gridEnd; $d = date('Y-m-d', strtotime($d . ' +1 day'))):
        $out = substr($d, 0, 7) !== $m; ?>
      <div class="cal-cell<?= $out ? ' out' : '' ?><?= $d === $today ? ' today' : '' ?>" data-date="<?= $d ?>" data-label="<?= h(cal_date_text($d)) ?>">
        <div class="cal-dnum"><?php if (cal_can('editor')): ?><a href="event_form?date=<?= $d ?>" class="cal-add" title="เพิ่มกิจกรรมวันนี้"><i class="bi bi-plus"></i></a><?php endif; ?><?= $d === $today ? '<span class="cal-today-tag">วันนี้</span>' : '' ?><span class="cal-n"><?= substr($d, 8, 2) ?></span></div>
        <?php foreach ($byDay[$d] ?? [] as $e): $isMine = in_array($me['id'], $assignees[(int)$e['id']] ?? [], true); ?>
          <a href="event_view?id=<?= (int)$e['id'] ?>" class="cal-ev<?= cal_is_duty($e) ? ' duty' : '' ?><?= cal_is_guard($e) ? ' guard' : '' ?><?= $isMine ? ' mine' : '' ?>" title="<?= h(cal_time_text($e) . ' ' . $e['title'] . ($e['location'] ? ' @ ' . $e['location'] : '')) ?>">
            <?php if ($e['start_time'] && $d === $e['event_date']): ?><span class="t"><?= cal_time_thai($e['start_time']) ?></span><?php endif; ?><?= cal_is_private($e) ? '<i class="bi bi-lock-fill me-1" title="ส่วนตัว"></i>' : '' ?><?= h($e['title']) ?></a>
        <?php endforeach; ?>
        <?php if (isset($attItems[$d])): $ai = $attItems[$d]; ?>
          <a href="att_view?date=<?= $d ?>" class="cal-ev att-ev<?= $ai['cls'] === 'att-cert' ? ' done' : ($ai['cls'] === 'att-late' ? ' late' : '') ?>" title="<?= h($ai['title']) ?> (เห็นเฉพาะคุณ)"><i class="bi bi-lock-fill me-1"></i><?= h(att_item_title($ai)) ?></a>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
  <div class="card-footer small text-muted"><?php if (!$guest): ?><span class="cal-ev mine d-inline-block px-2">งานของคุณ</span> <?php endif; ?><span class="cal-ev d-inline-block px-2">งานของผู้อื่น</span> <span class="cal-ev duty d-inline-block px-2">เวรสำนักงาน</span> <span class="cal-ev guard d-inline-block px-2">เวรศาลากลาง</span><?php if (!$guest): ?> <span class="cal-ev att-ev d-inline-block px-2"><i class="bi bi-lock-fill me-1"></i>ขาดราชการ/ลงเวลาไม่ครบ (เห็นเฉพาะคุณ)</span> <span class="cal-ev att-ev late d-inline-block px-2">มาสาย</span> <span class="cal-ev att-ev done d-inline-block px-2">รับรองการมาปฏิบัติราชการ</span><?php endif; ?> · คลิกกิจกรรมเพื่อดูรายละเอียด</div>
</div>
</div>
<div class="col-xl-3">
<div class="cal-day-panel" id="dayPanel" aria-labelledby="dayPanelTitle">
  <div class="cal-day-head" id="dayPanelHead">
    <div class="cal-day-date"><div class="d" id="dayPanelD"></div><div class="m" id="dayPanelM"></div></div>
    <div class="min-w-0 flex-grow-1"><div class="small"><i class="bi bi-calendar-day"></i> สรุปรายวัน · คลิกวันที่เพื่อเปลี่ยน</div><div class="fw-semibold text-truncate" id="dayPanelTitle"></div></div>
  </div>
  <div class="cal-day-body" id="dayPanelBody"></div>
</div>
</div>
</div>
<script>
(() => {
  // การ์ดสรุปรายวันข้างปฏิทิน: เวรสำนักงาน + ผู้มีกิจกรรมแยกตามคน · คลิกวันที่ในปฏิทินเพื่อเปลี่ยนวัน
  const data = <?= json_encode($dayInfo, JSON_UNESCAPED_UNICODE) ?>;
  const panel = document.getElementById('dayPanel'), body = document.getElementById('dayPanelBody');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const item = it => `<a href="event_view?id=${it.id}" class="cal-day-item"><span class="t">${esc(it.t)}</span> ${it.priv ? '<i class="bi bi-lock-fill" title="ส่วนตัว"></i> ' : ''}${esc(it.title)}${it.loc ? `<span class="loc"> @ ${esc(it.loc)}</span>` : ''}</a>`;
  let cur = null;
  const open = cell => {
    const d = data[cell.dataset.date] || { duty: [], guard: [], people: [], none: [] };
    document.getElementById('dayPanelTitle').textContent = cell.dataset.label;
    const [, mm, dd] = cell.dataset.date.split('-').map(Number);
    document.getElementById('dayPanelD').textContent = dd;
    document.getElementById('dayPanelM').textContent = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][mm];
    let html = `<div class="cal-day-sec duty"><div class="cal-day-h"><i class="bi bi-shield-check"></i> 1. เวรสำนักงาน</div><div class="cal-day-c">`
      + (d.duty.length ? d.duty.map(esc).join(', ') : '<span class="text-muted">-</span>')
      + (d.guard.length ? `<div class="cal-day-guard"><i class="bi bi-building"></i> เวรศาลากลาง: ${d.guard.map(esc).join(', ')}</div>` : '') + '</div></div>';
    html += `<div class="cal-day-sec people"><div class="cal-day-h"><i class="bi bi-people-fill"></i> 2. ผู้มีกิจกรรม <span class="badge rounded-pill ms-auto">${d.people.length} คน</span></div><div class="cal-day-c">`
      + (d.people.length ? d.people.map((p, i) => `<div class="cal-day-person"><div class="cal-day-no">${i + 1}</div><div class="cal-day-pbody"><div class="cal-day-name">${esc(p.name)}</div>${p.items.map(item).join('')}</div></div>`).join('')
         : '<div class="text-muted">ไม่มีกิจกรรม</div>')
      + (d.none.length ? `<div class="cal-day-person none"><div class="cal-day-no"><i class="bi bi-question-lg"></i></div><div class="cal-day-pbody"><div class="cal-day-name">ไม่ระบุผู้รับผิดชอบ</div>${d.none.map(item).join('')}</div></div>` : '') + '</div></div>';
    body.innerHTML = html;
    cur?.classList.remove('cal-picked'); cur = cell; cell.classList.add('cal-picked');
  };
  document.querySelector('.cal-grid').addEventListener('click', ev => {
    const cell = ev.target.closest('.cal-cell[data-date]');
    if (!cell || ev.target.closest('a, button')) return;          // คลิกกิจกรรม/ปุ่มเพิ่ม = ทำงานตามเดิม
    open(cell);
  });
  // เปิดหน้า: แสดงวันนี้ (ดูเดือนอื่น = วันที่ 1 ของเดือนนั้น)
  open(document.querySelector('.cal-cell[data-date="<?= substr($today, 0, 7) === $m ? $today : $first ?>"]'));
})();
</script>
<?= cal_modal_script() ?>
<?php require __DIR__ . '/footer.php'; ?>
