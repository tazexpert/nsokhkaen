/**
 * ตัวเลือกวันที่/เวลาแบบไทย (flatpickr)
 *   .thai-date  แสดง "29 ตุลาคม 2569" (พ.ศ.) — ค่าที่ส่งยังเป็น Y-m-d
 *   .thai-time  เวลา 24 ชม. แบบไทย "09.00" — ฝั่งเซิร์ฟเวอร์แปลงเป็น 09:00:00 ตอนบันทึก
 */
(() => {
  if (typeof flatpickr === 'undefined') return;   // โหลด CDN ไม่ได้ → ช่องข้อความธรรมดา
  const MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  const pad = n => String(n).padStart(2, '0');
  const locale = (flatpickr.l10ns && flatpickr.l10ns.th) || 'default';

  // หัวปฏิทินแสดงปี พ.ศ. แทน ค.ศ.
  const beYear = fp => {
    const box = fp.calendarContainer.querySelector('.flatpickr-current-month');
    if (!box) return;
    let s = box.querySelector('.be-year');
    if (!s) { s = document.createElement('span'); s.className = 'be-year'; box.appendChild(s); }
    s.textContent = fp.currentYear + 543;
  };

  document.querySelectorAll('input.thai-date').forEach(el => {
    flatpickr(el, {
      locale, altInput: true, altFormat: 'thai', dateFormat: 'Y-m-d', allowInput: false, disableMobile: true,
      formatDate: (d, f) => f === 'Y-m-d'
        ? `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
        : `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear() + 543}`,
      onReady: (s, d, fp) => { beYear(fp); if (el.required) fp.altInput.required = true; },
      onMonthChange: (s, d, fp) => beYear(fp),
      onYearChange: (s, d, fp) => beYear(fp),
      onOpen: (s, d, fp) => beYear(fp),
    });
  });

  document.querySelectorAll('input.thai-time').forEach(el => {
    flatpickr(el, {
      locale, enableTime: true, noCalendar: true, time_24hr: true, minuteIncrement: 5,
      dateFormat: 'H.i', allowInput: true, disableMobile: true,
      parseDate: s => {                            // รับ "9.00", "09:00", "0900"
        const m = String(s).replace(/น\.?/g, '').trim().match(/^(\d{1,2})[.:]?(\d{2})$/);
        if (!m) return undefined;
        const d = new Date(); d.setHours(+m[1], +m[2], 0, 0); return d;
      },
    });
  });

  document.querySelectorAll('[data-clear-date]').forEach(btn => btn.addEventListener('click', () => {
    const el = document.querySelector(`[name="${btn.dataset.clearDate}"]`);
    if (el && el._flatpickr) el._flatpickr.clear(); else if (el) el.value = '';
  }));
})();
