const currency = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const number = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
const decimal = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 2 });
const compactCurrency = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', notation: 'compact', maximumFractionDigits: 2 });
const compactNumber = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
const longDate = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const shortDate = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });
const shortMonth = new Intl.DateTimeFormat('id-ID', { month: 'short', year: 'numeric' });
const longMonth = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });
const weekday = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

export function formatValue(value, format, { compact = false } = {}) {
    if (value === null || value === undefined) return '—';

    if (format === 'currency') return (compact ? compactCurrency : currency).format(value);
    if (format === 'percent') return `${decimal.format(value)}%`;

    return number.format(value);
}

function toDate(value) {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

export function formatDate(value) {
    return value ? longDate.format(toDate(value)) : '';
}

/**
 * Short value for chart axes, e.g. "Rp 5,6 jt" or "12,5%".
 */
export function formatAxis(value, format) {
    if (format === 'currency') return compactCurrency.format(value);
    if (format === 'percent') return `${number.format(value)}%`;

    return compactNumber.format(value);
}

/**
 * Label of one trend bucket. Short for axes, long for tooltips and the table.
 */
export function formatPeriod({ period_start: start, period_end: end }, granularity, { long = false } = {}) {
    if (!start) return '';

    if (granularity === 'monthly') return (long ? longMonth : shortMonth).format(toDate(start));
    if (granularity === 'weekly') {
        return long
            ? formatRange({ start_date: start, end_date: end ?? start })
            : `${shortDate.format(toDate(start))} – ${shortDate.format(toDate(end ?? start))}`;
    }

    return long ? weekday.format(toDate(start)) : shortDate.format(toDate(start));
}

export function formatRange(range) {
    if (!range?.start_date) return '';

    return range.start_date === range.end_date
        ? formatDate(range.start_date)
        : `${formatDate(range.start_date)} – ${formatDate(range.end_date)}`;
}

const TRENDS = {
    up: { label: 'Up', word: 'Naik', symbol: '↑', tone: 'bg-emerald-50 text-emerald-700', textTone: 'text-emerald-600' },
    down: { label: 'Down', word: 'Turun', symbol: '↓', tone: 'bg-red-50 text-red-600', textTone: 'text-red-600' },
    same: { label: 'Same', word: 'Sama', symbol: '=', tone: 'bg-soft text-muted', textTone: 'text-muted' },
};

/**
 * Comparison with the previous period. Trend and change come from the backend;
 * a null trend means there is no previous data to compare against.
 *
 * Badge labels are English (titles); the caption sentence is Indonesian (description).
 *
 * - text: table badge ("↓ 57,6% Down")
 * - short: card badge ("↓ 57,6%")
 * - sentence: card caption ("Turun 57,6% dari periode sebelumnya")
 */
export function trendBadge(metric) {
    const trend = TRENDS[metric?.trend];

    if (!trend) {
        return { tone: 'bg-soft text-label', textTone: 'text-label', text: 'No Comparison', short: '', sentence: 'Tidak ada data pembanding' };
    }

    const hasChange = metric.change_percent !== null && metric.change_percent !== undefined && metric.trend !== 'same';
    const change = hasChange ? `${decimal.format(Math.abs(metric.change_percent))}%` : '';

    return {
        tone: trend.tone,
        textTone: trend.textTone,
        text: `${trend.symbol} ${change} ${trend.label}`.replace(/\s+/g, ' '),
        short: hasChange ? `${trend.symbol} ${change}` : trend.label,
        sentence: metric.trend === 'same'
            ? 'Sama dengan periode sebelumnya'
            : `${trend.word} ${change} dari periode sebelumnya`.replace(/\s+/g, ' '),
    };
}

const dateTime = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

export function formatDateTime(date) {
    return dateTime.format(date);
}
