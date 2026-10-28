/* ฟังก์ชันใช้ร่วมกันของหน้าบ้าน */
const API = '../api/centers.php';

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const TH_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const TH_MONTHS_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

function parseDate(d) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || '');
  return m ? { y: +m[1], m: +m[2], d: +m[3] } : null;
}
function thaiDate(d, full) {
  const p = parseDate(d);
  if (!p) return '';
  return `${p.d} ${(full ? TH_MONTHS_FULL : TH_MONTHS)[p.m - 1]} ${p.y + 543}`;
}
const fmt = n => Number(n || 0).toLocaleString('th-TH');

async function api(params = '') {
  const r = await fetch(API + params, { headers: { Accept: 'application/json' } });
  const data = await r.json().catch(() => ({ error: 'รูปแบบข้อมูลไม่ถูกต้อง' }));
  if (!r.ok || data.error) throw new Error(data.error || 'โหลดข้อมูลไม่สำเร็จ');
  return data;
}

/** หมุดโลโก้ศูนย์ดิจิทัลชุมชน — ปลายหมุดอยู่ที่ 1.207 × ขนาด (มุมของสี่เหลี่ยมที่หมุน 45°) */
function pinIcon(big, base = '../') {
  const s = big ? 56 : 40;
  return L.divIcon({
    className: '',
    html: `<div class="pin${big ? ' big' : ''}"><img src="${base}assets/img/logo.png" alt=""></div>`,
    iconSize: [s, s],
    iconAnchor: [s / 2, Math.round(s * 1.207)],
    popupAnchor: [0, -Math.round(s * 1.25)],
    tooltipAnchor: [s / 2, -Math.round(s * 0.6)],
  });
}

function baseLayers(map) {
  const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);
  const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19, attribution: 'Tiles &copy; Esri',
  });
  L.control.layers({ 'แผนที่': street, 'ภาพดาวเทียม': sat }).addTo(map);
}

/* ---------- Lightbox ---------- */
const Lightbox = (() => {
  let items = [], idx = 0, el;
  function build() {
    el = document.createElement('div');
    el.className = 'lb';
    el.innerHTML = `<button class="x" aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
      <button class="prev" aria-label="ก่อนหน้า"><i class="bi bi-chevron-left"></i></button>
      <img alt=""><div class="cap"></div>
      <button class="next" aria-label="ถัดไป"><i class="bi bi-chevron-right"></i></button>`;
    document.body.appendChild(el);
    el.querySelector('.x').onclick = close;
    el.querySelector('.prev').onclick = () => show(idx - 1);
    el.querySelector('.next').onclick = () => show(idx + 1);
    el.addEventListener('click', e => { if (e.target === el) close(); });
    document.addEventListener('keydown', e => {
      if (!el.classList.contains('open')) return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowLeft') show(idx - 1);
      if (e.key === 'ArrowRight') show(idx + 1);
    });
    let x0 = null;
    el.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
    el.addEventListener('touchend', e => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) show(idx + (dx < 0 ? 1 : -1));
      x0 = null;
    });
  }
  function show(i) {
    idx = (i + items.length) % items.length;
    const it = items[idx];
    el.querySelector('img').src = it.url;
    el.querySelector('.cap').textContent = (it.caption ? it.caption + ' · ' : '') + `${idx + 1} / ${items.length}`;
    const multi = items.length > 1;
    el.querySelector('.prev').style.display = el.querySelector('.next').style.display = multi ? '' : 'none';
  }
  function open(list, i = 0) {
    if (!el) build();
    items = list;
    show(i);
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    el.classList.remove('open');
    document.body.style.overflow = '';
  }
  return { open };
})();

/** สร้าง HTML แกลเลอรี โดยแสดงไม่เกิน max ภาพ ภาพสุดท้ายแสดง +N */
function galleryHTML(images, group, max, big) {
  if (!images.length) return '';
  const shown = images.slice(0, max);
  const rest = images.length - shown.length;
  return `<div class="gallery${big && images.length > 2 ? ' big' : ''}">` + shown.map((im, i) => {
    const more = rest > 0 && i === shown.length - 1;
    return `<a href="${esc(im.url)}" data-group="${group}" data-index="${i}"${more ? ` class="more-n" data-more="+${rest + 1}"` : ''}>
      <img src="${esc(im.url)}" alt="${esc(im.caption)}" loading="lazy"></a>`;
  }).join('') + '</div>';
}

/** ผูก click ของแกลเลอรีเข้ากับ lightbox: groups = { key: images[] } */
function bindGalleries(root, groups) {
  root.addEventListener('click', e => {
    const a = e.target.closest('a[data-group]');
    if (!a) return;
    e.preventDefault();
    Lightbox.open(groups[a.dataset.group] || [], +a.dataset.index);
  });
}
