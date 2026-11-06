<?php
/**
 * สร้าง/แก้ไขแผนการปฏิบัติงานรายเดือน (ผู้ดูแลปฏิทิน)
 * - ตารางคน × วัน: พิมพ์รหัสในช่องได้เหมือน Excel (เช่น สพค, สพค*, โพล สรง) หรือเลือกรหัสจากแถบด้านบนแล้วคลิก/ลากบนช่อง
 * - เพิ่ม/ลบรายชื่อ: ผู้มีบัญชีในระบบ หรือพิมพ์ชื่อ (ผู้ไม่มีบัญชี) · แผนใหม่เริ่มจากรายชื่อเดือนก่อน (ไม่มี = บุคลากรทั้งหมด)
 * - บันทึก = แทนที่แผนของเดือนนั้น + ปรับเวรศาลากลางในปฏิทิน · เวรสำนักงานแสดงจากปฏิทิน (แก้ที่ "นำเข้าเวรสำนักงาน")
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/plan_lib.php';
$me = require_module(CAL_MODULE, 'admin');
[$y, $m] = plan_ym($_GET['m'] ?? null);
$ym = sprintf('%04d-%02d', $y, $m);
$days = (int)date('t', strtotime($ym . '-01'));
$codes = plan_codes();
$plan = plan_get($y, $m);
$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'delete' && $plan) {
        plan_delete($y, $m, (int)$me['id']);
        audit_log('cal_plan_delete', 'ลบแผนการปฏิบัติงาน ' . plan_month_text($y, $m), CAL_MODULE);
        flash('ลบแผนการปฏิบัติงานเดือน' . plan_month_text($y, $m) . ' แล้ว');
        redirect('plan.php?m=' . $ym);
    }
    $valid = array_map('intval', db()->query('SELECT id FROM core_users WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $rows = [];
    $people = [];
    $dutyTyped = 0;
    foreach ((array)($_POST['p'] ?? []) as $p) {
        $uid = (int)($p['uid'] ?? 0);
        $uid = $uid && in_array($uid, $valid, true) ? $uid : null;
        $name = trim((string)($p['name'] ?? ''));
        if (!$uid && $name === '') {
            continue;
        }
        $texts = [];
        $cells = [];
        foreach ((array)($p['c'] ?? []) as $d => $txt) {
            $d = (int)$d;
            $txt = trim((string)$txt);
            if ($d < 1 || $d > $days || $txt === '') {
                continue;
            }
            $texts[$d] = $txt;
            [$ids, $unk, $duty] = plan_parse_cell($txt, $codes);
            if ($ids) {
                $cells[$d] = $ids;
            }
            if ($duty) {
                $dutyTyped++;
            }
            foreach ($unk as $u) {
                $errors[] = $name . ' วันที่ ' . $d . ': ไม่รู้จักรหัส "' . $u . '"';
            }
        }
        $rows[] = ['user_id' => $uid, 'name' => $name, 'texts' => $texts];
        $people[] = ['user_id' => $uid, 'name' => $name, 'cells' => $cells];
    }
    $note = trim(post('note'));
    if (!$errors) {
        $r = plan_save($y, $m, $people, $note !== '' ? $note : null, (int)$me['id']);
        audit_log('cal_plan_save', 'บันทึกแผนการปฏิบัติงาน ' . plan_month_text($y, $m) . ' (' . count($people) . ' คน)', CAL_MODULE);
        [$gc, $gu, $gd] = $r['guard'];
        flash('บันทึกแผนการปฏิบัติงานเดือน' . plan_month_text($y, $m) . ' แล้ว'
            . ($gc + $gu + $gd ? ' · เวรศาลากลางในปฏิทิน: เพิ่ม ' . $gc . ' วัน ปรับ ' . $gu . ' วัน ลบ ' . $gd . ' วัน' : '')
            . ($dutyTyped ? ' · ช่อง "เวร" ' . $dutyTyped . ' ช่องไม่ได้บันทึก (เวรสำนักงานใช้ข้อมูลในปฏิทิน)' : ''));
        redirect('plan.php?m=' . $ym);
    }
} else {
    // เริ่มจากแผนเดือนนี้ / รายชื่อแผนเดือนก่อน / บุคลากรทั้งหมด
    $rows = [];
    $note = $plan['note'] ?? '';
    if ($plan) {
        $cells = plan_cells((int)$plan['id']);
        foreach (plan_people((int)$plan['id']) as $p) {
            $rows[] = ['user_id' => $p['user_id'] ? (int)$p['user_id'] : null, 'name' => $p['name'],
                       'texts' => array_map(fn($ids) => plan_cell_text($ids, $codes), $cells[(int)$p['id']] ?? [])];
        }
    } else {
        [$py, $pm] = plan_ym(date('Y-m', strtotime($ym . '-01 -1 month')));
        if ($prev = plan_get($py, $pm)) {
            foreach (plan_people((int)$prev['id']) as $p) {
                $rows[] = ['user_id' => $p['user_id'] ? (int)$p['user_id'] : null, 'name' => $p['name'], 'texts' => []];
            }
            $notice = 'แผนใหม่: ใช้รายชื่อจากแผนเดือน' . plan_month_text($py, $pm) . ' — เพิ่ม/ลบรายชื่อได้';
        } else {
            foreach (cal_users() as $u) {
                $rows[] = ['user_id' => (int)$u['id'], 'name' => $u['full_name'], 'texts' => []];
            }
            $notice = 'แผนใหม่: เริ่มจากรายชื่อบุคลากรทั้งหมด — ลบคนที่ไม่เกี่ยวข้อง หรือเพิ่มผู้ไม่มีบัญชีได้';
        }
    }
}

// รายชื่อผู้ใช้สำหรับเพิ่ม + เรียงแถวผู้มีบัญชีตามลำดับบุคลากร
$allUsers = array_values(sort_staff(db()->query('SELECT id, username, sort_order, full_name, position FROM core_users WHERE is_active = 1')->fetchAll()));
$info = array_column($allUsers, null, 'id');
foreach ($rows as &$r) {
    if ($r['user_id'] && isset($info[$r['user_id']])) {
        $r += ['username' => $info[$r['user_id']]['username'], 'sort_order' => $info[$r['user_id']]['sort_order'], 'position' => $info[$r['user_id']]['position']];
        $r['name'] = $info[$r['user_id']]['full_name'];
    }
}
unset($r);
$rows = plan_sort_people($rows);
$duty = plan_duty_map($y, $m);
$activeCodes = plan_month_projects($codes, $y, $m) + [PLAN_GUARD_ID => $codes[PLAN_GUARD_ID]];   // แถบเครื่องมือ: โครงการที่ดำเนินการในเดือนนี้ + เวรศาลากลาง
$colors = [];
foreach ($codes as $c) {
    $colors[$c['code']] = $c['color'];
}
$wd = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

$pageTitle = ($plan ? 'แก้ไข' : 'สร้าง') . 'แผนการปฏิบัติงาน';
$active = 'plan';
$pageActions = '<a href="plan.php?m=' . $ym . '" class="btn btn-light"><i class="bi bi-grid-3x3"></i> กลับไปหน้าแผน</a>';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><b>ยังไม่ได้บันทึก</b> — แก้รหัสที่ไม่รู้จัก (ช่องสีแดง) หรือเพิ่มโครงการที่ <a href="<?= h(module_url('ea', 'projects.php')) ?>" target="_blank">โครงการที่รับผิดชอบ</a><br><?= implode('<br>', array_map('h', array_slice($errors, 0, 20))) ?><?= count($errors) > 20 ? '<br>…' : '' ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> <?= h($notice) ?></div><?php endif; ?>

<form method="post" id="planForm">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <h2 class="h5 mb-0 me-2"><?= h(plan_month_text($y, $m)) ?></h2>
      <span class="small text-muted me-1">เครื่องมือ:</span>
      <?php if (count($activeCodes) <= 1): ?><span class="small text-danger">ไม่มีโครงการที่ดำเนินการในเดือนนี้ — <a href="<?= h(module_url('ea', 'projects.php')) ?>">เพิ่ม/แก้ช่วงเวลาโครงการ</a></span><?php endif; ?>
      <div class="btn-group btn-group-sm flex-wrap plan-palette" role="group" aria-label="เลือกรหัสแล้วคลิกช่อง">
        <input type="radio" class="btn-check" name="tool" id="tool-type" value="" checked><label class="btn btn-outline-secondary" for="tool-type" title="พิมพ์ในช่องเอง"><i class="bi bi-cursor-text"></i> พิมพ์</label>
        <?php foreach ($activeCodes as $c): ?>
          <input type="radio" class="btn-check" name="tool" id="tool-<?= (int)$c['id'] ?>" value="<?= h($c['code']) ?>">
          <label class="btn btn-outline-secondary plan-tool" for="tool-<?= (int)$c['id'] ?>" style="--c:<?= h($c['color']) ?>" title="<?= h($c['name']) ?>"><?= h($c['code']) ?></label>
        <?php endforeach; ?>
        <input type="radio" class="btn-check" name="tool" id="tool-erase" value="-"><label class="btn btn-outline-secondary" for="tool-erase" title="ล้างช่อง"><i class="bi bi-eraser"></i> ล้าง</label>
      </div>
      <span class="small text-muted ms-auto">เลือกรหัสแล้วคลิก/ลากบนช่อง (คลิกซ้ำ = เอาออก) · ช่องมีขีดเขียว = มีเวรสำนักงาน (จากปฏิทิน)</span>
    </div>
    <div class="table-responsive plan-scroll">
      <table class="table table-bordered table-sm align-middle mb-0 plan-edit">
        <thead>
          <tr>
            <th class="plan-name">รายชื่อผู้ปฏิบัติงาน</th>
            <?php for ($d = 1; $d <= $days; $d++): $w = (int)date('w', strtotime(sprintf('%s-%02d', $ym, $d))); ?>
              <th class="text-center<?= $w === 0 || $w === 6 ? ' we' : '' ?>"><?= $d ?><div class="small fw-normal text-muted"><?= $wd[$w] ?></div></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody id="planRows">
        <?php foreach ($rows as $i => $r): ?>
          <tr data-uid="<?= (int)$r['user_id'] ?>">
            <td class="plan-name text-nowrap">
              <button type="button" class="btn btn-sm btn-link text-danger p-0 me-1 plan-del" title="ลบออกจากแผน" aria-label="ลบ"><i class="bi bi-x-circle"></i></button>
              <?= h(preg_replace('/\s+/u', ' ', $r['name'])) ?><?= $r['user_id'] ? '' : ' <i class="bi bi-person-dash text-muted small" title="ไม่มีบัญชีในระบบ"></i>' ?>
              <input type="hidden" name="p[<?= $i ?>][uid]" value="<?= (int)$r['user_id'] ?>"><input type="hidden" name="p[<?= $i ?>][name]" value="<?= h($r['name']) ?>">
            </td>
            <?php for ($d = 1; $d <= $days; $d++):
              $w = (int)date('w', strtotime(sprintf('%s-%02d', $ym, $d)));
              $hasDuty = $r['user_id'] && !empty($duty[$r['user_id']][$d]); ?>
              <td class="p-0<?= $w === 0 || $w === 6 ? ' we' : '' ?><?= $hasDuty ? ' has-duty' : '' ?>"<?= $hasDuty ? ' title="เวรสำนักงาน (จากปฏิทิน)"' : '' ?>>
                <input name="p[<?= $i ?>][c][<?= $d ?>]" class="plan-in" value="<?= h($r['texts'][$d] ?? '') ?>" autocomplete="off" aria-label="วันที่ <?= $d ?>"></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <div class="row g-2 align-items-center">
        <div class="col-md-5 d-flex gap-2">
          <select id="addUser" class="form-select form-select-sm" aria-label="เพิ่มผู้มีบัญชี">
            <option value="">+ เพิ่มบุคลากรในระบบ…</option>
            <?php foreach ($allUsers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h(preg_replace('/\s+/u', ' ', $u['full_name'])) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5 d-flex gap-2">
          <input id="addName" class="form-control form-control-sm" placeholder="+ ผู้ไม่มีบัญชี: พิมพ์ชื่อ-นามสกุล" maxlength="150">
          <button type="button" id="addNameBtn" class="btn btn-sm btn-outline-primary text-nowrap">เพิ่ม</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <label class="form-label" for="planNote">หมายเหตุ (แสดงท้ายแผน)</label>
      <textarea name="note" id="planNote" class="form-control" rows="2" maxlength="2000"><?= h($note) ?></textarea>
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($plan): ?><button type="submit" form="planDelete" class="btn btn-outline-danger"><i class="bi bi-trash"></i> ลบแผนเดือนนี้</button><?php endif; ?>
    <a href="plan.php?m=<?= $ym ?>" class="btn btn-outline-secondary ms-auto">ยกเลิก</a>
    <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึกแผน</button>
  </div>
</form>
<?php if ($plan): ?>
<form method="post" id="planDelete" onsubmit="return confirm(<?= js_attr('ลบแผนการปฏิบัติงานเดือน' . plan_month_text($y, $m) . " ทั้งหมด?\n(เวรศาลากลางของเดือนนี้ในปฏิทินจะถูกลบด้วย)") ?>)">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
</form>
<?php endif; ?>

<template id="rowTpl">
  <tr data-uid="__UID__">
    <td class="plan-name text-nowrap">
      <button type="button" class="btn btn-sm btn-link text-danger p-0 me-1 plan-del" title="ลบออกจากแผน" aria-label="ลบ"><i class="bi bi-x-circle"></i></button>
      <span class="nm"></span>
      <input type="hidden" name="p[__I__][uid]" value="__UID__"><input type="hidden" name="p[__I__][name]" class="nm-in">
    </td>
    <?php for ($d = 1; $d <= $days; $d++): $w = (int)date('w', strtotime(sprintf('%s-%02d', $ym, $d))); ?>
      <td class="p-0<?= $w === 0 || $w === 6 ? ' we' : '' ?>"><input name="p[__I__][c][<?= $d ?>]" class="plan-in" autocomplete="off" aria-label="วันที่ <?= $d ?>"></td>
    <?php endfor; ?>
  </tr>
</template>

<style>
  .plan-scroll { max-height: 72vh; }
  .plan-edit { font-size: .8rem; }
  .plan-edit thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg); padding: .15rem; line-height: 1.1; }
  .plan-edit .plan-name { position: sticky; left: 0; z-index: 1; background: var(--bs-body-bg); min-width: 200px; padding: .2rem .4rem; }
  .plan-edit thead .plan-name { z-index: 3; }
  .plan-edit .we { background-color: #f1f5f9; }
  .plan-in { width: 2.9rem; border: 0; background: transparent; text-align: center; font-weight: 600; padding: .3rem 0; font-size: .8rem; outline: none; }
  .plan-in:focus { box-shadow: inset 0 0 0 2px #3b82f6; background: #fff; }
  .plan-in.has { background: color-mix(in srgb, var(--c) 18%, white); color: color-mix(in srgb, var(--c) 85%, black); }
  .plan-in.bad { background: #fee2e2; color: #b91c1c; }
  td.has-duty { box-shadow: inset 0 -3px 0 #22c55e; }
  .plan-tool { color: var(--c); font-weight: 600; }
  .btn-check:checked + .plan-tool { background: var(--c); border-color: var(--c); color: #fff; }
  .plan-painting .plan-in { cursor: cell; caret-color: transparent; }
</style>
<script>
(() => {
  const COLORS = <?= json_encode($colors, JSON_UNESCAPED_UNICODE) ?>;
  const form = document.getElementById('planForm'), body = document.getElementById('planRows');
  const tool = () => form.querySelector('[name=tool]:checked').value;
  let dirty = false;

  // แยกข้อความในช่อง → {codes: [...], star: bool}
  const parse = v => ({ star: v.includes('*'), codes: v.replace(/\*/g, ' ').split(/[\s,+\/]+/).filter(Boolean) });
  const build = p => p.codes.join(' ') + (p.star ? '*' : '');
  const paint = inp => {
    const p = parse(inp.value.trim());
    const known = p.codes.filter(c => COLORS[c] && c !== '*');
    const bad = p.codes.some(c => !COLORS[c] && c !== 'เวร');
    inp.classList.toggle('bad', bad);
    inp.classList.toggle('has', !bad && known.length > 0);
    if (known.length) inp.style.setProperty('--c', COLORS[known[0]]); else inp.style.removeProperty('--c');
    inp.style.color = !known.length && p.star ? '#dc2626' : '';
  };
  form.querySelectorAll('.plan-in').forEach(paint);

  // โหมดเลือกรหัสแล้วคลิก/ลาก: คลิกแรกกำหนดว่าจะ "ใส่" หรือ "เอาออก" แล้วใช้แบบเดียวกันกับช่องที่ลากผ่าน
  let down = false, adding = true;
  const apply = (inp, first) => {
    const t = tool();
    if (!t) return;
    const p = parse(inp.value.trim());
    if (t === '-') { inp.value = ''; }
    else {
      const has = t === '*' ? p.star : p.codes.includes(t);
      if (first) adding = !has;
      if (t === '*') p.star = adding;
      else if (adding && !has) p.codes.push(t);
      else if (!adding && has) p.codes = p.codes.filter(c => c !== t);
      inp.value = build(p);
    }
    paint(inp); dirty = true;
  };
  form.addEventListener('change', e => { if (e.target.name === 'tool') document.body.classList.toggle('plan-painting', !!tool()); });
  body.addEventListener('mousedown', e => {
    const inp = e.target.closest('.plan-in');
    if (!inp || !tool()) return;
    e.preventDefault(); down = true; apply(inp, true);
  });
  body.addEventListener('mouseover', e => { const inp = e.target.closest('.plan-in'); if (down && inp) apply(inp, false); });
  document.addEventListener('mouseup', () => { down = false; });
  body.addEventListener('input', e => { if (e.target.classList.contains('plan-in')) { paint(e.target); dirty = true; } });

  // ลบแถว / เพิ่มแถว
  body.addEventListener('click', e => {
    const b = e.target.closest('.plan-del');
    if (b && confirm('ลบรายชื่อนี้ออกจากแผน?')) { b.closest('tr').remove(); dirty = true; syncSelect(); }
  });
  let idx = body.rows.length + 1000;
  const addRow = (uid, name) => {
    const html = document.getElementById('rowTpl').innerHTML.replaceAll('__I__', idx++).replaceAll('__UID__', uid || 0);
    const tb = document.createElement('tbody'); tb.innerHTML = html.trim();
    const tr = tb.firstElementChild;
    tr.querySelector('.nm').textContent = name;
    tr.querySelector('.nm-in').value = name;
    body.appendChild(tr); dirty = true; syncSelect();
    tr.scrollIntoView({ block: 'nearest' });
  };
  const sel = document.getElementById('addUser');
  const syncSelect = () => {
    const used = new Set([...body.rows].map(r => r.dataset.uid));
    [...sel.options].forEach(o => { if (o.value) o.disabled = used.has(o.value); });
  };
  syncSelect();
  sel.addEventListener('change', () => { if (sel.value) { addRow(sel.value, sel.selectedOptions[0].text); sel.value = ''; } });
  const nameIn = document.getElementById('addName');
  const addName = () => { const n = nameIn.value.trim(); if (n) { addRow(0, n); nameIn.value = ''; } };
  document.getElementById('addNameBtn').addEventListener('click', addName);
  nameIn.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addName(); } });

  // เลื่อนช่องด้วยลูกศร/Enter เหมือนตาราง
  body.addEventListener('keydown', e => {
    const inp = e.target.closest('.plan-in');
    if (!inp) return;
    const td = inp.parentElement, tr = td.parentElement, ci = td.cellIndex;
    let target = null;
    if (e.key === 'ArrowRight' && inp.selectionStart === inp.value.length) target = td.nextElementSibling;
    else if (e.key === 'ArrowLeft' && inp.selectionStart === 0) target = td.previousElementSibling;
    else if (e.key === 'ArrowDown' || e.key === 'Enter') target = tr.nextElementSibling?.cells[ci];
    else if (e.key === 'ArrowUp') target = tr.previousElementSibling?.cells[ci];
    const next = target?.querySelector('.plan-in');
    if (next) { e.preventDefault(); next.focus(); next.select(); }
  });

  let saving = false;
  form.addEventListener('submit', () => { saving = true; });
  window.addEventListener('beforeunload', e => { if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
