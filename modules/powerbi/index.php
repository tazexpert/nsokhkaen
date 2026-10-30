<?php
/** แสดงแดชบอร์ด Power BI ใน iframe (เฉพาะผู้มีสิทธิ์ "ผู้ดูรายงาน" ขึ้นไป) */
require_once __DIR__ . '/bootstrap.php';
require_module(PBI_MODULE, 'viewer');
$url = pbi_url();
$active = 'view';
$pageTitle = pbi_title();
$pageActions = '<div class="btn-group" role="group" aria-label="ซูม">'
    . '<button type="button" class="btn btn-outline-secondary" data-zoom="-1" title="ย่อ"><i class="bi bi-zoom-out"></i></button>'
    . '<button type="button" class="btn btn-outline-secondary" id="pbiZoom" title="กลับเป็น 70%" style="min-width:64px">70%</button>'
    . '<button type="button" class="btn btn-outline-secondary" data-zoom="1" title="ขยาย"><i class="bi bi-zoom-in"></i></button></div>'
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
  /* เฟรมสูงเต็มพื้นที่ใต้หัวข้อจนสุดจอ (คำนวณด้วย JS) · เนื้อหา Power BI ย่อ/ขยายด้วย --z (ค่าเริ่มต้น 70%) */
  .pbi-card { position: relative; overflow: hidden; height: calc(100vh - 200px); min-height: 420px; margin-bottom: 0; }
  .pbi-card iframe { position: absolute; top: 0; left: 0; border: 0;
    width: calc(100% / var(--z, .7)); height: calc(100% / var(--z, .7)); transform: scale(var(--z, .7)); transform-origin: 0 0; }
  .pbi-loading { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; background: #fff; z-index: 1; }
  .pbi-card:fullscreen { height: 100vh !important; border-radius: 0; }
</style>
<script>
(() => {
  const box = document.getElementById('pbiBox'), label = document.getElementById('pbiZoom');
  const DEF = 70, MIN = 40, MAX = 100;
  let zoom = DEF;
  try { zoom = Math.min(MAX, Math.max(MIN, parseInt(localStorage.getItem('pbiZoom'), 10) || DEF)); } catch (e) {}
  const apply = () => {
    box.style.setProperty('--z', zoom / 100); label.textContent = zoom + '%';
    try { localStorage.setItem('pbiZoom', zoom); } catch (e) {}
  };
  const fit = () => {                       // สูงจนถึงขอบล่างของจอ (เว้น 16px)
    if (document.fullscreenElement) return;
    box.style.height = Math.max(420, window.innerHeight - box.getBoundingClientRect().top - window.scrollY - 16) + 'px';
  };
  document.querySelectorAll('[data-zoom]').forEach(b => b.addEventListener('click', () => { zoom = Math.min(MAX, Math.max(MIN, zoom + 10 * b.dataset.zoom)); apply(); }));
  label.addEventListener('click', () => { zoom = DEF; apply(); });
  document.getElementById('pbiFull').addEventListener('click', () => (box.requestFullscreen || box.webkitRequestFullscreen || (() => {})).call(box));
  document.addEventListener('fullscreenchange', fit);
  window.addEventListener('resize', fit);
  box.addEventListener('animationend', fit);   // คำนวณใหม่หลัง animation กล่องลอยขึ้นจบ
  apply(); fit();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
