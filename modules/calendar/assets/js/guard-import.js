/**
 * นำเข้าเวรศาลากลางจากคำสั่งจังหวัด (guard_import.php) — ทำงานในเบราว์เซอร์ทั้งหมด ไม่ส่งไฟล์ไปที่ใด
 *   PDF → ภาพทีละหน้า (pdf.js) → หาเส้นตาราง (เส้นตั้ง 5 เส้น = 4 คอลัมน์ · เส้นนอน = แถว)
 *   → อ่านทีละช่อง (OCR tesseract.js ภาษาไทย): วัน เดือน ปี · ผู้ปฏิบัติหน้าที่เวร · ผู้ตรวจเวร (ไม่อ่านคอลัมน์ข้าราชการชั้นผู้ใหญ่)
 *   วันที่: OCR อ่านเลขไทยผิดบ่อย → ใช้ลำดับแถว (เรียงวันที่ 1–31 · กลางวันมาก่อนกลางคืนของวันเดียวกัน) แถวที่เลขอ่านได้ไม่ตรงจะเน้นสีให้ตรวจ
 *   บุคลากร: เทียบชื่อกับรายชื่อในระบบแบบยอมให้สะกดเพี้ยนเล็กน้อย · ช่องที่เป็น "(สำนักงานสถิติจังหวัดขอนแก่น)" แต่จับคู่ชื่อไม่ได้ → ใส่ชื่อที่อ่านได้ให้ตรวจ
 */
(() => {
  const D = JSON.parse(document.getElementById('giData').textContent);
  const body = document.getElementById('giBody');
  const msg = document.getElementById('giMsg');
  const prog = document.getElementById('giProg');
  const TH = '๐๑๒๓๔๕๖๗๘๙';
  const MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  // ตัด ช่องว่าง/ขีด/วงเล็บ/วรรณยุกต์/การันต์ (OCR อ่านเพี้ยนบ่อย) · ํา → ำ · เลขไทย → อารบิก
  const norm = s => s.replace(/\u0e4d\u0e32/g, '\u0e33').replace(/[\u0e48-\u0e4c]/g, '').replace(/[๐-๙]/g, c => TH.indexOf(c)).replace(/[\s|\-–_.,:;()[\]{}'"`‘’“”]/g, '');
  // ระยะแก้ไขน้อยที่สุดของ p ที่อยู่ส่วนใดส่วนหนึ่งของ t (approximate substring)
  const approx = (p, t) => {
    if (!p.length) return 0;
    let prev = new Array(t.length + 1).fill(0), best = p.length;
    for (let i = 1; i <= p.length; i++) {
      const cur = [i];
      for (let j = 1; j <= t.length; j++) cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (p[i - 1] === t[j - 1] ? 0 : 1));
      prev = cur;
    }
    for (let j = 0; j <= t.length; j++) best = Math.min(best, prev[j]);
    return best;
  };
  const staff = D.staff.map(s => ({ ...s, key: norm(s.bare) }));
  const OFFICE = norm('สถิติจังหวัดขอนแก่น');
  const PREFIX = /^\s*(นางสาว|นาง|นาย|น\.ส\.|ว่าที่|จ่า|สิบ|พัน|ร้อย|ร\.|ส\.|พ\.|ด\.|ม\.?ร\.?ว|ม\.?ล|มล|มรว)/;

  /* ---------- ตารางรายการ (แก้ไขได้) ---------- */
  const userOpts = id => '<option value="0">— ไม่มีบัญชีในระบบ (พิมพ์ชื่อ) —</option>'
    + staff.map(s => `<option value="${s.id}"${s.id === id ? ' selected' : ''}>${esc(s.name)}</option>`).join('');
  const sel = (name, opts, v) => `<select class="form-select form-select-sm" data-f="${name}">`
    + Object.entries(opts).map(([k, lb]) => `<option value="${k}"${String(k) === String(v) ? ' selected' : ''}>${esc(lb)}</option>`).join('') + '</select>';
  const monthDays = () => { const [y, m] = document.getElementById('giMonth').value.split('-').map(Number); return new Date(y, m, 0).getDate(); };
  const rowHtml = r => {
    const days = {}; for (let d = 1; d <= monthDays(); d++) days[d] = d;
    const uid = +r.user_id || 0;
    return `<tr class="${r.warn ? 'gi-warn' : ''}${r.fresh ? ' gi-new' : ''}" title="${esc(r.why || '')}">`
      + `<td>${sel('day', days, r.day)}</td><td>${sel('shift', D.shifts, r.shift)}</td><td>${sel('role', D.roles, r.role)}</td>`
      + `<td><select class="form-select form-select-sm" data-f="user_id">${userOpts(uid)}</select>`
      + `<input class="form-control form-control-sm mt-1${uid ? ' d-none' : ''}" data-f="name" maxlength="150" placeholder="ชื่อ-สกุล" value="${esc(uid ? '' : (r.name || ''))}">`
      + (r.why ? `<div class="small text-warning-emphasis mt-1"><i class="bi bi-exclamation-triangle"></i> ${esc(r.why)}</div>` : '') + '</td>'
      + '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-del title="ลบรายการ"><i class="bi bi-x-lg"></i></button></td></tr>';
  };
  const render = rows => {
    body.innerHTML = rows.length ? rows.map(rowHtml).join('') : '<tr class="gi-empty"><td colspan="5" class="text-center text-muted py-4">ยังไม่มีรายการ — เลือกไฟล์ PDF ด้านซ้าย หรือกด "เพิ่มรายการ"</td></tr>';
  };
  const collect = () => [...body.querySelectorAll('tr:not(.gi-empty)')].map(tr => {
    const v = f => tr.querySelector(`[data-f="${f}"]`).value;
    return { day: +v('day'), shift: v('shift'), role: v('role'), user_id: +v('user_id'), name: v('name').trim() };
  });
  const dirty = () => { body.dataset.dirty = '1'; };
  body.addEventListener('change', e => {
    if (e.target.dataset.f === 'user_id') e.target.nextElementSibling.classList.toggle('d-none', e.target.value !== '0');
    dirty();
  });
  body.addEventListener('click', e => { if (e.target.closest('[data-del]')) { e.target.closest('tr').remove(); dirty(); if (!body.querySelector('tr')) render([]); } });
  document.getElementById('giAdd').addEventListener('click', () => {
    body.querySelector('.gi-empty')?.remove();
    const last = collect().pop();
    body.insertAdjacentHTML('beforeend', rowHtml({ day: last ? last.day : 1, shift: 'night', role: 'duty', user_id: 0, name: '', fresh: true }));
    dirty();
  });
  document.getElementById('giForm').addEventListener('submit', e => {
    if (e.submitter && e.submitter.value === 'clear') return;
    const rows = collect();
    const bad = rows.findIndex(r => !r.user_id && !r.name);
    if (bad >= 0) { e.preventDefault(); alert('รายการที่ ' + (bad + 1) + ' ยังไม่ได้เลือกบุคลากร / พิมพ์ชื่อ'); return; }
    if (!rows.length) { e.preventDefault(); alert('ยังไม่มีรายการเวร'); return; }
    document.getElementById('giRows').value = JSON.stringify(rows);
    delete body.dataset.dirty;
  });
  render(D.rows);

  /* ---------- โหลด pdf.js / tesseract.js จาก CDN (ครั้งแรกที่ใช้) ---------- */
  const loadScript = (src, integrity) => new Promise((ok, fail) => {
    const sc = document.createElement('script');
    sc.src = src; sc.integrity = integrity; sc.crossOrigin = 'anonymous';
    sc.onload = ok; sc.onerror = () => fail(new Error('โหลดตัวอ่านไฟล์ไม่ได้ (ตรวจการเชื่อมต่ออินเทอร์เน็ต)'));
    document.head.appendChild(sc);
  });
  const PDFJS = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/';
  let pdfReady = null, tessWorker = null;
  const loadPdf = () => pdfReady || (pdfReady = (async () => {
    await loadScript(PDFJS + 'pdf.min.js', 'sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e');
    // worker ข้ามโดเมนไม่ได้ (CSP worker-src 'self' blob:) → ดาวน์โหลดพร้อมตรวจ SRI แล้วสร้างเป็น blob
    const res = await fetch(PDFJS + 'pdf.worker.min.js', { integrity: 'sha384-SnzOobpRMLXZ52iJvZm/C0fYw0OQemTXzTjIsdsfMcrCtCEe9qgzxTd3RSklO5x2' });
    const url = URL.createObjectURL(new Blob([await res.text()], { type: 'text/javascript' }));
    window.pdfjsLib.GlobalWorkerOptions.workerPort = new Worker(url);
    return window.pdfjsLib;
  })().catch(e => { pdfReady = null; throw e; }));
  const loadTess = async () => {
    if (tessWorker) return tessWorker;
    if (!window.Tesseract) await loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js', 'sha384-GJqSu7vueQ9qN0E9yLPb3Wtpd7OrgK8KmYzC8T1IysG1bcvxvIO4qtYR/D3A991F');
    tessWorker = await window.Tesseract.createWorker('tha');
    return tessWorker;
  };

  /* ---------- หาเส้นตาราง ---------- */
  const DARK = 150;
  const findGrid = cv => {
    const W = cv.width, H = cv.height, px = cv.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, W, H).data;
    const dark = new Uint8Array(W * H);
    for (let i = 0, k = 0; k < W * H; i += 4, k++) dark[k] = (px[i] * 0.3 + px[i + 1] * 0.59 + px[i + 2] * 0.11) < DARK ? 1 : 0;
    // เส้นตั้ง: เก็บเฉพาะช่วงเข้มแนวตั้งยาว ≥ SEG px (ตัวอักษรสั้นกว่านี้) แล้วดูความครอบคลุมในแถบกว้าง ±4 px (ภาพสแกนเอียงเล็กน้อย)
    const SEG = 40, SKEW = 4;
    const vseg = new Uint8Array(W * H);
    for (let x = 0; x < W; x++) {
      let run = 0;
      for (let y = 0; y <= H; y++) {
        const on = y < H && (dark[y * W + x] || (x > 0 && dark[y * W + x - 1]) || (x < W - 1 && dark[y * W + x + 1]));
        if (on) { run++; continue; }
        if (run >= SEG) for (let k = y - run; k < y; k++) vseg[k * W + x] = 1;
        run = 0;
      }
    }
    const vcov = new Float32Array(W), vTop = new Int32Array(W).fill(H), vBot = new Int32Array(W);
    for (let x = SKEW; x < W - SKEW; x++) {
      let c = 0;
      for (let y = 0; y < H; y++) {
        for (let d = -SKEW; d <= SKEW; d++) if (vseg[y * W + x + d]) { c++; if (y < vTop[x]) vTop[x] = y; vBot[x] = y; break; }
      }
      vcov[x] = c;
    }
    const groups = [];
    for (let x = 0; x < W; x++) {
      if (vcov[x] < H * 0.3) continue;
      const g = groups[groups.length - 1];
      if (g && x - g.x2 <= 3) { g.x2 = x; if (vcov[x] > g.c) { g.c = vcov[x]; g.x = x; } g.top = Math.min(g.top, vTop[x]); g.bot = Math.max(g.bot, vBot[x]); }
      else groups.push({ x1: x, x2: x, x, c: vcov[x], top: vTop[x], bot: vBot[x] });
    }
    // ตัดเงาขอบกระดาษจากการสแกน (ชิดขอบภาพ) · เกิน 5 เส้น = เลือก 5 เส้นติดกันที่สัดส่วนคอลัมน์ใกล้แบบฟอร์มที่สุด
    let gs = groups.filter(g => g.x > W * 0.015 && g.x < W * 0.985);
    if (gs.length < 4) return null;                                       // ไม่ใช่หน้าตาราง (บันทึกข้อความ / คำสั่ง)
    if (gs.length > 5) {
      const RATIO = [0, 0.195, 0.45, 0.71, 1];
      let best = null;
      for (let i = 0; i + 5 <= gs.length; i++) {
        const w5 = gs.slice(i, i + 5), a0 = w5[0].x, span = w5[4].x - a0;
        const err = w5.reduce((e, g, k) => e + Math.abs((g.x - a0) / span - RATIO[k]), 0) - span / W;
        if (!best || err < best.err) best = { err, w5 };
      }
      gs = best.w5;
    }
    const groups2 = gs;
    const xs = groups2.map(g => g.x);
    const top = Math.min(...groups2.map(g => g.top)), bot = Math.max(...groups2.map(g => g.bot));
    const L = xs[0], R = xs[xs.length - 1];
    const cols = xs.length >= 5 ? xs.slice(0, 5) : [0, 0.195, 0.45, 0.71, 1].map(f => L + (R - L) * f);
    // เส้นนอน: ช่วงเข้มแนวนอนยาว ≥ SEG px · ครอบคลุม ≥ 60% ของความกว้างตารางในแถบ ±4 px
    const x0 = Math.floor(L), x1 = Math.ceil(R), TW = x1 - x0 + 1;
    const hseg = new Uint8Array(TW * H);
    for (let y = Math.max(1, top - 10); y < Math.min(H - 1, bot + 10); y++) {
      let run = 0;
      for (let x = x0; x <= x1 + 1; x++) {
        const on = x <= x1 && (dark[y * W + x] || dark[(y - 1) * W + x] || dark[(y + 1) * W + x]);
        if (on) { run++; continue; }
        if (run >= SEG) for (let k = x - run; k < x; k++) hseg[y * TW + k - x0] = 1;
        run = 0;
      }
    }
    const hy = [];
    for (let y = Math.max(SKEW, top - 6); y < Math.min(H - SKEW, bot + 6); y++) {
      let c = 0;
      for (let x = 0; x < TW; x++) for (let d = -SKEW; d <= SKEW; d++) if (hseg[(y + d) * TW + x]) { c++; break; }
      if (c > TW * 0.6) hy.push({ y, c });
    }
    const lines = [];
    hy.forEach(h => { const l = lines[lines.length - 1]; if (l && h.y - l.y2 <= 3) { l.y2 = h.y; if (h.c > l.c) { l.c = h.c; l.y = h.y; } } else lines.push({ y1: h.y, y2: h.y, y: h.y, c: h.c }); });
    lines.forEach(l => { l.y1 = l.y - 3; l.y2 = l.y + 3; });
    const bands = [];
    for (let i = 0; i + 1 < lines.length; i++) if (lines[i + 1].y1 - lines[i].y2 > 30) bands.push([lines[i].y2, lines[i + 1].y1]);
    return { cols, bands };
  };
  const crop = (cv, x1, y1, x2, y2) => {
    const pad = 6, c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(x2 - x1 - pad * 2)); c.height = Math.max(1, Math.round(y2 - y1 - pad * 2));
    const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
    g.drawImage(cv, x1 + pad, y1 + pad, c.width, c.height, 0, 0, c.width, c.height);
    return c;
  };

  /* ---------- แปลข้อความในช่อง ---------- */
  const people = (text, role) => {
    const out = [], lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    let block = null;                                     // บรรทัดชื่อล่าสุด (ขึ้นต้นด้วยคำนำหน้า) + จับคู่ได้หรือยัง
    lines.forEach(line => {
      const n = norm(line);
      if (PREFIX.test(line)) block = { line, matched: false };
      let best = null;
      staff.forEach(s => {
        const d = approx(s.key, n), lim = Math.max(1, Math.floor(s.key.length * 0.15));
        if (d <= lim && (!best || d / s.key.length < best.r)) best = { s, r: d / s.key.length, d };
      });
      if (best) {
        if (block) block.matched = true;
        out.push({ role, user_id: best.s.id, name: best.s.name, why: best.d ? 'อ่านได้ "' + line + '" — ตรวจชื่ออีกครั้ง' : '' });
      } else if (n.length > 8 && approx(OFFICE, n) <= 2 && !/สัสดี/.test(line) && block && !block.matched) {
        block.matched = true;                             // หน่วยงาน = สำนักงานสถิติจังหวัด แต่จับคู่ชื่อไม่ได้
        out.push({ role, user_id: 0, name: block.line.replace(/[^ก-๙a-zA-Z.\s]/g, '').replace(/\s+/g, ' ').trim(), why: 'ไม่พบชื่อ "' + block.line + '" ในระบบ — เลือกบุคลากรหรือแก้ชื่อ' });
      }
    });
    return out;
  };
  const shiftOf = t => /คืน/.test(t) ? 'night' : (/กลาง|วัน\)/.test(t) ? 'day' : 'night');
  const monthOf = t => { const n = norm(t); let b = null; MONTHS.forEach((mm, i) => { const d = approx(norm(mm), n); if (d <= 2 && (!b || d < b.d)) b = { m: i + 1, d }; }); return b ? b.m : 0; };

  /* ---------- อ่านไฟล์ ---------- */
  const setProg = (p, text) => { prog.classList.remove('d-none'); prog.firstElementChild.style.width = Math.round(p * 100) + '%'; if (text) msg.innerHTML = text; };
  const pagesOf = async files => {
    const out = [];
    for (const f of files) {
      if (/pdf$/i.test(f.type) || /\.pdf$/i.test(f.name)) {
        const lib = await loadPdf();
        const doc = await lib.getDocument({ data: await f.arrayBuffer() }).promise;
        for (let i = 1; i <= doc.numPages; i++) out.push({ label: f.name + ' หน้า ' + i, get: async () => {
          const pg = await doc.getPage(i), vp0 = pg.getViewport({ scale: 1 }), vp = pg.getViewport({ scale: 2000 / vp0.width });
          const cv = document.createElement('canvas'); cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
          const g = cv.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, cv.width, cv.height);
          await pg.render({ canvasContext: g, viewport: vp }).promise;
          return cv;
        } });
      } else if (/^image\//.test(f.type)) {
        out.push({ label: f.name, get: async () => {
          const bmp = await createImageBitmap(f), s = 2000 / bmp.width, cv = document.createElement('canvas');
          cv.width = 2000; cv.height = Math.round(bmp.height * s);
          const g = cv.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, cv.width, cv.height); g.drawImage(bmp, 0, 0, cv.width, cv.height);
          return cv;
        } });
      }
    }
    return out;
  };
  const run = async files => {
    const input = document.getElementById('giFile');
    input.disabled = true;
    const raw = [], rows = [], votes = {};
    let prev = null, tables = 0, firstDay = null;
    try {
      setProg(0.02, '<i class="bi bi-hourglass-split"></i> กำลังเปิดไฟล์และโหลดตัวอ่าน…');
      const pages = await pagesOf(files);
      if (!pages.length) throw new Error('ไม่พบหน้าที่อ่านได้ — เลือกไฟล์ PDF หรือภาพ');
      const w = await loadTess();
      for (let p = 0; p < pages.length; p++) {
        setProg((p + 0.1) / pages.length, `<i class="bi bi-hourglass-split"></i> กำลังอ่าน ${esc(pages[p].label)} (${p + 1}/${pages.length})…`);
        const cv = await pages[p].get();
        const grid = findGrid(cv);
        if (!grid || !grid.bands.length) { raw.push(`[${pages[p].label}] ไม่ใช่หน้าตาราง — ข้าม`); continue; }
        tables++;
        const [c0, c1, c2, c3] = grid.cols;
        for (let b = 0; b < grid.bands.length; b++) {
          const [y1, y2] = grid.bands[b];
          setProg((p + (b + 1) / (grid.bands.length + 1)) / pages.length);
          const dateT = (await w.recognize(crop(cv, c0, y1, c1, y2))).data.text;
          if (/เดือน|ปี\s*$/.test(dateT) && !/\d|[๐-๙]/.test(dateT)) continue;          // หัวตาราง
          if (!/[0-9๐-๙]/.test(dateT) && !/กลาง|คืน|วัน/.test(dateT)) continue;
          const dutyT = (await w.recognize(crop(cv, c1, y1, c2, y2))).data.text;
          const inspT = (await w.recognize(crop(cv, c2, y1, c3, y2))).data.text;
          const shift = shiftOf(dateT);
          const mo = monthOf(dateT); if (mo) votes[mo] = (votes[mo] || 0) + 1;
          const num = +((norm(dateT).match(/^\d{1,2}/) || [])[0] || 0);
          // วันที่ตามลำดับแถว: กลางวัน → กลางคืน วันเดียวกัน · อื่น ๆ = วันถัดไป
          const day = prev ? (shift === 'night' && prev.shift === 'day' ? prev.day : prev.day + 1) : (num >= 1 && num <= 31 ? num : 1);
          prev = { day, shift };
          if (firstDay === null) firstDay = day;
          const warn = num && num !== day ? `วันที่อ่านได้ "${num}" ไม่ตรงลำดับ (ใช้ ${day}) — ตรวจอีกครั้ง` : '';
          raw.push(`[${pages[p].label}] ${dateT.replace(/\s+/g, ' ').trim()} → วันที่ ${day} ${D.shifts[shift]}\n  ผู้ปฏิบัติหน้าที่เวร: ${dutyT.replace(/\s+/g, ' ').trim()}\n  ผู้ตรวจเวร: ${inspT.replace(/\s+/g, ' ').trim()}`);
          [...people(dutyT, 'duty'), ...people(inspT, 'inspector')].forEach(x => {
            if (rows.some(r => r.day === day && r.shift === shift && r.role === x.role && (x.user_id ? r.user_id === x.user_id : r.name === x.name))) return;
            rows.push({ day, shift, ...x, dateWarn: warn, nameWarn: x.why, fresh: true });
          });
        }
      }
      if (!tables) throw new Error('ไม่พบตารางเวรในไฟล์ — ต้องเป็นหน้า "บัญชีรายละเอียดแนบท้ายคำสั่ง" ที่มีเส้นตาราง');
      // เดือนจากชื่อเดือนในคอลัมน์วันที่ (เสียงข้างมาก) — ปี: เดือนที่ใกล้ปัจจุบันที่สุด
      const mo = +Object.entries(votes).sort((a, b) => b[1] - a[1])[0]?.[0] || 0;
      if (mo) {
        const now = new Date(), y = now.getFullYear() + (mo < now.getMonth() + 1 - 2 ? 1 : 0), k = `${y}-${String(mo).padStart(2, '0')}`;
        const ms = document.getElementById('giMonth');
        if (![...ms.options].some(o => o.value === k)) ms.add(new Option(MONTHS[mo - 1] + ' ' + (y + 543), k));
        ms.value = k;
      }
      // ลำดับวันที่ครบพอดี (เริ่มวันที่ 1 · แถวสุดท้าย = วันสุดท้ายของเดือน) = เชื่อลำดับ ไม่ต้องเตือนเลขที่อ่านผิด
      const okSeq = firstDay === 1 && prev && prev.day === monthDays();
      rows.forEach(r => { r.why = [okSeq ? '' : r.dateWarn, r.nameWarn].filter(Boolean).join(' · '); r.warn = !!r.why; });
      rows.sort((a, b) => a.day - b.day || (a.shift === 'night') - (b.shift === 'night') || (a.role === 'inspector') - (b.role === 'inspector'));
      render(rows);
      dirty();
      const nWarn = rows.filter(r => r.warn).length;
      setProg(1, `<span class="text-success"><i class="bi bi-check2-circle"></i> อ่านแล้ว ${tables} หน้าตาราง · พบบุคลากรของสำนักงาน ${rows.length} รายการ</span>`
        + (nWarn ? `<br><span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> ตรวจรายการที่เน้นสี ${nWarn} รายการ</span>` : '')
        + '<br><span class="text-muted">ตรวจสอบกับไฟล์อีกครั้ง แล้วกด "บันทึกและลงปฏิทิน"</span>');
    } catch (e) {
      msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + esc(e.message || String(e)) + '</span>';
      console.error(e);
    } finally {
      input.disabled = false;
      prog.classList.add('d-none');
      if (raw.length) { document.getElementById('giRaw').textContent = raw.join('\n'); document.getElementById('giRawBox').classList.remove('d-none'); }
    }
  };
  document.getElementById('giFile').addEventListener('change', e => { if (e.target.files.length) run([...e.target.files]); });
  window.guardImportDebug = { norm, approx, people, findGrid };      // ใช้ทดสอบ
})();
