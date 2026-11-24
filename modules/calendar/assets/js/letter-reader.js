/**
 * อ่านข้อความจากหนังสือ (คัดลอกจากหนังสือเชิญ / บันทึกสั่งการ) แล้วกรอกฟอร์มเพิ่มกิจกรรมให้อัตโนมัติ — ทำงานในเบราว์เซอร์ ไม่ส่งข้อความไปที่ใด
 *   กิจกรรม   : "เข้าร่วม…" (หรือข้อความหลัง "ขอเชิญท่าน…") จนถึง "ในวันที่ / ในระหว่างวันที่ / วันที่ / ณ"
 *   วันที่     : "๒๙ - ๓๐ มกราคม ๒๕๗๐" / "๒๙ มกราคม - ๒ กุมภาพันธ์ ๒๕๗๐" / "๒๙ มกราคม ๒๕๗๐" (เลขไทย · พ.ศ. · ชื่อเดือนเต็ม/ย่อ)
 *   เวลา      : "เวลา ๐๙.๐๐ - ๑๖.๓๐ น." (ถ้ามี)
 *   สถานที่    : หลัง "ณ" จนถึง "โดย / ทั้งนี้ / จึง… / และขอ… / ตามกำหนดการ / เวลา"
 *   ผู้รับผิดชอบ: บรรทัด "เรียน …" บรรทัดสุดท้าย (บันทึกสั่งการ) — ตำแหน่งย่อ (ผอ กว / ผอ.กป / สถจ.) หรือชื่อ · "ทุกคน"
 *   ออนไลน์    : URL → ลิงก์ประกอบ · Meeting ID / Passcode → หมายเหตุ · ไม่มี "ณ" = สถานที่ "ออนไลน์"
 *   ภาพ        : วาง (Ctrl+V) / เลือกไฟล์ภาพ → อ่านตัวอักษร (OCR: tesseract.js ภาษาไทย+อังกฤษ โหลดจาก CDN เมื่อใช้ครั้งแรก)
 *                → ใส่ข้อความในกล่องแล้วอ่านต่อ (ตัวเลขไทยจากภาพหน้าจอมักอ่านผิด — ให้ผู้ใช้ตรวจ)
 *   ข้อมูลบุคลากร: data-users ของกล่อง (#letterReader) = [{id, name, first, abbrs:[...]}]
 */
(() => {
  const box = document.getElementById('letterReader');
  if (!box) return;
  const users = JSON.parse(box.dataset.users || '[]');
  const form = document.getElementById('evForm');
  const field = n => form.querySelector('[name="' + n + '"]');
  const TH = '๐๑๒๓๔๕๖๗๘๙';
  const MONTHS = [['มกราคม', 'ม.ค.'], ['กุมภาพันธ์', 'ก.พ.'], ['มีนาคม', 'มี.ค.'], ['เมษายน', 'เม.ย.'], ['พฤษภาคม', 'พ.ค.'], ['มิถุนายน', 'มิ.ย.'],
    ['กรกฎาคม', 'ก.ค.'], ['สิงหาคม', 'ส.ค.'], ['กันยายน', 'ก.ย.'], ['ตุลาคม', 'ต.ค.'], ['พฤศจิกายน', 'พ.ย.'], ['ธันวาคม', 'ธ.ค.']];
  const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const MON = MONTHS.flat().sort((a, b) => b.length - a.length).map(esc).join('|');
  const monthNo = m => MONTHS.findIndex(x => x.includes(m.trim())) + 1;
  const pad = n => String(n).padStart(2, '0');
  const year = y => { y = +y; if (y < 100) y += 2500; return y > 2400 ? y - 543 : y; };   // พ.ศ. → ค.ศ. · 70 → 2570
  const ymd = (y, m, d) => `${year(y)}-${pad(m)}-${pad(+d)}`;
  const norm = s => s.replace(/[\s.]/g, '');                                              // "ผอ. กว" → "ผอกว"

  const setVal = (name, val, isDate) => {
    const el = field(name);
    if (!el) return;
    if (el._flatpickr) el._flatpickr.setDate(val, true, isDate ? 'Y-m-d' : 'H.i');
    else el.value = val;
    const shown = el._flatpickr && el._flatpickr.altInput ? el._flatpickr.altInput : el;
    shown.classList.add('lr-filled');
    setTimeout(() => shown.classList.remove('lr-filled'), 2500);
  };

  const read = raw => {
    const text = raw.replace(/[๐-๙]/g, c => TH.indexOf(c)).replace(/\r/g, '');
    const flat = text.replace(/\s*\n\s*/g, ' ').replace(/[ \t]+/g, ' ').trim();
    const out = {};
    // กิจกรรม
    const STOP = '(?=\\s*(?:ใน(?:ระหว่าง)?วันที่|ระหว่างวันที่|วันที่|ณ\\s|เวลา\\s*\\d|โดยมี|$))';
    let m = flat.match(new RegExp('(เข้าร่วม[\\s\\S]*?)' + STOP))
      || flat.match(new RegExp('ขอเชิญ(?:ท่าน)?(?:\\s*หรือ\\s*ผู้แทน(?:หน่วยงาน)?)?\\s*([\\s\\S]*?)' + STOP));
    if (!m) {                                         // "๑. หลักสูตร… “…” ในวันพฤหัสบดีที่ …" — ข้อความก่อน "ใน(วัน…)ที่" ตั้งแต่เลขข้อ/ต้นบรรทัด
      const k = flat.search(/\s*ใน(?:วัน[ก-๙]*ที่|ระหว่างวันที่|วันที่)/);
      if (k > 0) {
        let pre = flat.slice(0, k), at = -1, x;
        const re = /(?:^|\s)\d+\.\s*/g;                 // เลขข้อสุดท้าย "๑. " ก่อนวันที่
        while ((x = re.exec(pre))) at = x.index + x[0].length;
        if (at < 0 && pre.includes('ดังนี้')) at = pre.lastIndexOf('ดังนี้') + 6;
        pre = pre.slice(Math.max(at, 0)).trim();
        if (pre) m = [null, pre.length > 255 ? pre.slice(-255) : pre];
      }
    }
    if (m && m[1].trim()) out.title = m[1].trim().slice(0, 255);
    // วันที่ (ช่วงที่อยู่หลัง "วันที่" ก่อน ไม่มีจึงค้นทั้งข้อความ)
    const area = (flat.match(/วัน[ก-๙]*ที่\s*([\s\S]{0,80})/) || [, flat])[1] + ' ' + flat;   // วันที่ / วันพฤหัสบดีที่
    const D = '(\\d{1,2})', Y = '(?:พ\\.ศ\\.\\s*)?(\\d{4}|\\d{2})', SEP = '\\s*(?:-|–|ถึง(?:วันที่)?)\\s*';
    if ((m = area.match(new RegExp(D + '\\s*(' + MON + ')\\s*(?:' + Y + ')?' + SEP + D + '\\s*(' + MON + ')\\s*' + Y)))) {
      out.start = ymd(m[3] || m[6], monthNo(m[2]), m[1]);
      out.end = ymd(m[6], monthNo(m[5]), m[4]);
    } else if ((m = area.match(new RegExp(D + SEP + D + '\\s*(' + MON + ')\\s*' + Y)))) {
      out.start = ymd(m[4], monthNo(m[3]), m[1]);
      out.end = ymd(m[4], monthNo(m[3]), m[2]);
    } else if ((m = area.match(new RegExp(D + '\\s*(' + MON + ')\\s*' + Y)))) {
      out.start = ymd(m[3], monthNo(m[2]), m[1]);
    }
    // เวลา
    if ((m = flat.match(/เวลา\s*(\d{1,2})[.:](\d{2})(?:\s*(?:น\.)?\s*(?:-|–|ถึง)\s*(\d{1,2})[.:](\d{2}))?/))) {
      out.t1 = pad(m[1]) + '.' + m[2];
      if (m[3]) out.t2 = pad(m[3]) + '.' + m[4];
    }
    // สถานที่
    if ((m = flat.match(/(?:^|\s)ณ\s*([\s\S]*?)(?=\s*(?:โดย|ทั้งนี้|จึงเรียน|จึงขอ|และขอ|ตามกำหนดการ|เวลา\s*\d|ใน(?:ระหว่าง)?วันที่|ระหว่างวันที่|สิ่งที่ส่งมาด้วย|เรียน|เพื่อ(?:ทราบ|โปรด|เข้าร่วม|พิจารณา|ดำเนินการ)|$))/))) {
      out.location = m[1].replace(/[,\s]+$/, '').trim().slice(0, 255);
    }
    // ออนไลน์: URL / Meeting ID / Passcode
    if ((m = flat.match(/https?:\/\/[^\s"'<>)]+/))) out.link = m[0].replace(/[.,]+$/, '');
    const mid = flat.match(/Meeting\s*ID\s*:?\s*([\d ]{6,})/i), pass = flat.match(/Pass\s*code\s*:?\s*(\S+)/i);
    if (mid || pass) out.note = [mid ? 'Meeting ID: ' + mid[1].trim() : '', pass ? 'Passcode: ' + pass[1] : ''].filter(Boolean).join('\n');
    if (!out.location && (mid || /zoom|webex|teams|ออนไลน์/i.test(flat))) out.location = 'ออนไลน์' + (/zoom/i.test(flat) ? ' (Zoom)' : '');
    // ผู้รับผิดชอบ: "เรียน …" บรรทัดสุดท้าย (บันทึกสั่งการท้ายหนังสือ)
    const lines = text.split('\n').map(l => l.trim()).filter(l => /^เรียน/.test(l));
    if (lines.length) {
      const line = lines[lines.length - 1].replace(/^เรียน\s*/, '');
      const n = norm(line);
      out.people = [];
      if (/ทุกคน/.test(line)) out.all = true;
      users.forEach(u => {
        // ตำแหน่งย่อ (ผอ กว / ผอ.กป …) · "สถจ." = สถิติจังหวัด / รก.สถิติจังหวัด
        const abbr = u.abbrs.find(a => n.includes(norm(a))) || (/สถจ/.test(n) ? u.abbrs.find(a => /สถิติจังหวัด/.test(a)) : undefined);
        if (abbr || n.includes(norm(u.name)) || (u.first.length > 2 && n.includes(norm(u.first)))) out.people.push({ id: u.id, abbr });
      });
      out.toLine = line;
    }
    return out;
  };

  const apply = r => {
    const done = [], miss = [];
    if (r.title) { setVal('title', r.title); done.push('กิจกรรม'); } else miss.push('กิจกรรม');
    if (r.start) {
      setVal('event_date', r.start, true);
      if (r.end && r.end !== r.start) setVal('end_date', r.end, true); else if (field('end_date')._flatpickr) field('end_date')._flatpickr.clear();
      done.push(r.end && r.end !== r.start ? 'วันที่–ถึงวันที่' : 'วันที่');
    } else miss.push('วันที่');
    if (r.t1) {
      const allDay = document.getElementById('allDay');
      if (allDay.checked) allDay.click();
      setVal('start_time', r.t1);
      if (r.t2) setVal('end_time', r.t2);
      done.push('เวลา');
    }
    if (r.location) { setVal('location', r.location); done.push('สถานที่'); } else miss.push('สถานที่');
    if (r.link) { setVal('link', r.link); done.push('ลิงก์'); }
    if (r.note) { const n = field('note'); n.value = (n.value.trim() ? n.value.trim() + '\n' : '') + r.note; done.push('หมายเหตุ (Meeting ID)'); }
    if (r.all) document.querySelector('[data-add="*"]')?.click();
    if (r.people && (r.people.length || r.all)) {
      r.people.forEach(p => {
        const cb = form.querySelector('#userPick input[name="users[]"][value="' + p.id + '"]');
        if (cb && !cb.checked) cb.click();
        if (p.abbr) {                                  // แสดงในตารางเป็นตำแหน่งที่อ้างถึง (เช่น ผอ.กว)
          form.querySelectorAll('input[name="show_as[' + p.id + '][]"]').forEach(x => { x.checked = x.value === p.abbr; });
        }
      });
      done.push('ผู้รับผิดชอบ ' + (r.all ? 'ทุกคน' : r.people.length + ' คน'));
    } else miss.push('ผู้รับผิดชอบ' + (r.toLine ? ' ("' + r.toLine + '")' : ' (ไม่พบบรรทัด "เรียน …")'));
    const msg = document.getElementById('lrResult');
    msg.className = 'small mt-2 ' + (miss.length ? 'text-warning-emphasis' : 'text-success');
    msg.innerHTML = (done.length ? '<i class="bi bi-check2-circle"></i> กรอกแล้ว: ' + done.join(' · ') : '')
      + (miss.length ? (done.length ? '<br>' : '') + '<i class="bi bi-exclamation-triangle"></i> ไม่พบ: ' + miss.join(' · ') + ' — กรุณากรอกเอง' : '')
      + '<br><span class="text-muted">ตรวจสอบข้อมูลอีกครั้งก่อนกดบันทึก</span>';
  };

  document.getElementById('lrRead').addEventListener('click', () => {
    const t = document.getElementById('lrText').value;
    if (!t.trim()) { document.getElementById('lrText').focus(); return; }
    apply(read(t));
  });
  window.calLetterRead = read;                           // ใช้ทดสอบ

  // ---- อ่านตัวอักษรจากภาพ (OCR) ----
  const ocrMsg = document.getElementById('lrOcr');
  let tess = null;
  const loadTess = () => tess || (tess = new Promise((ok, fail) => {
    if (window.Tesseract) return ok(window.Tesseract);
    const sc = document.createElement('script');
    sc.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
    sc.integrity = 'sha384-GJqSu7vueQ9qN0E9yLPb3Wtpd7OrgK8KmYzC8T1IysG1bcvxvIO4qtYR/D3A991F';
    sc.crossOrigin = 'anonymous';
    sc.onload = () => ok(window.Tesseract);
    sc.onerror = () => { tess = null; fail(new Error('โหลดตัวอ่านภาพไม่ได้ (ตรวจการเชื่อมต่ออินเทอร์เน็ต)')); };
    document.head.appendChild(sc);
  }));
  const ocr = async file => {
    if (!file || !/^image\//.test(file.type)) return;
    const ta = document.getElementById('lrText');
    ocrMsg.className = 'small mt-1 text-primary';
    ocrMsg.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังอ่านตัวอักษรจากภาพ… (ครั้งแรกโหลดข้อมูลภาษาไทยประมาณ 2 MB)';
    try {
      const T = await loadTess();
      const { data } = await T.recognize(file, 'tha+eng', {
        logger: m => { if (m.status === 'recognizing text') ocrMsg.lastChild.textContent = ' กำลังอ่านตัวอักษรจากภาพ… ' + Math.round(m.progress * 100) + '%'; },
      });
      const txt = data.text.replace(/\s+(?=[ะ-ฺ็-๎])/g, '').trim();   // ตัดช่องว่างหน้าสระ/วรรณยุกต์ที่ OCR แทรก
      ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + txt;
      ocrMsg.className = 'small mt-1 text-warning-emphasis';
      ocrMsg.innerHTML = '<i class="bi bi-exclamation-triangle"></i> อ่านจากภาพแล้ว — <b>ตรวจ/แก้ข้อความในกล่อง (โดยเฉพาะตัวเลขวันที่และเวลา)</b> แล้วกด "อ่านข้อความและกรอกฟอร์ม"';
      ta.focus();
    } catch (e) {
      ocrMsg.className = 'small mt-1 text-danger';
      ocrMsg.textContent = 'อ่านภาพไม่สำเร็จ: ' + e.message;
    }
  };
  document.getElementById('lrDrop').addEventListener('paste', e => {
    const item = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
    if (item) { e.preventDefault(); ocr(item.getAsFile()); }
  });
  document.getElementById('lrImage').addEventListener('change', e => { ocr(e.target.files[0]); e.target.value = ''; });
  const drop = document.getElementById('lrDrop');
  drop.addEventListener('dragover', e => { if ([...e.dataTransfer.items].some(i => i.type.startsWith('image/'))) { e.preventDefault(); drop.classList.add('over'); } });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', e => { const f = [...e.dataTransfer.files].find(x => x.type.startsWith('image/')); if (f) { e.preventDefault(); drop.classList.remove('over'); ocr(f); } });
})();
