// ระบบบริหารจัดการ EA — ตัวช่วยฝั่งหน้าเว็บ
(() => {
  // เปลี่ยนอำเภอ → ล้างตำบล แล้วส่งฟอร์มค้นหาเพื่อโหลดรายชื่อตำบลของอำเภอนั้น
  document.querySelectorAll('select[data-autosubmit]').forEach(sel => {
    sel.addEventListener('change', () => {
      const t = sel.form.querySelector('select[name$="tambon"]');
      if (t) { t.value = ''; }
      if (sel.form.method.toLowerCase() === 'get') { sel.form.submit(); }
    });
  });
  // เลือกทั้งหมด + นับจำนวนที่เลือก
  document.querySelectorAll('[data-check-all]').forEach(all => {
    const boxes = () => document.querySelectorAll(all.dataset.checkAll);
    const count = document.querySelector('[data-check-count]');
    const update = () => {
      const n = [...boxes()].filter(b => b.checked).length;
      if (count) { count.textContent = n.toLocaleString(); }
      document.querySelectorAll('[data-need-check]').forEach(b => { b.disabled = n === 0; });
    };
    all.addEventListener('change', () => { boxes().forEach(b => { b.checked = all.checked; }); update(); });
    document.addEventListener('change', e => { if (e.target.matches(all.dataset.checkAll)) { update(); } });
    update();
  });
})();
