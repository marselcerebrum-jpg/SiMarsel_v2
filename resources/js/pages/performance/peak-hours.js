/**
 * Jam Ramai Pembelian (FIX-12-FE-01): Success payments per WIB payment hour, 24 slots, for the active period and
 * optionally its comparison. Slots, values, availability and day options come from `hourly` of
 * `GET /api/performance/purchase-times` (docs/api/purchase-times.md); this module only maps them to Chart.js and
 * to display text.
 *
 * Free of Chart.js and Alpine so it can be tested in Node.
 */

import { formatAxis } from '../../format';
import { formatCount, formatRupiah, formatWibDateTime } from './formatters';
import { bucketIndexAt } from './combined-trend';

export const NOT_AVAILABLE = 'Belum Tersedia';
export const ALL_DAYS = 'Semua Hari';
export const NO_COMPARISON = 'Periode ini tidak memiliki pembanding.';

/** Bar height is the Success payment count; the comparison bar is outlined so it is not told apart by color alone. */
export const SERIES = Object.freeze({
    active: { key: 'active', label: 'Periode Aktif', color: '#3b5b8c', border: '#3b5b8c', pattern: 'solid' },
    comparison: { key: 'comparison', label: 'Pembanding', color: '#94a3b8', border: '#475569', pattern: 'outline' },
});

/** Below this chart width (px) only a few hour labels are drawn; every slot stays in the data and the detail. */
export const COMPACT_WIDTH = 640;

const TICKS = Object.freeze({ wide: 24, compact: 6 });

const UNAVAILABLE_FILL = 'rgba(148, 163, 184, 0.16)';
const ACTIVE_FILL = 'rgba(59, 91, 140, 0.08)';
const GRID_COLOR = '#e3e6ea';
const INK = '#1e293b';

const isMissing = (value) => value === null || value === undefined;

/** A side of a slot with a value: not missing and not `unavailable`. */
export function isKnown(side) {
    return !isMissing(side) && side.availability !== 'unavailable' && !isMissing(side.success_payments);
}

/** Bar height of one side of a slot; null (a gap, never 0) when that hour is not covered yet. */
export function slotValue(side) {
    return isKnown(side) ? Number(side.success_payments) : null;
}

/** "19.00" from the slot `start` "19:00". */
export function hourLabel(slot) {
    return (slot?.start ?? '').replace(':', '.');
}

/** "19.00–19.59 WIB". */
export function slotTime(slot) {
    return `${slot.label} WIB`;
}

/** Whether the comparison series can be shown: the period has one and it contains the chosen day. */
export function hasComparison(hourly) {
    return !isMissing(hourly?.comparison);
}

/** Why the comparison cannot be shown, or '' when it can. */
export function comparisonNotice(hourly) {
    if (!hourly || hasComparison(hourly)) return '';

    return hourly.comparison_message || NO_COMPARISON;
}

/** Series drawn for the current choice: the active period, plus the comparison when it is turned on and present. */
export function visibleSeries(hourly, showComparison) {
    return showComparison && hasComparison(hourly) ? [SERIES.active, SERIES.comparison] : [SERIES.active];
}

/** Day choice for the select: Semua Hari first, then the days of the active period. */
export function dayOptions(hourly) {
    return [{ value: '', label: ALL_DAYS }, ...(hourly?.day_options ?? []).map(({ value, label }) => ({ value: String(value), label }))];
}

function sideNote(side) {
    if (isKnown(side) && side.availability === 'partial') return `Sebagian, data sampai ${formatWibDateTime(side.as_of)}`;

    return '';
}

/**
 * Detail of one slot: its WIB hour, and the payment count and revenue of each visible series. An hour that is
 * not covered yet reads "Belum Tersedia" and is never shown as 0.
 */
export function slotDetail(slot, series) {
    return {
        time: slotTime(slot),
        rows: series.map((item) => {
            const side = slot[item.key];
            const known = isKnown(side);

            return {
                key: item.key,
                label: item.label,
                color: item.color,
                border: item.border,
                pattern: item.pattern,
                known,
                payments: known ? `${formatCount(side.success_payments)} pembayaran` : NOT_AVAILABLE,
                revenue: known ? formatRupiah(side.revenue) : NOT_AVAILABLE,
                note: sideNote(side),
            };
        }),
    };
}

/** One table row per slot, with the same text as the detail. */
export function slotRows(slots, series) {
    return slots.map((slot) => ({ hour: slot.hour, time: slot.label, ...slotDetail(slot, series) }));
}

/**
 * Hours of the active period that are not covered yet, joined into ranges: "14.00–23.59" or
 * "00.00–05.59, 14.00–23.59". '' when every hour has data.
 */
export function unavailableRanges(slots, key = 'active') {
    const ranges = [];

    slots.forEach((slot) => {
        if (isKnown(slot[key])) return;

        const last = ranges.at(-1);
        if (last && last.to.hour === slot.hour - 1) last.to = slot;
        else ranges.push({ from: slot, to: slot });
    });

    return ranges.map(({ from, to }) => `${hourLabel(from)}–${to.end.replace(':', '.')}`).join(', ');
}

/** Totals of the active period: "1.234 pembayaran · Rp 12.345.678", or "Belum Tersedia". */
export function totalsLabel(side) {
    const totals = side?.totals;
    if (isMissing(totals?.success_payments)) return NOT_AVAILABLE;

    return `${formatCount(totals.success_payments)} pembayaran · ${formatRupiah(totals.revenue)}`;
}

/** Busiest known hour of the active period, e.g. "19.00–19.59 WIB", or '' when no hour has a payment. */
export function peakSlot(slots) {
    let peak = null;

    slots.forEach((slot) => {
        const value = slotValue(slot.active);
        if (value !== null && value > 0 && (peak === null || value > slotValue(peak.active))) peak = slot;
    });

    return peak ? slotTime(peak) : '';
}

/** Accessible chart name, with the hours that are not covered yet as text. */
export function chartLabel(hourly, series) {
    const day = hourly?.day_label || ALL_DAYS;
    const names = series.map(({ label }) => label).join(' dan ');
    const missing = unavailableRanges(hourly?.slots ?? []);

    return `Grafik batang jumlah pembayaran berhasil per jam WIB, ${day}, ${names}.${missing ? ` ${NOT_AVAILABLE}: ${missing}.` : ''}`;
}

/** Chart.js datasets: one bar per slot and series; a slot that is not covered stays null, so it is a gap. */
export function hourDatasets(slots, series) {
    return series.map((item) => ({
        label: item.label,
        data: slots.map((slot) => slotValue(slot[item.key])),
        backgroundColor: item.color,
        borderColor: item.border,
        borderWidth: item.pattern === 'outline' ? 1.5 : 0,
        borderRadius: 3,
        borderSkipped: 'bottom',
        maxBarThickness: 24,
        categoryPercentage: 0.8,
        barPercentage: 0.9,
    }));
}

export function tickLimit(compact) {
    return compact ? TICKS.compact : TICKS.wide;
}

/** Fewer visible hour labels on narrow charts; called on every Chart.js resize. */
export function applyTickLimit(chart, width) {
    chart.options.scales.x.ticks.maxTicksLimit = tickLimit(width < COMPACT_WIDTH);
}

/**
 * Column bands behind the bars: a tinted band with a short "Belum Tersedia" mark for every hour of the active
 * period that is not covered yet (when there is room for it), and a light band on the open slot.
 */
export const slotBands = {
    id: 'slotBands',
    beforeDatasetsDraw(chart, _args, options) {
        const { ctx, chartArea: { top, bottom } } = chart;
        const x = chart.scales.x;
        const unavailable = options.unavailable ?? [];
        const width = unavailable.length > 0 || !isMissing(options.index)
            ? Math.abs(x.getPixelForValue(1) - x.getPixelForValue(0)) || 0
            : 0;
        const band = (index, fill) => {
            const center = x.getPixelForValue(index);
            ctx.fillStyle = fill;
            ctx.fillRect(center - width / 2, top, width, bottom - top);
        };

        ctx.save();
        if (!isMissing(options.index)) band(options.index, ACTIVE_FILL);
        unavailable.forEach((index) => band(index, UNAVAILABLE_FILL));

        // The vertical text needs a column at least as wide as its line height.
        if (width >= 14 && unavailable.length > 0) {
            ctx.fillStyle = '#64748b';
            ctx.font = '600 10px system-ui, sans-serif';
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            unavailable.forEach((index) => {
                ctx.save();
                ctx.translate(x.getPixelForValue(index), top + 6);
                ctx.rotate(-Math.PI / 2);
                ctx.fillText(NOT_AVAILABLE, 0, 0);
                ctx.restore();
            });
        }
        ctx.restore();
    },
};

/**
 * Chart.js config. The Chart.js legend and tooltip are off: the HTML legend names the series, and the slot detail
 * is rendered from slotDetail() for hover, tap and keyboard. `onSlot(index)` gets the slot under the pointer;
 * `onLeave()` fires when the mouse leaves the canvas.
 */
export function peakHoursConfig({ slots, series, compact = false, onSlot = () => {}, onLeave = () => {} }) {
    const pick = (event, chart) => {
        if (event.type === 'mouseout') return onLeave();

        const index = bucketIndexAt(chart, event, slots.length);
        if (index !== null) onSlot(index);
    };

    return {
        type: 'bar',
        data: {
            labels: slots.map(hourLabel),
            datasets: hourDatasets(slots, series),
        },
        plugins: [slotBands],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            events: ['mousemove', 'mouseout', 'click', 'touchstart'],
            onHover: (event, _elements, chart) => pick(event, chart),
            onClick: (event, _elements, chart) => pick(event, chart),
            onResize: (chart, size) => applyTickLimit(chart, size.width),
            layout: { padding: { top: 8 } },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
                slotBands: {
                    index: null,
                    unavailable: slots.filter((slot) => !isKnown(slot.active)).map((slot) => slots.indexOf(slot)),
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    title: { display: true, text: 'Jam bayar (WIB)', color: INK, font: { weight: '600' } },
                    ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 8, maxTicksLimit: tickLimit(compact) },
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Pembayaran berhasil', color: INK, font: { weight: '600' } },
                    grid: { color: GRID_COLOR },
                    border: { display: false },
                    ticks: { maxTicksLimit: 6, padding: 6, precision: 0, callback: (value) => formatAxis(value, 'number') },
                },
            },
        },
    };
}
