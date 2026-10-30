// สคริปต์กลางที่ใช้ทุกหน้า

// ยืนยันก่อนส่งฟอร์มที่มี data-confirm (เช่น ปุ่มลบ)
document.querySelectorAll('form[data-confirm]').forEach(f => {
  f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
});

// แสดงตัวอย่างรูปก่อนอัปโหลด: <input type=file data-preview="idของกล่อง">
document.querySelectorAll('input[type=file][data-preview]').forEach(input => {
  input.addEventListener('change', () => {
    const box = document.getElementById(input.dataset.preview);
    if (!box) return;
    box.innerHTML = '';
    [...input.files].forEach(file => {
      if (!file.type.startsWith('image/')) return;
      const img = document.createElement('img');
      img.src = URL.createObjectURL(file);
      img.title = file.name;
      box.appendChild(img);
    });
  });
});

// ย่อรูปในเบราว์เซอร์ก่อนอัปโหลด (ภาพจากมือถือมักใหญ่เกินขีดจำกัดของเซิร์ฟเวอร์)
// ใช้กับ <input type=file accept="image/..."> ทุกช่องที่ไม่ได้ใส่ data-no-shrink
(function () {
  const MAX = 1600, QUALITY = 0.85;
  if (typeof DataTransfer === 'undefined') return;
  function shrink(file) {
    return new Promise(resolve => {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024) return resolve(file);
      const img = new Image();
      const src = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(src);
        const r = Math.min(1, MAX / Math.max(img.width, img.height));
        const c = document.createElement('canvas');
        c.width = Math.round(img.width * r);
        c.height = Math.round(img.height * r);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(b => resolve(b && b.size < file.size
          ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() })
          : file), 'image/jpeg', QUALITY);
      };
      img.onerror = () => { URL.revokeObjectURL(src); resolve(file); };
      img.src = src;
    });
  }
  document.querySelectorAll('input[type=file][accept^="image"]:not([data-no-shrink])').forEach(input => {
    input.addEventListener('change', async () => {
      if (input.dataset.shrinking) return;
      const btns = input.form ? input.form.querySelectorAll('button:not([type=button])') : [];
      btns.forEach(b => b.disabled = true);
      input.dataset.shrinking = '1';
      try {
        const out = await Promise.all([...input.files].map(shrink));
        const dt = new DataTransfer();
        out.forEach(f => dt.items.add(f));
        input.files = dt.files;
      } finally {
        delete input.dataset.shrinking;
        btns.forEach(b => b.disabled = false);
      }
    });
  });
})();

// animation กล่อง: กลุ่ม [data-reveal] เล่นครั้งเดียวเมื่อเลื่อนมาเห็นครั้งแรก — กล่อง .reveal-item ทยอยลอยขึ้น
// (หน้าหลัก: ระบบงาน / ลิงก์ · แดชบอร์ดวัสดุฯ · ภาพรวมศูนย์ดิจิทัลฯ) — ตั้งค่าลดการเคลื่อนไหว = แสดงทันที
(() => {
  const groups = [...document.querySelectorAll('[data-reveal]')];
  if (!groups.length) return;
  const itemsOf = g => [g, ...g.querySelectorAll('.reveal-item')].filter(el => el.classList.contains('reveal-item'));
  const play = g => { g.classList.add('revealed-group'); itemsOf(g).forEach(el => el.classList.add('revealed')); };
  groups.forEach(g => itemsOf(g).forEach((el, i) => el.style.setProperty('--d', (i * 110) + 'ms')));
  if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) { groups.forEach(play); return; }
  const io = new IntersectionObserver(entries => entries.forEach(e => {
    if (e.isIntersecting) { play(e.target); io.unobserve(e.target); }
  }), { threshold: 0.12 });
  groups.forEach(g => io.observe(g));
})();
