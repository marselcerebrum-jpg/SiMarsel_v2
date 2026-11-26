/**
 * Ringkasan Sales cards (FIX-05-FE-01): turns `data.sales_summary` of GET /api/performance/summary
 * (FIX-05-BE-01, docs/api/performance.md) into the display text of the seven metrics. Every value, comparison
 * and change comes from the backend; nothing is recomputed here.
 */

import {
    COMPARISON_UNAVAILABLE,
    NOT_COMPUTABLE,
    conversionNote,
    formatCount,
    formatPercent,
    formatPercentagePoints,
    formatRelativeChange,
    formatRupiah,
} from './formatters';

export const SALES_SUMMARY_METRICS = Object.freeze([
    'revenue',
    'success_payments',
    'total_checkouts',
    'conversion',
    'average_order_value',
    'unique_buyers',
    'downloaders',
]);

const FORMATS = Object.freeze({ currency: formatRupiah, count: formatCount, percentage: formatPercent });

const DIRECTIONS = Object.freeze({
    up: { symbol: '▲', label: 'Naik' },
    down: { symbol: '▼', label: 'Turun' },
    same: { symbol: '=', label: 'Tidak berubah' },
});

const NO_CHANGE = Object.freeze({ text: '', symbol: '', label: '' });

/** One side of a metric as the formatter metric: `null` with a known side means it cannot be computed. */
const metricOf = (side) => ({ value: side?.value ?? null, available: Boolean(side) && side.availability !== 'unavailable' });

const hasNumber = (metric) => metric.available && metric.value !== null;

/**
 * Change of a Ringkasan Sales metric as the backend returns it: Konversi in percentage points, the others relative.
 * An empty relative change with a comparison of 0 reads "Belum dapat dihitung"; with a missing comparison it stays empty.
 */
function summaryChangeOf(metric) {
    const active = metricOf(metric?.active);
    const comparison = metricOf(metric?.comparison);
    const change = metric?.change;

    if (!hasNumber(active) || !hasNumber(comparison)) return NO_CHANGE;

    if (change?.value === null || change?.value === undefined) {
        return Number(comparison.value) === 0 ? { ...NO_CHANGE, text: NOT_COMPUTABLE } : NO_CHANGE;
    }

    const format = change.unit === 'percentage_point' ? formatPercentagePoints : formatRelativeChange;

    return { text: format({ value: change.value, available: true }), ...(DIRECTIONS[change.trend] ?? { symbol: '', label: '' }) };
}

/** Context under Total Transaksi and Konversi Transaksi, only from values the backend returned. */
function detailOf(metric) {
    const active = metric?.active;
    if (!active || active.availability === 'unavailable') return '';

    if (metric.key === 'total_checkouts') {
        const attempts = active.breakdown?.purchase_attempts ?? null;
        const claims = active.breakdown?.claim_checkouts ?? null;

        return attempts === null || claims === null ? '' : `${formatCount(attempts)} percobaan pembelian · ${formatCount(claims)} klaim`;
    }

    if (metric.key === 'conversion') {
        const { success_purchases: success = null, purchase_attempts: attempts = null } = active;

        return success === null || attempts === null ? '' : `${formatCount(success)} dari ${formatCount(attempts)} percobaan pembelian`;
    }

    return '';
}

function cardOf(key, metric) {
    const format = FORMATS[metric?.unit] ?? formatCount;
    const comparison = metricOf(metric?.comparison);
    const active = metric?.active;

    return {
        key,
        value: format(metricOf(active)),
        comparison: comparison.available ? format(comparison) : COMPARISON_UNAVAILABLE,
        change: summaryChangeOf(metric),
        note: key === 'conversion' && active && active.availability !== 'unavailable' ? conversionNote(active) : '',
        detail: detailOf(metric),
    };
}

/** Display text of every card, keyed by metric. A missing summary reads as "Belum tersedia". */
export function salesSummaryCards(summary) {
    const metrics = new Map((summary?.metrics ?? []).map((metric) => [metric.key, metric]));

    return Object.fromEntries(SALES_SUMMARY_METRICS.map((key) => [key, cardOf(key, metrics.get(key))]));
}
