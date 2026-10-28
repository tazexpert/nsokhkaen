<?php
require_once __DIR__ . '/header.php';
requireAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h3 class="mb-0">รายงานการเบิกวัสดุ</h3>
    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์รายงาน</button>
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">วันที่เริ่มต้น</label>
                <?php renderThaiDateSelect('f_start') ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">วันที่สิ้นสุด</label>
                <?php renderThaiDateSelect('f_end') ?>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="button" class="btn btn-primary w-100" onclick="loadReport()">ค้นหา</button>
            </div>
        </div>
    </div>
</div>

<div id="reportSheet">
    <div class="card mb-4 report-section" id="chartSection">
        <div class="text-center mb-2 d-none d-print-block">
            <h4 class="mb-1">รายงานการเบิกวัสดุ</h4>
            <div class="small text-muted">สำนักงานสถิติจังหวัดขอนแก่น</div>
            <div class="small text-muted" id="reportRangeText"></div>
        </div>
        <div class="card-header">1. กราฟจำนวนการเบิกวัสดุ</div>
        <div class="card-body">
            <div class="chart-bars" id="chartBars"></div>
            <p class="text-center text-muted small mb-0" id="chartEmpty" style="display:none;">ไม่มีข้อมูลการเบิกในช่วงเวลาที่เลือก</p>
        </div>
    </div>

    <div class="card mb-4 report-section" id="valueSection">
        <div class="card-header">2. มูลค่าวัสดุที่เบิก</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0" id="valueTable">
                <thead>
                <tr><th style="width:50px;">ลำดับ</th><th>รายการ</th><th class="text-end">จำนวน</th><th class="text-end">ราคา</th><th class="text-end">มูลค่าสินค้า</th></tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                <tr class="fw-bold">
                    <td colspan="4" class="text-end">มูลค่ารวมทั้งหมด</td>
                    <td class="text-end" id="totalValueCell">0.00</td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card report-section">
        <div class="card-header">3. วัสดุที่ควรจัดหา (ใกล้หมดสต๊อก)</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0" id="lowStockTable">
                <thead>
                <tr><th style="width:50px;">ลำดับ</th><th>รายการ</th><th class="text-end">คงเหลือ</th><th class="text-end">จุดสั่งซื้อขั้นต่ำ</th><th>หน่วย</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<style>
    /* Embeds the font file from this server instead of relying on it being
       installed locally or on an external CDN - see
       public/requisition_print.php for why. Applied to the printed report
       only (see @media print below) - the on-screen page keeps the normal
       Bootstrap font to match the rest of the app's UI. */
    @font-face {
        font-family: 'TH Sarabun New';
        src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew.ttf') format('truetype');
        font-weight: normal; font-style: normal;
    }
    @font-face {
        font-family: 'TH Sarabun New';
        src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew-Bold.ttf') format('truetype');
        font-weight: bold; font-style: normal;
    }
    .chart-bars {
        display: flex;
        align-items: flex-end;
        gap: 6px;
        height: 360px;
        box-sizing: border-box;
        padding: 28px 8px 0; /* top padding reserves room for the tallest bar's value label */
        margin-bottom: 90px;
        border-left: 1px solid #999;
        border-bottom: 1px solid #999;
    }
    .bar-col {
        flex: 1 1 0;
        min-width: 4px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        height: 100%;
        position: relative;
    }
    .bar {
        width: 100%;
        max-width: 32px;
        background: #2a78d6;
        border-radius: 4px 4px 0 0;
        position: relative;
        /* Chrome drops background-color by default when printing unless
           told otherwise - without this the bars (plain color fills, no
           border) render as blank space on the printed page. */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .bar-value {
        font-size: 11px;
        color: #333;
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        white-space: nowrap;
        margin-bottom: 2px;
    }
    .bar-label {
        position: absolute;
        top: 100%;
        left: 50%;
        font-size: 10px;
        color: #555;
        white-space: nowrap;
        max-width: 90px;
        overflow: hidden;
        text-overflow: ellipsis;
        transform: translateX(-100%) rotate(-45deg);
        transform-origin: top right;
        margin-top: 6px;
    }
    @media print {
        @page { size: A4 portrait; margin: 12mm; }
        /* Section 1 (the chart) prints on its own landscape page - a lot
           more horizontal room for however many materials were withdrawn -
           while sections 2/3 stay on normal portrait pages. */
        @page chart-page { size: A4 landscape; margin: 12mm; }
        nav, footer, .no-print { display: none !important; }
        body, #reportSheet { font-family: 'TH Sarabun New', 'Tahoma', sans-serif; }
        .container-fluid { padding: 0 !important; }
        .report-section { page-break-inside: avoid; border: none !important; }
        .card-header { background: none !important; border-bottom: 1px solid #000 !important; font-weight: bold; }
        #valueTable, #lowStockTable { page-break-inside: auto; }
        #valueTable tr, #lowStockTable tr { page-break-inside: avoid; }

        #chartSection {
            page: chart-page;
            break-after: page;
        }
        /* The title block now lives inside #chartSection (merged onto the
           same printed page) - shrink it in print so it doesn't crowd the
           chart below it. */
        #chartSection .d-print-block { margin-bottom: 6px !important; }
        #chartSection .d-print-block h4 { font-size: 16pt; margin-bottom: 2px !important; }
        #chartSection .d-print-block .small { font-size: 10pt; }
        #valueSection { break-after: page; }
        /* A generous fixed height rather than stretching to exactly fill
           the landscape page: an exact-fit (flex:1 inside a page-height
           container) looked right in a plain screenshot but Chromium's
           actual PDF print pass lays the page out slightly differently,
           and the smallest shortfall fragments the chart across 2 pages
           instead of clipping it (reproduced repeatedly in testing). This
           number comfortably fits a landscape A4 page (~186mm tall content
           area) alongside the title/header above and the rotated labels
           below, with room to spare - safety margin, not a tight fit. */
        .chart-bars { height: 120mm; margin-bottom: 40px; }
        .bar { max-width: 40px; }
        .bar-value { font-size: 13px; }
        .bar-label { font-size: 12px; max-width: 140px; }
    }
</style>

<script>
const REPORT_API = INV_URL_JS + 'api/report_api.php';

function getFilters() {
    return {
        start_date: thaiDateSelectGet('f_start'),
        end_date: thaiDateSelectGet('f_end'),
    };
}

function formatNumber(n) {
    return Number(n).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function loadReport() {
    const filters = getFilters();
    $.get(REPORT_API, { action: 'summary', ...filters }, function (res) {
        renderRangeText(filters);
        renderChart(res.data.items);
        renderValueTable(res.data.items, res.data.total_value);
        renderLowStock(res.data.low_stock);
    });
}

function isoToThaiDate(iso) {
    const [y, m, d] = iso.split('-').map((n) => parseInt(n, 10));
    return `${d} ${THAI_MONTHS[m - 1]} ${y + 543}`;
}

function renderRangeText(filters) {
    const start = filters.start_date || null;
    const end = filters.end_date || null;
    let text = 'ทุกช่วงเวลา';
    if (start && end) text = `ช่วงวันที่ ${isoToThaiDate(start)} ถึง ${isoToThaiDate(end)}`;
    else if (start) text = `ตั้งแต่วันที่ ${isoToThaiDate(start)}`;
    else if (end) text = `ถึงวันที่ ${isoToThaiDate(end)}`;
    $('#reportRangeText').text(text + ' — พิมพ์เมื่อ ' + isoToThaiDate(new Date().toISOString().slice(0, 10)));
}

function renderChart(items) {
    const container = $('#chartBars').empty();
    if (!items.length) {
        $('#chartEmpty').show();
        return;
    }
    $('#chartEmpty').hide();
    // Percentage of .bar-col's own height (not a fixed px figure) so the
    // same markup auto-scales whether .chart-bars is sized for the screen
    // or stretched to fill the landscape print page (see @media print).
    const max = Math.max(...items.map((i) => i.qty));
    items.forEach((item) => {
        const pct = max > 0 ? (item.qty / max) * 100 : 0;
        const col = $(`
            <div class="bar-col" title="${item.name}: ${item.qty} ${item.unit}">
                <div class="bar" style="height:${pct}%">
                    <div class="bar-value">${item.qty}</div>
                </div>
                <div class="bar-label">${item.name}</div>
            </div>
        `);
        container.append(col);
    });
}

function renderValueTable(items, totalValue) {
    const tbody = $('#valueTable tbody').empty();
    items.forEach((item, i) => {
        tbody.append(`
            <tr>
                <td>${i + 1}</td>
                <td>${item.name}</td>
                <td class="text-end">${item.qty} ${item.unit}</td>
                <td class="text-end">${formatNumber(item.unit_cost)}</td>
                <td class="text-end">${formatNumber(item.value)}</td>
            </tr>
        `);
    });
    if (!items.length) {
        tbody.append('<tr><td colspan="5" class="text-center text-muted">ไม่มีข้อมูล</td></tr>');
    }
    $('#totalValueCell').text(formatNumber(totalValue));
}

function renderLowStock(lowStock) {
    const tbody = $('#lowStockTable tbody').empty();
    lowStock.forEach((m, i) => {
        tbody.append(`
            <tr>
                <td>${i + 1}</td>
                <td>${m.name}</td>
                <td class="text-end text-danger fw-bold">${m.stock_qty}</td>
                <td class="text-end">${m.min_stock}</td>
                <td>${m.unit}</td>
            </tr>
        `);
    });
    if (!lowStock.length) {
        tbody.append('<tr><td colspan="5" class="text-center text-muted">ไม่มีวัสดุใกล้หมดสต๊อก</td></tr>');
    }
}

$(document).ready(loadReport);

// @font-face files referenced only inside @media print are fetched lazily -
// not until the browser actually paints print output. If the user clicks
// "พิมพ์รายงาน" before that fetch finishes, the print output can render
// blank (observed in testing). Forcing the font to load as soon as the page
// is ready avoids that race, while the on-screen page still shows the
// normal Bootstrap font since font-family stays scoped to @media print.
if (document.fonts) {
    document.fonts.load("400 16px 'TH Sarabun New'");
    document.fonts.load("700 16px 'TH Sarabun New'");
}
</script>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js"></script>
<script src="<?= INV_URL ?>assets/js/thai-date-select.js<?= assetVersion('assets/js/thai-date-select.js') ?>"></script>
<?php require_once __DIR__ . '/footer.php'; ?>
