<?php
/** แสดงแดชบอร์ด Power BI ใน iframe (เฉพาะผู้มีสิทธิ์ "ผู้ดูรายงาน" ขึ้นไป) */
require_once __DIR__ . '/bootstrap.php';
require_module(PBI_MODULE, 'viewer');
$url = pbi_url();
$active = 'view';
$pageTitle = pbi_title();
$pageActions = ''
    . '<button type="button" class="btn btn-outline-secondary" id="pbiFull"><i class="bi bi-arrows-fullscreen"></i> เต็มจอ</button>'
    . '<a href="' . h($url) . '" target="_blank" rel="noopener" class="btn btn-outline-primary" title="ถ้าแผนที่ไม่แสดง ให้เปิดในแท็บใหม่"><i class="bi bi-box-arrow-up-right"></i> เปิดในแท็บใหม่</a>'
    . (user_can(PBI_MODULE, 'admin') ? '<a href="settings.php" class="btn btn-light"><i class="bi bi-gear"></i> ตั้งค่า</a>' : '');
require __DIR__ . '/header.php';
?>
<div data-reveal>
  <div class="card pbi-card reveal-item" id="pbiBox">
    <div class="pbi-loading" id="pbiLoading"><div class="spinner-border text-warning"></div><div class="small text-muted mt-2">กำลังโหลดแดชบอร์ด…</div></div>
    <iframe src="<?= h($url) ?>" title="<?= h(pbi_title()) ?>" allowfullscreen loading="eager"
            referrerpolicy="strict-origin-when-cross-origin" onload="document.getElementById('pbiLoading').remove()"></iframe>
  </div>
</div>
<style>
  /* Power BI แสดงที่ซูม 70% (คงที่) · ความสูงเฟรมตามสัดส่วนหน้ารายงาน 16:9 + แถบเครื่องมือด้านล่าง → แสดงครบทั้งหน้า */
  .pbi-card { --z: .7; position: relative; overflow: hidden; height: 600px; margin-bottom: 0; }
  .pbi-card iframe { position: absolute; top: 0; left: 0; border: 0;
    width: calc(100% / var(--z)); height: calc(100% / var(--z)); transform: scale(var(--z)); transform-origin: 0 0; }
  .pbi-loading { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; background: #fff; z-index: 1; }
  .pbi-card:fullscreen { height: 100vh !important; border-radius: 0; }
</style>
<script>
(() => {
  const box = document.getElementById('pbiBox');
  const ZOOM = 0.7, RATIO = 9 / 16, BAR = 36;      // แถบเครื่องมือ Power BI ด้านล่าง ~36px (ก่อนย่อ)
  const fit = () => {
    if (document.fullscreenElement) return;
    const inner = box.clientWidth / ZOOM;              // ความกว้างที่ Power BI เห็น
    box.style.height = Math.round((inner * RATIO + BAR) * ZOOM) + 'px';
  };
  document.getElementById('pbiFull').addEventListener('click', () => (box.requestFullscreen || box.webkitRequestFullscreen || (() => {})).call(box));
  document.addEventListener('fullscreenchange', fit);
  window.addEventListener('resize', fit);
  fit();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
