<?php
/** แสดงแดชบอร์ด Power BI ใน iframe (เฉพาะผู้มีสิทธิ์ "ผู้ดูรายงาน" ขึ้นไป) */
require_once __DIR__ . '/bootstrap.php';
require_module(PBI_MODULE, 'viewer');
$url = pbi_url();
$active = 'view';
$pageTitle = pbi_title();
$pageActions = '<button type="button" class="btn btn-outline-secondary" id="pbiFull"><i class="bi bi-arrows-fullscreen"></i> เต็มจอ</button>'
    . '<a href="' . h($url) . '" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i> เปิดในแท็บใหม่</a>'
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
<p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle"></i> ข้อมูลแสดงจาก Power BI โดยตรง — ถ้าไม่แสดง ให้กด "เปิดในแท็บใหม่"</p>
<style>
  .pbi-card { position: relative; overflow: hidden; height: calc(100vh - 250px); min-height: 480px; }
  .pbi-card iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
  .pbi-loading { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; background: #fff; }
  .pbi-card:fullscreen { height: 100vh; border-radius: 0; }
  @media (max-width: 575.98px) { .pbi-card { height: 75vh; min-height: 360px; } }
</style>
<script>
document.getElementById('pbiFull').addEventListener('click', () => {
  const box = document.getElementById('pbiBox');
  (box.requestFullscreen || box.webkitRequestFullscreen || (() => {})).call(box);
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
