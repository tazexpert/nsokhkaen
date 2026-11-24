/**
 * อ่านข้อความจากหนังสือ (คัดลอกจากหนังสือเชิญ / บันทึกสั่งการ) แล้วกรอกฟอร์มเพิ่มกิจกรรมให้อัตโนมัติ — ทำงานในเบราว์เซอร์ ไม่ส่งข้อความไปที่ใด
 *   กิจกรรม   : "เข้าร่วม…" (หรือข้อความหลัง "ขอเชิญท่าน…") จนถึง "ในวันที่ / ในระหว่างวันที่ / วันที่ / ณ"
 *   วันที่     : "๒๙ - ๓๐ มกราคม ๒๕๗๐" / "๒๙ มกราคม - ๒ กุมภาพันธ์ ๒๕๗๐" / "๒๙ มกราคม ๒๕๗๐" (เลขไทย · พ.ศ. · ชื่อเดือนเต็ม/ย่อ)
 *   เวลา      : "เวลา ๐๙.๐๐ - ๑๖.๓๐ น." (ถ้ามี)
 *   สถานที่    : หลัง "ณ" จนถึง "โดย / ทั้งนี้ / จึง… / และขอ… / ตามกำหนดการ / เวลา"
 *   ผู้รับผิดชอบ: บรรทัด "เรียน …" บรรทัดสุดท้าย (บันทึกสั่งการ) — ตำแหน่งย่อ (ผอ กว / ผอ.กป / สถจ.) หรือชื่อ · "ทุกคน"
 *   ออนไลน์    : URL → ลิงก์ประกอบ · Meeting ID / Passcode → หมายเหตุ · ไม่มี "ณ" = สถานที่ "ออนไลน์"
 *   ภาพ        : วาง (Ctrl+V) / เลือกไฟล์ภาพ → อ่านตัวอักษร (OCR: tesseract.js ภาษาไทย+อังกฤษ โหลดจาก CDN เมื่อใช้ครั้งแรก)
 *                ขยายภาพ 3 เท่า + ขอบขาว · แก้ตัวเลขไทยด้วยการเทียบรูปทรงกับ ๐–๙ (TH Sarabun New) → ใส่ข้อความในกล่องให้ตรวจ
 *   ข้อมูลบุคลากร: data-users ของกล่อง (#letterReader) = [{id, name, first, abbrs:[...]}]
 */
(() => {
  const box = document.getElementById('letterReader');
  if (!box) return;
  const users = JSON.parse(box.dataset.users || '[]');
  const form = document.getElementById(box.dataset.form || 'evForm');   // ฟอร์มที่จะกรอก (เพิ่มกิจกรรม / เวรกิจกรรม)
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
    const STOP = '(?=\\s*(?:ใน(?:ระหว่าง)?วัน[ก-๙]*ที่|ระหว่างวันที่|วัน[ก-๙]*ที่|ณ\\s|เวลา\\s*\\d|โดยมี|$))';   // วันที่ / วันอังคารที่
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
        pre = pre.replace(/\s+ณ\s[\s\S]*$/, '').trim();      // ชื่อกิจกรรมหยุดก่อน "ณ …" (สถานที่)
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
    if ((m = flat.match(/(?:^|\s)ณ\s*([\s\S]*?)(?=\s*(?:โดย|ทั้งนี้|จึงเรียน|จึงขอ|และขอ|ตามกำหนดการ|เวลา\s*\d|ใน(?:ระหว่าง)?วัน[ก-๙]*ที่|ระหว่างวันที่|วัน[ก-๙]*ที่\s*\d|สิ่งที่ส่งมาด้วย|เรียน|(?:การ)?แต่งกาย|เพื่อ(?:ทราบ|โปรด|เข้าร่วม|พิจารณา|ดำเนินการ)|$))/))) {
      const loc = m[1].replace(/[,.\s]+$/, '').trim();
      if (loc) out.location = ('ณ ' + loc).slice(0, 255);                      // คง "ณ" ไว้หน้าสถานที่ตามหนังสือ
    }
    // การแต่งกาย (หน้าเวรกิจกรรม): "แต่งกาย(ด้วย)ชุด…" / "การแต่งกาย : …"
    if ((m = flat.match(/แต่งกาย\s*(?:ด้วย|โดย)?\s*:?\s*([\s\S]*?)(?=\s*(?:ทั้งนี้|จึงเรียน|จึงขอ|และขอ|ตามกำหนดการ|เรียน|ณ\s|เวลา\s*\d|$)|[.。](?:\s|$))/))) {
      const d = m[1].replace(/[,\s]+$/, '').trim();
      if (d) out.dress = d.slice(0, 255);
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
      const ed = field('end_date');
      if (ed && r.end && r.end !== r.start) setVal('end_date', r.end, true); else if (ed && ed._flatpickr) ed._flatpickr.clear();
      done.push(ed && r.end && r.end !== r.start ? 'วันที่–ถึงวันที่' : 'วันที่');
    } else miss.push('วันที่');
    if (r.t1) {
      const allDay = document.getElementById('allDay');
      if (allDay && allDay.checked) allDay.click();
      setVal('start_time', r.t1);
      if (r.t2) setVal('end_time', r.t2);
      done.push('เวลา');
    }
    if (r.location) { setVal('location', r.location); done.push('สถานที่'); } else miss.push('สถานที่');
    if (r.dress && field('dress')) { setVal('dress', r.dress); done.push('การแต่งกาย'); }
    if (r.link && field('link')) { setVal('link', r.link); done.push('ลิงก์'); }
    const n = field('note');
    if (r.note && n) { n.value = (n.value.trim() ? n.value.trim() + '\n' : '') + r.note; done.push('หมายเหตุ (Meeting ID)'); }
    const pick = form.querySelector('#userPick');            // หน้าเวรกิจกรรม: ผู้รับผิดชอบมาจากคิวเวร ไม่ต้องเลือก
    if (pick && r.all) document.querySelector('[data-add="*"]')?.click();
    if (!pick) { /* ข้าม */ }
    else if (r.people && (r.people.length || r.all)) {
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
  // ---- แก้ตัวเลขไทยหลัง OCR: แยกก้อนหมึกของตัวเลขแต่ละตัว แล้วเทียบรูปทรงกับ ๐–๙ (ฟอนต์ TH Sarabun New) ----
  //   tesseract อ่านเลขไทยจากภาพหน้าจอผิดบ่อย (๑๗→๑๓, ๖→๒, ๙→๕) แต่หาตำแหน่งได้ — จึงใช้ตำแหน่งจาก OCR แล้วจำแนกรูปทรงเอง
  const DIG = '๐๑๒๓๔๕๖๗๘๙', CONF = 'ฒซ«»beoใไจ', RUNC = DIG + CONF + '.:-–', GRID = 24, SCALE = 3, PAD = 40;
  let tpls = null;
  const featOf = (get, w, h) => {                     // ครอบเฉพาะหมึก → ย่อ/ขยายเป็น 24×24 · สัดส่วนกว้าง/สูง
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const cx = c.getContext('2d'), id = cx.createImageData(w, h);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const k = (y * w + x) * 4, v = get(x, y) ? 255 : 0; id.data[k] = id.data[k + 1] = id.data[k + 2] = v; id.data[k + 3] = 255; }
    cx.putImageData(id, 0, 0);
    const g = document.createElement('canvas'); g.width = g.height = GRID;
    const gx = g.getContext('2d'); gx.imageSmoothingEnabled = true; gx.drawImage(c, 0, 0, GRID, GRID);
    const d = gx.getImageData(0, 0, GRID, GRID).data, a = new Float32Array(GRID * GRID);
    let mean = 0;
    for (let q = 0; q < a.length; q++) { a[q] = d[q * 4] / 255; mean += a[q]; }
    mean /= a.length; let nn = 0;
    for (let q = 0; q < a.length; q++) { a[q] -= mean; nn += a[q] * a[q]; }
    return { a, n: Math.sqrt(nn) || 1, ar: w / h };
  };
  const bboxFeat = (mask, W, H) => {                   // mask: (x,y)=>bool ในกรอบ W×H → ตัดขอบว่าง
    let x0 = W, x1 = -1, y0 = H, y1 = -1;
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) if (mask(x, y)) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    if (x1 < 0) return null;
    return featOf((x, y) => mask(x + x0, y + y0), x1 - x0 + 1, y1 - y0 + 1);
  };
  const loadTpls = async () => {
    if (tpls) return tpls;
    const base = (typeof BASE_URL_JS !== 'undefined' ? BASE_URL_JS : '/') + 'assets/fonts/';
    const fonts = [new FontFace('LrSarabun', 'url(' + base + 'THSarabunNew.ttf)'), new FontFace('LrSarabun', 'url(' + base + 'THSarabunNew-Bold.ttf)', { weight: '700' })];
    await Promise.all(fonts.map(f => f.load().then(ff => document.fonts.add(ff)).catch(() => null)));
    tpls = [];
    for (const wt of ['400', '700']) for (const size of [40, 64]) for (const d of DIG) {
      const c = document.createElement('canvas'); c.width = c.height = 120;
      const cx = c.getContext('2d'); cx.fillStyle = '#000'; cx.fillRect(0, 0, 120, 120);
      cx.fillStyle = '#fff'; cx.font = wt + ' ' + size + 'px LrSarabun, "TH Sarabun New", sans-serif'; cx.textBaseline = 'top'; cx.fillText(d, 10, 0);
      const px = cx.getImageData(0, 0, 120, 120).data;
      const f = bboxFeat((x, y) => px[(y * 120 + x) * 4] > 128, 120, 120);
      if (f) tpls.push({ d, ...f });
    }
    tpls.asp = tpls.reduce((s, t) => s + t.ar, 0) / tpls.length;
    return tpls;
  };
  const classify = f => {
    let best = -9, bd = '?';
    for (const t of tpls) {
      let dot = 0; for (let q = 0; q < f.a.length; q++) dot += f.a[q] * t.a[q];
      const sc = dot / (f.n * t.n) - 0.5 * Math.abs(Math.log(f.ar / t.ar));
      if (sc > best) { best = sc; bd = t.d; }
    }
    return bd;
  };
  // ป้ายชื่อก้อนหมึก (8 ทิศ) ในกรอบบรรทัด
  const label = (ink, W, x0, y0, w, h) => {
    const lab = new Int32Array(w * h), comps = [];
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      if (lab[y * w + x] || !ink[(y + y0) * W + x + x0]) continue;
      const id = comps.length + 1, st = [y * w + x]; lab[y * w + x] = id;
      const c = { id, x0: x, x1: x, y0: y, y1: y, n: 0 };
      while (st.length) {
        const p = st.pop(), px = p % w, py = (p - px) / w; c.n++;
        if (px < c.x0) c.x0 = px; if (px > c.x1) c.x1 = px; if (py < c.y0) c.y0 = py; if (py > c.y1) c.y1 = py;
        for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
          const nx = px + dx, ny = py + dy;
          if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
          const q = ny * w + nx;
          if (!lab[q] && ink[(ny + y0) * W + nx + x0]) { lab[q] = id; st.push(q); }
        }
      }
      comps.push(c);
    }
    return { lab, comps, w };
  };
  const runText = (L, xa, xb, widen) => {
    const { lab, comps, w } = L, ov = (c, a, b) => Math.min(c.x1 + 1, b) - Math.max(c.x0, a);
    const core = comps.filter(c => ov(c, xa, xb) >= 0.3 * (c.x1 - c.x0 + 1));
    if (!core.length) return '';
    const tall = core.reduce((m, c) => (c.y1 - c.y0 > m.y1 - m.y0 ? c : m)), H = tall.y1 - tall.y0 + 1;
    const top = tall.y0 - H * 0.15, bot = tall.y1 + H * 0.15, xa2 = widen ? xa - H * 0.7 : xa;
    const raw = comps.filter(c => { const cy = (c.y0 + c.y1) / 2; return cy >= top && cy <= bot && c.n >= 6 && ov(c, xa2, xb) >= 0.3 * (c.x1 - c.x0 + 1); })
      .sort((a, b) => a.x0 - b.x0);
    const sel = [];                                    // ตัวเลขที่ขาดเป็นหลายก้อน (ภาพความละเอียดต่ำ) — ก้อนที่ซ้อนกันแนวนอนรวมเป็นตัวเดียว
    for (const c of raw) {
      const g = sel[sel.length - 1];
      if (g && c.x0 <= g.x1 - 2) { g.x1 = Math.max(g.x1, c.x1); g.y0 = Math.min(g.y0, c.y0); g.y1 = Math.max(g.y1, c.y1); g.ids.add(c.id); g.n += c.n; }
      else sel.push({ x0: c.x0, x1: c.x1, y0: c.y0, y1: c.y1, n: c.n, ids: new Set([c.id]) });
    }
    const Wd = H * tpls.asp;
    let out = '', prev = null;
    for (const c of sel) {
      if (prev !== null && c.x0 - prev > H * 0.45) out += ' ';
      prev = c.x1 + 1;
      const cw = c.x1 - c.x0 + 1, ch = c.y1 - c.y0 + 1;
      if (ch < H * 0.4) { out += cw > ch * 2 ? '-' : '.'; continue; }
      // ตัวเลขติดกัน (กว้างเกิน) → ตัดที่คอลัมน์หมึกน้อยสุดช่วงกลาง
      const parts = [[c.x0, c.x1]];
      for (let k = 0; k < parts.length; k++) {
        const [a, b] = parts[k], pw = b - a + 1;
        if (pw < Wd * 1.4) continue;
        let cut = a + Math.floor(pw * 0.3), mn = 1e9;
        for (let x = a + Math.floor(pw * 0.3); x <= a + Math.floor(pw * 0.7); x++) {
          let s2 = 0; for (let y = c.y0; y <= c.y1; y++) if (c.ids.has(lab[y * w + x])) s2++;
          if (s2 < mn) { mn = s2; cut = x; }
        }
        parts.splice(k, 1, [a, cut - 1], [cut, b]); k--;
      }
      for (const [a, b] of parts) {
        const f = bboxFeat((x, y) => c.ids.has(lab[(y + c.y0) * w + x + a]), b - a + 1, c.y1 - c.y0 + 1);
        if (f) out += classify(f);
      }
    }
    return out;
  };
  const isLatin = ch => /^[A-Za-z]$/.test(ch);
  const fixDigits = (data, ink, W) => {
    const lines = [];
    for (const b of data.blocks || []) for (const p of b.paragraphs) for (const l of p.lines) {
      const sy = [], lb = l.bbox, gapMin = (lb.y1 - lb.y0) * 0.3;    // ภาษาไทยไม่เว้นวรรคระหว่างคำ: เว้นเฉพาะช่องว่างจริงในภาพ
      l.words.forEach((wd, wi) => wd.symbols.forEach((s, si) => sy.push({ t: s.text, b: s.bbox, sp: wi > 0 && si === 0 && wd.bbox.x0 - l.words[wi - 1].bbox.x1 > gapMin })));
      const L = label(ink, W, lb.x0, lb.y0, lb.x1 - lb.x0 + 1, lb.y1 - lb.y0 + 1);
      let out = '', i = 0;
      while (i < sy.length) {
        const t = sy[i].t, prev = i ? sy[i - 1].t : ' ';
        const start = DIG.includes(t) || (CONF.includes(t) && !isLatin(prev) && sy.slice(i + 1, i + 3).some(s => DIG.includes(s.t)));
        if (!start) { out += (sy[i].sp ? ' ' : '') + t; i++; continue; }
        let j = i;
        while (j < sy.length && RUNC.includes(sy[j].t) && !(isLatin(sy[j].t) && j + 1 < sy.length && isLatin(sy[j + 1].t))) j++;
        j = Math.max(j, i + 1);
        let trimmed = false;
        while (j > i + 1 && '.:-–'.includes(sy[j - 1].t)) { j--; trimmed = true; }
        const xa = Math.min(...sy.slice(i, j).map(s => s.b.x0)) - lb.x0 - 2, xb = Math.max(...sy.slice(i, j).map(s => s.b.x1)) - lb.x0 + 2;
        let r = runText(L, xa, xb, !(i && '([{"“\'‘:/-–'.includes(sy[i - 1].t)));
        if (trimmed) r = r.replace(/[.\-]+$/, '');
        out += (sy[i].sp ? ' ' : '') + (r || sy.slice(i, j).map(s => s.t).join(''));
        i = j;
      }
      lines.push({ text: out, bbox: lb });
    }
    return lines;
  };
  // โดเมนที่ใช้บ่อยในหนังสือราชการ — OCR อ่านผิด 1 ตัวอักษร (เช่น de.th) → ปรับเป็นโดเมนที่รู้จัก (dg.th)
  const KNOWN_HOSTS = ['dg.th', 'bit.ly', 'tinyurl.com', 'forms.gle', 'shorturl.at', 'cutt.ly', 'rebrand.ly', 's.id', 'zoom.us', 'meet.google.com',
    'docs.google.com', 'drive.google.com', 'forms.office.com', 'teams.microsoft.com', 'teams.live.com', 'webex.com', 'line.me', 'youtu.be',
    'youtube.com', 'facebook.com', 'fb.me', 'nso.go.th', 'kkportal.nso.go.th', 'khonkaen.go.th', 'saraban.nso.go.th'];
  const snapHost = url => {
    const m = url.match(/^(https?:\/\/)([^/\s]+)(.*)$/i);
    if (!m) return url;
    const host = m[2].toLowerCase();
    if (KNOWN_HOSTS.some(k => host === k || host.endsWith('.' + k))) return url;
    const diff = (x, y) => x.length === y.length ? [...x].filter((c, i) => c !== y[i]).length : 9;
    const hit = KNOWN_HOSTS.find(k => diff(host, k) === 1);
    return hit ? m[1] + hit + m[3] : url;
  };
  // บรรทัดที่มี URL: อ่านซ้ำเฉพาะบรรทัดนั้นด้วยภาษาอังกฤษอย่างเดียว หลายแบบ (ขยาย 2/3/4 เท่า · ขาวดำ) แล้วเลือก URL ที่ความมั่นใจรวมสูงสุด
  //   (ตัวละตินเล็ก ๆ เช่น dg.th / de.th อ่านต่างกันตามการย่อขยายภาพของแต่ละเบราว์เซอร์ — ใช้การโหวตแทนการอ่านครั้งเดียว)
  const fixUrls = async (T, img, lines) => {
    for (const ln of lines) {
      if (!/https?:\/\//i.test(ln.text)) continue;
      const b = ln.bbox, sx = Math.max(0, (b.x0 - PAD) / SCALE - 3), sy = Math.max(0, (b.y0 - PAD) / SCALE - 3);
      const sw = Math.min(img.width - sx, (b.x1 - b.x0) / SCALE + 6), sh = Math.min(img.height - sy, (b.y1 - b.y0) / SCALE + 6);
      const votes = {};
      for (const [k, bw] of [[2, false], [3, false], [4, false], [3, true]]) {
        const c = document.createElement('canvas'), p = 30;
        c.width = Math.round(sw * k) + p * 2; c.height = Math.round(sh * k) + p * 2;
        const cx = c.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, c.width, c.height);
        cx.imageSmoothingEnabled = true; cx.imageSmoothingQuality = 'high';
        cx.drawImage(img, sx, sy, sw, sh, p, p, Math.round(sw * k), Math.round(sh * k));
        if (bw) {
          const id = cx.getImageData(0, 0, c.width, c.height), d = id.data;
          for (let q = 0; q < d.length; q += 4) { const v = (d[q] * 299 + d[q + 1] * 587 + d[q + 2] * 114) / 1000 < 160 ? 0 : 255; d[q] = d[q + 1] = d[q + 2] = v; }
          cx.putImageData(id, 0, 0);
        }
        try {
          const { data } = await T.recognize(c, 'eng', {}, { text: true, blocks: true });
          let best = null;
          for (const bl of data.blocks || []) for (const pa of bl.paragraphs) for (const li of pa.lines) for (const wd of li.words) {
            const u = (wd.text.match(/https?:\/\/\S+/i) || [])[0];
            if (u && (!best || wd.confidence > best.conf)) best = { u: u.replace(/[.,;)]+$/, ''), conf: wd.confidence };
          }
          if (best) { const u = snapHost(best.u); votes[u] = (votes[u] || 0) + best.conf + 20; }   // +20 = 1 เสียงต่อ 1 แบบ · ปรับโดเมนที่รู้จัก
        } catch (e) { /* ข้าม */ }
      }
      const win = Object.entries(votes).sort((a, b2) => b2[1] - a[1])[0];
      if (win) ln.text = ln.text.replace(/https?:\/\/\S+/i, win[0]);
      else ln.text = ln.text.replace(/https?:\/\/\S+/i, u => snapHost(u));
      ln.urlVotes = votes;
    }
    return lines;
  };
  // ภาพ → ขยาย 3 เท่า + ขอบขาว (ตัวอักษรเล็กจากภาพหน้าจออ่านได้ดีขึ้น · URL ไม่หลุด)
  const prepare = file => new Promise((ok, fail) => {
    const img = new Image();
    img.onload = () => {
      const W = img.width * SCALE + PAD * 2, H = img.height * SCALE + PAD * 2, c = document.createElement('canvas');
      c.width = W; c.height = H;
      const cx = c.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, W, H);
      cx.imageSmoothingEnabled = true; cx.imageSmoothingQuality = 'high'; cx.drawImage(img, PAD, PAD, img.width * SCALE, img.height * SCALE);
      const px = cx.getImageData(0, 0, W, H).data, ink = new Uint8Array(W * H);
      for (let q = 0; q < W * H; q++) { const g = (px[q * 4] * 299 + px[q * 4 + 1] * 587 + px[q * 4 + 2] * 114) / 1000; px[q * 4] = px[q * 4 + 1] = px[q * 4 + 2] = g; ink[q] = g < 170 ? 1 : 0; }
      cx.putImageData(new ImageData(px, W, H), 0, 0);
      ok({ canvas: c, ink, W, img });
    };
    img.onerror = () => fail(new Error('เปิดไฟล์ภาพไม่ได้'));
    img.src = URL.createObjectURL(file);
  });
  // ตัวอ่าน (worker) สร้างครั้งเดียวแล้วใช้ซ้ำ — Tesseract.recognize() สร้าง worker ใหม่ทุกครั้ง (โหลดซ้ำ ช้า)
  const workers = {};
  const getWorker = (T, lang) => workers[lang] || (workers[lang] = T.createWorker(lang).catch(e => { delete workers[lang]; throw e; }));
  const reader = T => ({ recognize: async (img, lang, opts, out) => (await getWorker(T, lang)).recognize(img, opts || {}, out) });
  const ocr = async file => {
    if (!file || !/^image\//.test(file.type)) return;
    const ta = document.getElementById('lrText');
    ocrMsg.className = 'small mt-1 text-primary';
    ocrMsg.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังอ่านตัวอักษรจากภาพ… (ครั้งแรกโหลดข้อมูลภาษาไทยประมาณ 2 MB)';
    try {
      const [Tess, pic] = await Promise.all([loadTess(), prepare(file), loadTpls()]);
      const T = reader(Tess);
      ocrMsg.lastChild.textContent = ' กำลังอ่านตัวอักษรจากภาพ…';
      const { data } = await T.recognize(pic.canvas, 'tha+eng', {}, { text: true, blocks: true });
      let txt;
      box.lrDebug = data;                            // ตรวจสอบผล OCR ดิบได้จาก console (#letterReader.lrDebug)
      try {
        const lines = await fixUrls(T, pic.img, fixDigits(data, pic.ink, pic.W));
        box.lrUrlVotes = lines.filter(l => l.urlVotes).map(l => l.urlVotes);   // ตรวจผลโหวต URL ได้จาก console
        txt = lines.map(l => l.text).join('\n');
      } catch (e) { txt = ''; }
      URL.revokeObjectURL(pic.img.src);
      if (!txt.trim()) txt = data.text;
      txt = txt.replace(/\s+(?=[ะ-ฺ็-๎])/g, '').trim();   // ตัดช่องว่างหน้าสระ/วรรณยุกต์ที่ OCR แทรก
      ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + txt;
      ocrMsg.className = 'small mt-1 text-warning-emphasis';
      ocrMsg.innerHTML = '<i class="bi bi-exclamation-triangle"></i> อ่านจากภาพแล้ว — ตรวจข้อความในกล่องอีกครั้ง (โดยเฉพาะตัวเลข) แล้วกด "อ่านข้อความและกรอกฟอร์ม"';
      // URL ที่อ่านได้หลายแบบ → แสดงให้เลือก (คะแนนความมั่นใจรวม · ตัวแรก = ที่ใส่ในกล่องแล้ว)
      for (const v of box.lrUrlVotes || []) {
        const opts = Object.entries(v).sort((a, b) => b[1] - a[1]);
        if (opts.length < 2) continue;
        const div = document.createElement('div');
        div.className = 'mt-1 text-body';
        div.innerHTML = '<i class="bi bi-link-45deg"></i> URL อ่านได้หลายแบบ — คลิกเลือกที่ถูกต้อง: ';
        opts.forEach(([u, sc], k) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-sm me-1 mb-1 ' + (k ? 'btn-outline-secondary' : 'btn-primary');
          btn.textContent = u + ' (' + Math.round(sc) + ')';
          btn.addEventListener('click', () => {
            ta.value = ta.value.replace(/https?:\/\/\S+/i, u);
            div.querySelectorAll('button').forEach(x => x.className = 'btn btn-sm me-1 mb-1 btn-outline-secondary');
            btn.className = 'btn btn-sm me-1 mb-1 btn-primary';
          });
          div.appendChild(btn);
        });
        ocrMsg.appendChild(div);
      }
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
