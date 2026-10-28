// Turns every .thai-datepicker input (from includes/functions.php:
// renderThaiDateSelect()) into a click-to-pick flatpickr calendar. The
// field's own value stays the underlying ISO date (Y-m-d, what the API
// filters expect); the visible text always reads "9 ตุลาคม 2569" (Thai
// month name, พ.ศ. year) via a custom formatDate override, since flatpickr's
// built-in Thai locale only translates month/day names, not the พ.ศ. year.
const THAI_MONTHS = [
    'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
];

function pad2(n) {
    return String(n).padStart(2, '0');
}

$(document).ready(() => {
    $('.thai-datepicker').each(function () {
        flatpickr(this, {
            altInput: true,
            altFormat: 'thai-be', // arbitrary token - formatDate() below branches on it
            dateFormat: 'Y-m-d',
            allowInput: false,
            locale: 'th',
            formatDate: (date, format) => {
                if (format === 'Y-m-d') {
                    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
                }
                return `${date.getDate()} ${THAI_MONTHS[date.getMonth()]} ${date.getFullYear() + 543}`;
            },
        });
    });
});

// Returns 'YYYY-MM-DD', or '' if no date is chosen (treated as "no filter"
// by the pages that use this).
function thaiDateSelectGet(prefix) {
    return $(`#${prefix}`).val() || '';
}

// Sets the picker from an ISO (Gregorian) date string, or clears it if
// isoDate is empty/null.
function thaiDateSelectSet(prefix, isoDate) {
    const el = document.getElementById(prefix);
    if (!el || !el._flatpickr) {
        return;
    }
    el._flatpickr.setDate(isoDate || null, true);
}

// "9 ตุลาคม 2569 14:23 น." from a SQL datetime string ("YYYY-MM-DD HH:MM:SS")
// - the JS counterpart of includes/functions.php:formatThaiDateTime(), for
// rows rendered client-side from an API response.
function formatThaiDateTime(sqlDatetime) {
    if (!sqlDatetime) {
        return '';
    }
    const d = new Date(sqlDatetime.replace(' ', 'T'));
    if (isNaN(d.getTime())) {
        return sqlDatetime;
    }
    return `${d.getDate()} ${THAI_MONTHS[d.getMonth()]} ${d.getFullYear() + 543} ${pad2(d.getHours())}:${pad2(d.getMinutes())} น.`;
}
