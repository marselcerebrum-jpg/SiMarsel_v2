/**
 * Display formatters shared by the Dashboard components.
 *
 * A metric from the backend is either a plain number or `{ value, available }`:
 * - `available: false`            → coverage or snapshot is missing ("Belum tersedia")
 * - `available: true, value: null` → ratio with a zero divisor ("Belum dapat dihitung")
 * - `available: true, value: 0`    → complete period without data ("0")
 *
 * Every text is readable on its own, so no meaning depends on color.
 */

export const UNAVAILABLE = 'Belum tersedia';
export const NOT_COMPUTABLE = 'Belum dapat dihitung';
export const COMPARISON_UNAVAILABLE = 'Pembanding belum tersedia';
export const STATUS_UNAVAILABLE = 'Status belum tersedia';
export const PAID_AT_UNAVAILABLE = 'Tanggal bayar belum tersedia';
export const PROVISIONAL = 'Sementara';

const STATUS_LABELS = Object.freeze({ 0: 'Pending', 1: 'Success', 2: 'Expired', 3: 'Failed', 4: 'Canceled' });

const count = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
const percent = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const signedPercent = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1, signDisplay: 'exceptZero' });
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const wibDateTime = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});
const wibDate = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', day: 'numeric', month: 'short', year: 'numeric' });
const calendarDate = new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' });

const isMissing = (value) => value === null || value === undefined || value === '';

function display(metric, format) {
    if (isMissing(metric)) return UNAVAILABLE;

    if (typeof metric === 'object') {
        if (metric.available === false) return UNAVAILABLE;
        if (isMissing(metric.value)) return NOT_COMPUTABLE;

        return format(Number(metric.value));
    }

    return format(Number(metric));
}

/** "12", "1.250", "0". */
export function formatCount(metric) {
    return display(metric, (value) => count.format(value));
}

/** "Rp 360.000", "Rp 0". */
export function formatRupiah(metric) {
    return display(metric, (value) => rupiah.format(value).replace(/\s/g, ' '));
}

/** "60%", "33,33%", "0%". */
export function formatPercent(metric) {
    return display(metric, (value) => `${percent.format(value)}%`);
}

/** Relative change against the comparison, e.g. "+20%", "-12,5%". */
export function formatRelativeChange(metric) {
    return display(metric, (value) => `${signedPercent.format(value)}%`);
}

/** Difference between two percentages, e.g. "+10 poin persentase". */
export function formatPercentagePoints(metric) {
    return display(metric, (value) => `${signedPercent.format(value)} poin persentase`);
}

/** ISO timestamp with offset → "10 Nov 2026, 23.50 WIB". */
export function formatWibDateTime(value) {
    if (isMissing(value)) return UNAVAILABLE;

    const parts = Object.fromEntries(wibDateTime.formatToParts(new Date(value)).map(({ type, value: part }) => [type, part]));

    return `${parts.day} ${parts.month} ${parts.year}, ${parts.hour}.${parts.minute} WIB`;
}

/** ISO timestamp → "10 Nov 2026" in WIB, or a report date "YYYY-MM-DD" → "10 Nov 2026". */
export function formatWibDate(value) {
    if (isMissing(value)) return UNAVAILABLE;

    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return calendarDate.format(new Date(`${value}T00:00:00Z`));

    return wibDate.format(new Date(value));
}

/**
 * Report payment date and time (`paid_date` "YYYY-MM-DD", `paid_time` "HH:mm[:ss]", both WIB).
 * An empty payment date stays readable and never falls back to the sync time.
 */
export function formatPaidAt(paidDate, paidTime) {
    if (isMissing(paidDate)) return PAID_AT_UNAVAILABLE;

    const date = formatWibDate(paidDate);
    if (isMissing(paidTime)) return date;

    const [hour, minute] = paidTime.split(':');

    return `${date}, ${hour}.${minute} WIB`;
}

/** Resolved report status code (0–4) → label; null means neither status field is filled. */
export function statusLabel(code) {
    return STATUS_LABELS[code] ?? STATUS_UNAVAILABLE;
}

/** Note beside Konversi from the created metrics: "Sementara · 1 status belum tersedia", or "" when it is final. */
export function conversionNote(metrics) {
    if (metrics?.is_provisional !== true) return '';

    return `${PROVISIONAL} · ${count.format(metrics.unknown_status_count)} ${STATUS_UNAVAILABLE.toLowerCase()}`;
}
