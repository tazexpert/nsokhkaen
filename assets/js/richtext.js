/* ตัวแก้ไขข้อความจัดรูปแบบ (Quill 2) — ใช้ร่วมกัน: สมุดบันทึก, กล่องจดหมาย (core/richtext.php) */
const RICH_TOOLBAR = [
  [{ header: [1, 2, 3, false] }],
  ['bold', 'italic', 'underline', 'strike'],
  [{ color: [] }, { background: [] }],
  [{ list: 'ordered' }, { list: 'bullet' }, { list: 'check' }],
  [{ indent: '-1' }, { indent: '+1' }],
  [{ align: [] }],
  ['blockquote', 'link'],
  ['clean'],
];
// คำอธิบายปุ่ม (ภาษาไทย) เมื่อชี้เมาส์
const RICH_TIPS = {
  '.ql-header': 'ขนาดหัวข้อ', '.ql-bold': 'ตัวหนา (Ctrl+B)', '.ql-italic': 'ตัวเอียง (Ctrl+I)', '.ql-underline': 'ขีดเส้นใต้ (Ctrl+U)',
  '.ql-strike': 'ขีดฆ่า', '.ql-color': 'สีตัวอักษร', '.ql-background': 'สีไฮไลต์', '.ql-list[value=ordered]': 'รายการแบบตัวเลข',
  '.ql-list[value=bullet]': 'รายการแบบจุด', '.ql-list[value=check]': 'รายการเช็กลิสต์', '.ql-indent[value="-1"]': 'ลดการเยื้อง',
  '.ql-indent[value="+1"]': 'เพิ่มการเยื้อง', '.ql-align': 'จัดแนวข้อความ', '.ql-blockquote': 'ข้อความอ้างอิง', '.ql-link': 'แทรกลิงก์',
  '.ql-clean': 'ล้างรูปแบบข้อความ',
};

/** สร้างตัวแก้ไขใน element (เนื้อหาเดิมอยู่ใน element นั้นได้เลย) */
function richEditor(selector, opts = {}) {
  const quill = new Quill(selector, { theme: 'snow', placeholder: opts.placeholder || '', modules: { toolbar: RICH_TOOLBAR } });
  const toolbar = quill.getModule('toolbar').container;
  for (const [sel, t] of Object.entries(RICH_TIPS)) toolbar.querySelectorAll(sel).forEach(b => { b.title = t; });
  return quill;
}

/** HTML สำหรับส่งไปบันทึก ('' ถ้าว่าง) */
function richEditorHtml(quill) {
  return quill.getLength() <= 1 ? '' : quill.root.innerHTML;
}
