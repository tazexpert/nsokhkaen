const LOCATIONS_API = INV_URL_JS + 'api/storage_locations_api.php';
const SETTINGS_API = INV_URL_JS + 'api/settings_api.php';
const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ---------------- Storage locations ---------------- */

function loadStorageLocations() {
    $.get(LOCATIONS_API, { action: 'list' }, function (res) {
        const tbody = $('#locationsTable tbody').empty();
        res.data.forEach((loc) => {
            tbody.append(`
                <tr>
                    <td>${escHtml(loc.name)}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteStorageLocation(${Number(loc.id)})"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            `);
        });
    });
}

function addStorageLocation() {
    const name = $('#newLocationName').val().trim();
    if (!name) return;
    $.post(LOCATIONS_API, { action: 'create', name })
        .done(() => {
            $('#newLocationName').val('');
            loadStorageLocations();
        })
        .fail((xhr) => alert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

function deleteStorageLocation(id) {
    if (!confirm('ยืนยันการลบสถานที่จัดเก็บนี้?')) return;
    $.post(LOCATIONS_API, { action: 'delete', id })
        .done(() => loadStorageLocations())
        .fail((xhr) => alert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

/* ---------------- ผู้ลงนามในใบเบิก ---------------- */

function loadAppSettings() {
    $.get(SETTINGS_API, { action: 'get' }, function (res) {
        $('#s_issuer_name').val(res.data.issuer_name || '');
        $('#s_issuer_position').val(res.data.issuer_position || '');
        $('#s_approver_name').val(res.data.approver_name || '');
        $('#s_approver_position').val(res.data.approver_position || '');
    });
}

function saveAppSettings() {
    const payload = {
        action: 'update',
        issuer_name: $('#s_issuer_name').val(),
        issuer_position: $('#s_issuer_position').val(),
        approver_name: $('#s_approver_name').val(),
        approver_position: $('#s_approver_position').val(),
    };
    $.post(SETTINGS_API, payload)
        .done((res) => {
            $('#appSettingsResult').html(`<div class="alert alert-success mt-3">${escHtml(res.message)}</div>`);
            loadAppSettings();
        })
        .fail((xhr) => {
            $('#appSettingsResult').html(`<div class="alert alert-danger mt-3">${escHtml(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด')}</div>`);
        });
}

$(document).ready(() => {
    loadStorageLocations();
    loadAppSettings();
});

