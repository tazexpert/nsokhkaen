<?php
/**
 * ช่องเลือกผู้ปฏิบัติ — แบบเดียวกับหน้าเพิ่มกิจกรรมของระบบปฏิทิน
 *   ปุ่มเพิ่มทั้งหมด / ข้าราชการ / พนักงาน / จ้างเหมา / ขับรถ / ตามกลุ่มงาน · ค้นหาชื่อ/ตำแหน่ง · เรียงตามลำดับบุคลากร (cal_users → sort_staff)
 *   เปลี่ยนการเลือก → ส่ง event "pickchange" ที่กล่อง (detail = [{id, name}, ...])
 */
function kpi_user_picker(string $name, array $selected, string $id): string
{
    $users = cal_users();
    $selected = array_map('intval', $selected);
    ob_start(); ?>
<div class="kpi-picker" id="<?= h($id) ?>">
  <div class="d-flex flex-wrap gap-1 mb-2">
    <button type="button" class="btn btn-sm btn-outline-primary" data-add="*" title="ไม่รวมพนักงานขับรถ"><i class="bi bi-people-fill"></i> เพิ่มทั้งหมด</button>
    <button type="button" class="btn btn-sm btn-outline-primary" data-add="gov"><i class="bi bi-person-badge"></i> เพิ่มข้าราชการ</button>
    <button type="button" class="btn btn-sm btn-outline-primary" data-add="emp"><i class="bi bi-person-vcard"></i> เพิ่มพนักงาน</button>
    <?php if (cal_group_ids('contract')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-add="contract"><i class="bi bi-person-workspace"></i> เพิ่มจ้างเหมาบริการ</button><?php endif; ?>
    <?php if (cal_group_ids('driver')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-add="driver"><i class="bi bi-car-front"></i> เพิ่มพนักงานขับรถ</button><?php endif; ?>
    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-clear><i class="bi bi-x-lg"></i> ล้าง</button>
  </div>
  <?php if ($wgs = cal_work_groups()): ?>
  <div class="d-flex flex-wrap gap-1 mb-2 align-items-center">
    <span class="small text-muted me-1"><i class="bi bi-diagram-3"></i> กลุ่มงาน:</span>
    <?php foreach ($wgs as $gid => $g): ?>
      <button type="button" class="btn btn-sm btn-outline-success" data-add-ids="<?= h(implode(',', $g['ids'])) ?>" title="<?= h(cal_work_group_label($gid)) ?> (<?= count($g['ids']) ?> คน)"><i class="bi bi-plus"></i> <?= h($g['name']) ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="d-flex gap-2 align-items-center mb-2">
    <input type="search" class="form-control form-control-sm" data-search placeholder="ค้นหาชื่อ / ตำแหน่ง">
    <span class="badge text-bg-primary text-nowrap" data-count>0 คน</span>
  </div>
  <div class="cal-userpick border rounded">
    <?php foreach (array_keys(CAL_STAFF_LABEL) as $t): $list = array_filter($users, fn($u) => $u['type'] === $t); if (!$list) continue; ?>
      <div class="grp"><?= CAL_STAFF_LABEL[$t] ?> <span class="text-muted fw-normal">(<?= count($list) ?>)</span></div>
      <?php foreach ($list as $u): ?>
        <label class="opt" data-type="<?= h((string)$u['type']) ?>" data-text="<?= h(mb_strtolower($u['full_name'] . ' ' . $u['position'])) ?>">
          <input type="checkbox" class="form-check-input" name="<?= h($name) ?>[]" value="<?= (int)$u['id'] ?>" data-name="<?= h(preg_replace('/\s+/u', ' ', trim($u['full_name']))) ?>" <?= in_array((int)$u['id'], $selected, true) ? 'checked' : '' ?>>
          <span><?= h($u['full_name']) ?><span class="d-block small text-muted"><?= h(implode(' / ', staff_position_lines($u)) ?: '-') ?></span></span>
        </label>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>
<script>
(() => {
  const box = document.getElementById(<?= json_encode($id) ?>);
  const boxes = () => [...box.querySelectorAll('.opt input')];
  const changed = () => {
    const sel = boxes().filter(b => b.checked);
    box.querySelector('[data-count]').textContent = sel.length + ' คน';
    box.querySelectorAll('.opt').forEach(o => o.classList.toggle('on', o.querySelector('input').checked));
    box.dispatchEvent(new CustomEvent('pickchange', { detail: sel.map(b => ({ id: b.value, name: b.dataset.name })) }));
  };
  box.querySelectorAll('[data-add]').forEach(btn => btn.addEventListener('click', () => {
    const t = btn.dataset.add;
    box.querySelectorAll('.opt').forEach(o => { if ((t === '*' && o.dataset.type !== 'driver') || o.dataset.type === t) o.querySelector('input').checked = true; });
    changed();
  }));
  box.querySelectorAll('[data-add-ids]').forEach(btn => btn.addEventListener('click', () => {
    const ids = btn.dataset.addIds.split(',');
    boxes().forEach(b => { if (ids.includes(b.value)) b.checked = true; });
    changed();
  }));
  box.querySelector('[data-clear]').addEventListener('click', () => { boxes().forEach(b => b.checked = false); changed(); });
  box.querySelector('[data-search]').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    box.querySelectorAll('.opt').forEach(o => o.hidden = q !== '' && !o.dataset.text.includes(q));
  });
  box.addEventListener('change', e => { if (e.target.matches('.opt input')) changed(); });
  box.kpiRefresh = changed;
  document.addEventListener('DOMContentLoaded', changed);
})();
</script>
<?php
    return ob_get_clean();
}
