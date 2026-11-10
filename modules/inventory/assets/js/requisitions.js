const REQ_API = INV_URL_JS + 'api/requisition_api';
const REQ_PRINT_URL = INV_URL_JS + 'requisition_print';
const REQ_VIEW_URL = INV_URL_JS + 'requisition_view';

function loadRequisitions() {
    const params = {
        action: 'list',
        keyword: $('#f_keyword').val(),
        start_date: thaiDateSelectGet('f_start'),
        end_date: thaiDateSelectGet('f_end'),
    };

    $.get(REQ_API, params, function (res) {
        const tbody = $('#requisitionsTable tbody').empty();
        res.data.forEach((r) => {
            const statusBadge = r.approved_at
                ? `<span class="badge bg-success" title="อนุมัติโดย ${escapeHtml(r.approved_by_name || '-')} เมื่อ ${formatThaiDateTime(r.approved_at)}">อนุมัติแล้ว</span>`
                : '<span class="badge bg-secondary">รออนุมัติ</span>';
            // Once approved, cancelling is blocked server-side too (see
            // api/requisition_api.php) - hiding the button here is just so
            // the admin doesn't click it and get a rejection message.
            const actionBtns = IS_ADMIN_JS ? (r.approved_at ? '' : `
                <button type="button" class="btn btn-sm btn-outline-success" onclick="approveRequisition(${escapeHtml(r.id)}, ${jsStr(r.requisition_no)})">
                    <i class="bi bi-check-circle"></i> อนุมัติ
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="cancelRequisition(${escapeHtml(r.id)}, ${jsStr(r.requisition_no)})">
                    <i class="bi bi-x-circle"></i> ยกเลิก
                </button>`) : '';
            tbody.append(`
                <tr>
                    <td><a href="${REQ_VIEW_URL}?id=${escapeHtml(r.id)}">${escapeHtml(r.requisition_no)}</a></td>
                    <td>${formatThaiDateTime(r.created_at)}</td>
                    <td>${escapeHtml(r.requester_name || '-')}</td>
                    <td>${escapeHtml(r.purpose || '-')}</td>
                    <td>${escapeHtml(r.item_count)}</td>
                    <td>${escapeHtml(r.created_by_name)}</td>
                    <td>${statusBadge}</td>
                    <td class="text-end">
                        <a href="${REQ_PRINT_URL}?id=${escapeHtml(r.id)}" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-printer"></i> พิมพ์
                        </a>
                        ${actionBtns}
                    </td>
                </tr>
            `);
        });
        if (!res.data.length) {
            tbody.append('<tr><td colspan="8" class="text-center text-muted">ไม่พบข้อมูลใบเบิก</td></tr>');
        }
    });
}

// Admin only. Records who approved the requisition; once approved,
// cancelRequisition() below is blocked (server-side too - see
// api/requisition_api.php).
function approveRequisition(id, requisitionNo) {
    if (!confirm(`ยืนยันอนุมัติใบเบิกเลขที่ ${requisitionNo}?\n\nหลังอนุมัติแล้วจะไม่สามารถยกเลิกใบเบิกนี้ได้อีก`)) {
        return;
    }
    $.post(REQ_API, { action: 'approve', id }, function (res) {
        alert(res.message);
        if (res.success) {
            loadRequisitions();
        }
    }, 'json').fail(function (xhr) {
        alert((xhr.responseJSON && xhr.responseJSON.message) || 'เกิดข้อผิดพลาดในการอนุมัติใบเบิก');
    });
}

// Admin only. Deletes the requisition entirely and restores every
// withdrawn quantity back to stock - see api/requisition_api.php's
// cancelRequisition() for why this is a hard delete, not a status flag.
// Blocked once the requisition has been approved.
function cancelRequisition(id, requisitionNo) {
    if (!confirm(`ยืนยันยกเลิกใบเบิกเลขที่ ${requisitionNo}?\n\nระบบจะลบใบเบิกนี้ออกอย่างถาวร และคืนจำนวนวัสดุที่เบิกไปทั้งหมดกลับเข้าสต๊อก ไม่สามารถย้อนกลับได้`)) {
        return;
    }
    $.post(REQ_API, { action: 'cancel', id }, function (res) {
        alert(res.message);
        if (res.success) {
            loadRequisitions();
        }
    }, 'json').fail(function (xhr) {
        alert((xhr.responseJSON && xhr.responseJSON.message) || 'เกิดข้อผิดพลาดในการยกเลิกใบเบิก');
    });
}

$('#f_keyword').on('keypress', function (e) {
    if (e.which === 13) loadRequisitions();
});

$(document).ready(loadRequisitions);
