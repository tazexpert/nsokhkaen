<?php
/** หน้าหลัก: ตารางการปฏิบัติงาน (จากระบบที่มี home_widget.php) + กล่องเลือกระบบ (จัดเรียงเองได้) */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$mods = accessible_modules($user);

$pageTitle = 'หน้าหลัก';
$active = 'home';
/** วันที่ปัจจุบันแบบไทย เช่น "วันจันทร์ที่ 28 ตุลาคม 2569" */
function cal_home_date(): string
{
    $w = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'][(int)date('w')];
    return 'วัน' . $w . 'ที่ ' . (int)date('j') . ' ' . thai_month_long((int)date('n')) . ' ' . ((int)date('Y') + 543);
}
$widgets = array_filter(array_map(fn($m) => APP_ROOT . '/modules/' . $m['key'] . '/home_widget.php', $mods), 'is_file');
$hidePageHead = true;
require APP_ROOT . '/core/layout/header.php';
?>
<section class="home-hero mb-4">
  <a href="<?= h(url('account.php')) ?>" class="home-hero-avatar" title="บัญชีของฉัน — เปลี่ยนรูปโปรไฟล์"><?= user_avatar($user, 64) ?></a>
  <div class="min-w-0 me-auto">
    <div class="home-hero-date"><i class="bi bi-calendar3"></i> <?= h(cal_home_date()) ?></div>
    <h2 class="home-hero-title">สวัสดี <?= h(preg_replace('/\s+/u', ' ', $user['full_name'])) ?></h2>
    <div class="home-hero-sub"><?= h(ORG_NAME) ?></div>
  </div>
</section>

<?php
// ส่วนที่ระบบย่อยแสดงบนหน้าหลัก (modules/<key>/home_widget.php) เช่น ตารางการปฏิบัติงาน — แต่ละส่วนมีหัวข้อในการ์ดของตัวเอง
foreach ($widgets as $widget) {
    include $widget;
}
?>

<div class="d-flex align-items-center mb-3 mt-2">
  <div class="home-section-title tone-green mb-0"><i class="bi bi-grid-3x3-gap"></i> ระบบงาน <small>เลือกระบบที่ต้องการใช้งาน</small></div>
  <?php if (count($mods) > 1): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="arrangeBtn"><i class="bi bi-arrows-move"></i> จัดเรียงระบบ</button>
    <div class="ms-auto d-none gap-2" id="arrangeBar">
      <button type="button" class="btn btn-sm btn-light" id="arrangeReset">คืนค่าเริ่มต้น</button>
      <button type="button" class="btn btn-sm btn-light" id="arrangeCancel">ยกเลิก</button>
      <button type="button" class="btn btn-sm btn-primary" id="arrangeSave"><i class="bi bi-check2"></i> บันทึกลำดับ</button>
    </div>
  <?php endif; ?>
</div>
<div class="alert alert-info py-2 small d-none" id="arrangeHelp"><i class="bi bi-info-circle"></i>
  ลากกล่องเพื่อสลับตำแหน่ง หรือใช้ปุ่มลูกศร — ลำดับนี้ใช้กับเมนูด้านบนด้วย<?= count($mods) > 5 ? ' (เมนูด้านบนแสดง 4 ระบบแรก ที่เหลืออยู่ใน "ระบบอื่นๆ")' : '' ?></div>

<div class="row g-3 mb-4" id="modGrid">
  <?php foreach ($mods as $m): ?>
    <div class="col-md-6 col-xl-4 mod-item" data-key="<?= h($m['key']) ?>">
      <a class="mod-card" style="--mc:<?= h($m['color']) ?>" href="<?= h(module_url($m['key'], $m['entry'] ?? 'index.php')) ?>">
        <span class="mod-icon<?= $m['logo'] ? ' has-logo' : '' ?>" style="background:<?= h($m['color']) ?>"><?= $m['logo'] ? module_logo($m, 52) : '<i class="bi ' . h($m['icon']) . '"></i>' ?></span>
        <span class="min-w-0">
          <h3><?= h($m['name']) ?></h3>
          <span class="d-block small text-muted mb-2"><?= h($m['description']) ?></span>
          <span class="d-flex align-items-center gap-2 flex-wrap">
            <span class="mod-role"><i class="bi bi-person-badge"></i> <?= h(role_short($m['key'], module_role($m['key'], $user))) ?></span>
            <span class="mod-go ms-auto">เข้าใช้งาน <i class="bi bi-arrow-right"></i></span>
          </span>
        </span>
      </a>
      <div class="mod-move">
        <button type="button" class="btn btn-sm btn-light" data-move="-1" title="เลื่อนไปก่อนหน้า"><i class="bi bi-arrow-left"></i></button>
        <span class="mod-pos"></span>
        <button type="button" class="btn btn-sm btn-light" data-move="1" title="เลื่อนไปถัดไป"><i class="bi bi-arrow-right"></i></button>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$mods): ?>
    <div class="col-12"><div class="alert alert-warning mb-0"><i class="bi bi-info-circle"></i>
      บัญชีของคุณยังไม่ได้รับสิทธิ์ใช้งานระบบใด กรุณาติดต่อผู้ดูแลระบบ</div></div>
  <?php endif; ?>
</div>
<script>
(() => {
  const grid = document.getElementById('modGrid'), btn = document.getElementById('arrangeBtn');
  if (!btn) return;
  const bar = document.getElementById('arrangeBar'), help = document.getElementById('arrangeHelp');
  let original = null, dragEl = null;
  const items = () => [...grid.querySelectorAll('.mod-item')];
  const number = () => items().forEach((el, i) => el.querySelector('.mod-pos').textContent = i + 1);
  const setMode = on => {
    grid.classList.toggle('arranging', on);
    btn.classList.toggle('d-none', on); bar.classList.toggle('d-none', !on); bar.classList.toggle('d-flex', on); help.classList.toggle('d-none', !on);
    items().forEach(el => el.draggable = on);
    if (on) { original = items(); number(); }
  };
  const save = order => $.post(BASE_URL_JS + 'api/prefs.php', { action: 'module_order', order })
    .done(() => location.reload()).fail(() => alert('บันทึกไม่สำเร็จ'));
  btn.addEventListener('click', () => setMode(true));
  document.getElementById('arrangeCancel').addEventListener('click', () => { original.forEach(el => grid.appendChild(el)); setMode(false); });
  document.getElementById('arrangeSave').addEventListener('click', () => save(items().map(el => el.dataset.key)));
  document.getElementById('arrangeReset').addEventListener('click', () => save([]));
  grid.addEventListener('click', e => {
    if (!grid.classList.contains('arranging')) return;
    const mv = e.target.closest('[data-move]');
    e.preventDefault();                                   // ระหว่างจัดเรียง ไม่เปิดระบบเมื่อคลิกกล่อง
    if (!mv) return;
    const el = mv.closest('.mod-item'), list = items(), i = list.indexOf(el), j = i + Number(mv.dataset.move);
    if (j < 0 || j >= list.length) return;
    j > i ? list[j].after(el) : list[j].before(el);
    number();
  });
  grid.addEventListener('dragstart', e => { dragEl = e.target.closest('.mod-item'); dragEl?.classList.add('dragging'); });
  grid.addEventListener('dragend', () => { dragEl?.classList.remove('dragging'); dragEl = null; number(); });
  grid.addEventListener('dragover', e => {
    if (!dragEl) return;
    e.preventDefault();
    const over = e.target.closest('.mod-item');
    if (!over || over === dragEl) return;
    const r = over.getBoundingClientRect(), after = (e.clientX - r.left) > r.width / 2;
    after ? over.after(dragEl) : over.before(dragEl);
  });
  const fromHash = () => { if (location.hash === '#arrange' && !grid.classList.contains('arranging')) { setMode(true); grid.scrollIntoView({ behavior: 'smooth' }); } };
  window.addEventListener('hashchange', fromHash); fromHash();
})();
</script>

<?php
$links = [];
foreach (modules() as $m) {
    foreach ($m['links'] as $l) {
        $links[] = $l + ['module' => $m['key']];
    }
}
if ($links || $user['is_superadmin']): ?>
<div class="home-section-title tone-orange"><i class="bi bi-link-45deg"></i> ลิงก์และเครื่องมือ</div>
<div class="row g-3 mb-2">
<?php endif;
if ($links): ?>
  <div class="col-lg"><div class="card h-100 home-tool tone-orange"><div class="card-body">
  <div class="home-tool-title"><i class="bi bi-globe2"></i> ลิงก์ที่เกี่ยวข้อง</div>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($links as $l): ?>
      <a href="<?= h(module_url($l['module'], $l['href'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i class="bi <?= h($l['icon'] ?? 'bi-box-arrow-up-right') ?>"></i> <?= h($l['label']) ?></a>
    <?php endforeach; ?>
  </div>
  </div></div></div>
<?php endif; ?>

<?php if ($user['is_superadmin']): ?>
  <div class="col-lg"><div class="card h-100 home-tool tone-red"><div class="card-body">
  <div class="home-tool-title"><i class="bi bi-shield-lock"></i> ผู้ดูแลระบบกลาง</div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= h(url('admin/users.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-people"></i> ผู้ใช้งานและสิทธิ์</a>
    <a href="<?= h(url('admin/settings.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-sliders"></i> ตั้งค่าระบบ</a>
    <a href="<?= h(url('admin/audit.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-journal-text"></i> บันทึกการใช้งาน</a>
  </div>
  </div></div></div>
<?php endif; ?>
<?php if ($links || $user['is_superadmin']): ?></div><?php endif; ?>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
