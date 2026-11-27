/**
 * Combined trend chart (FIX-06-FE-01): Omzet, Omzet Pembanding, Transaksi Berhasil and Total Transaksi over
 * time, with Rupiah and Jumlah Transaksi on separate axes. Series, values and times come from
 * `GET /api/performance/trend/sales` (see fixtures/trend.js); this module only maps them to Chart.js and to
 * display text.
 *
 * Free of Chart.js and Alpine so it can be tested in Node.
 */

import { formatAxis } from '../../format';
import { UNAVAILABLE, formatCount, formatRupiah } from './formatters';

export const GRANULARITIES = Object.freeze([
    { value: 'hourly', label: 'Per Jam' },
    { value: 'daily', label: 'Per Hari' },
    { value: 'weekly', label: 'Per Minggu' },
    { value: 'monthly', label: 'Per Bulan' },
]);

/** Axis per series `axis` from the response. */
export const AXES = Object.freeze({
    currency: { id: 'currency', title: 'Rupiah' },
    count: { id: 'count', title: 'Jumlah Transaksi' },
});

const COLORS = Object.freeze({
    revenue: '#059669',
    comparison_revenue: '#64748b',
    success_payments: '#3b5b8c',
    total_checkouts: '#f59e0b',
});
const FALLBACK_COLOR = '#94a3b8';

/** Below this chart width (px) fewer axis labels are drawn; the point detail stays complete. */
export const COMPACT_WIDTH = 640;

const TICKS = Object.freeze({
    wide: { x: 12, y: 6 },
    compact: { x: 4, y: 4 },
});

const GUIDE_COLOR = '#94a3b8';
const GRID_COLOR = '#e3e6ea';
const INK = '#1e293b';

const isMissing = (value) => value === null || value === undefined;

/**
 * The response `series` with how each is drawn: Rupiah series are lines (the comparison one dashed), count
 * series are bars. The line pattern and the label tell the active and comparison lines apart, not only color.
 */
export function trendSeries(series = []) {
    return series.map((item) => {
        const type = item.axis === AXES.currency.id ? 'line' : 'bar';

        return {
            ...item,
            type,
            pattern: type === 'bar' ? 'bar' : item.side === 'comparison' ? 'dashed' : 'solid',
            color: COLORS[item.key] ?? FALLBACK_COLOR,
        };
    });
}

/** Initial visibility from the response: `visible: false` series start hidden. */
export function defaultVisibility(series = []) {
    return Object.fromEntries(series.map(({ key, visible }) => [key, visible === true]));
}

export function granularityLabel(value) {
    return GRANULARITIES.find((option) => option.value === value)?.label ?? '';
}

/** Value of one series in one bucket; null when that side or its values are not available. */
export function seriesValue(series, bucket) {
    const value = bucket?.[series.side]?.values?.[series.value];

    return isMissing(value) ? null : Number(value);
}

const wibParts = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

function wib(iso, { exclusiveEnd = false } = {}) {
    const read = (date) => Object.fromEntries(wibParts.formatToParts(date).map(({ type, value }) => [type, value]));
    let parts = read(new Date(iso));
    let time = `${parts.hour}.${parts.minute}`;

    // An exclusive end at midnight is the end of the day before: "10 Nov 2026, 24.00".
    if (exclusiveEnd && time === '00.00') {
        parts = read(new Date(Date.parse(iso) - 60_000));
        time = '24.00';
    }

    return { day: parts.day, month: parts.month, year: parts.year, date: `${parts.day} ${parts.month} ${parts.year}`, time };
}

/**
 * Time of one side of a bucket, e.g. "11 Nov 2026, 08.00–09.00 WIB" or "4 Nov 2026, 00.00 – 10 Nov 2026, 24.00 WIB".
 * A partial bucket ends at its `as_of` boundary. A missing side is "Belum tersedia".
 */
export function formatBucketTime(side) {
    if (!side?.start_at || !side?.end_at) return UNAVAILABLE;

    const start = wib(side.start_at);
    const end = wib(side.availability === 'partial' && side.as_of ? side.as_of : side.end_at, { exclusiveEnd: true });

    if (start.date === end.date) return `${start.date}, ${start.time}–${end.time} WIB`;

    return `${start.date}, ${start.time} – ${end.date}, ${end.time} WIB`;
}

/** Short x-axis label: "08.00" (or "5 Nov 08.00" over several days), "11 Nov", "Nov 2026". */
export function tickLabel(bucket, granularity, spansDays = false) {
    const start = wib(bucket.active.start_at);

    if (granularity === 'hourly') return spansDays ? `${start.day} ${start.month} ${start.time}` : start.time;
    if (granularity === 'monthly') return `${start.month} ${start.year}`;

    return `${start.day} ${start.month}`;
}

function displayValue(series, value) {
    if (isMissing(value)) return UNAVAILABLE;

    return series.unit === 'currency' ? formatRupiah(value) : `${formatCount(value)} transaksi`;
}

/**
 * Detail of one point: both bucket times and the value of each visible series with its unit.
 * A missing value stays "Belum tersedia" and is never shown as 0.
 */
export function pointDetail(bucket, series, visibility) {
    return {
        active: formatBucketTime(bucket.active),
        comparison: formatBucketTime(bucket.comparison),
        rows: series.filter(({ key }) => visibility[key]).map((item) => ({
            key: item.key,
            label: item.label,
            pattern: item.pattern,
            color: item.color,
            value: displayValue(item, seriesValue(item, bucket)),
        })),
    };
}

/** Accessible chart name, e.g. "Grafik tren per jam, 11 Nov 2026, 00.00–01.00 WIB sampai …". */
export function chartLabel(buckets, granularity) {
    if (buckets.length === 0) return 'Grafik tren';

    const first = formatBucketTime(buckets[0].active);
    const last = formatBucketTime(buckets[buckets.length - 1].active);

    return `Grafik tren ${granularityLabel(granularity).toLowerCase()}, ${first} sampai ${last}`;
}

/**
 * Chart.js datasets in `series` order. A null value stays null, so a bucket outside the complete boundary or a
 * missing comparison point leaves a gap instead of a 0.
 */
export function trendDatasets(buckets, series, visibility) {
    return series.map((item, index) => {
        const data = buckets.map((bucket) => seriesValue(item, bucket));
        const base = {
            label: item.label,
            data,
            yAxisID: AXES[item.axis]?.id ?? AXES.count.id,
            hidden: !visibility[item.key],
            borderColor: item.color,
        };

        if (item.type === 'bar') {
            return {
                ...base,
                type: 'bar',
                order: series.length + index,
                backgroundColor: `${item.color}b3`,
                borderWidth: 0,
                borderRadius: 3,
                maxBarThickness: 28,
            };
        }

        return {
            ...base,
            type: 'line',
            order: index,
            backgroundColor: item.color,
            borderWidth: 2,
            borderDash: item.pattern === 'dashed' ? [6, 4] : [],
            spanGaps: false,
            tension: 0.25,
            pointRadius: data.length > 48 ? 0 : 2.5,
            pointHoverRadius: 4,
            pointBackgroundColor: item.color,
        };
    });
}

export function tickLimits(compact) {
    return compact ? TICKS.compact : TICKS.wide;
}

/** Fewer visible axis labels on narrow charts; called on every Chart.js resize. */
export function applyTickLimits(chart, width) {
    const limits = tickLimits(width < COMPACT_WIDTH);
    const { x, currency, count } = chart.options.scales;

    x.ticks.maxTicksLimit = limits.x;
    currency.ticks.maxTicksLimit = limits.y;
    count.ticks.maxTicksLimit = limits.y;
}

/**
 * Bucket index under a pointer event, or null outside the plot area. Works on gaps too, so a bucket without
 * values still opens its detail.
 */
export function bucketIndexAt(chart, { x, y }, count) {
    const { left, right, top, bottom } = chart.chartArea;
    if (count === 0 || x < left || x > right || y < top || y > bottom) return null;

    const index = Math.round(chart.scales.x.getValueForPixel(x));

    return Math.min(Math.max(index, 0), count - 1);
}

/** Vertical guide at the open point. */
export const activeGuide = {
    id: 'activeGuide',
    afterDatasetsDraw(chart, _args, options) {
        if (isMissing(options.index)) return;

        const x = chart.scales.x.getPixelForValue(options.index);
        const { top, bottom } = chart.chartArea;
        const { ctx } = chart;

        ctx.save();
        ctx.strokeStyle = GUIDE_COLOR;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, top);
        ctx.lineTo(x, bottom);
        ctx.stroke();
        ctx.restore();
    },
};

function valueAxis(axis, position, compact, format) {
    return {
        type: 'linear',
        position,
        display: 'auto',
        beginAtZero: true,
        title: { display: true, text: axis.title, color: INK, font: { weight: '600' } },
        grid: position === 'left' ? { color: GRID_COLOR } : { drawOnChartArea: false },
        border: { display: false },
        ticks: { maxTicksLimit: tickLimits(compact).y, padding: 6, precision: 0, callback: format },
    };
}

/**
 * Chart.js config for the combined chart. The Chart.js legend and tooltip are off: the HTML legend is
 * keyboard operable, and the point detail is rendered from pointDetail() for hover, tap and keyboard.
 * `onPoint(index)` gets the bucket under the pointer; `onLeave()` fires when the mouse leaves the canvas.
 */
export function combinedTrendConfig({ buckets, series, granularity, visibility, compact = false, onPoint = () => {}, onLeave = () => {} }) {
    const spansDays = buckets.length > 0
        && wib(buckets[0].active.start_at).date !== wib(buckets[buckets.length - 1].active.start_at).date;
    const pick = (event, chart) => {
        if (event.type === 'mouseout') return onLeave();

        const index = bucketIndexAt(chart, event, buckets.length);
        if (index !== null) onPoint(index);
    };

    return {
        type: 'bar',
        data: {
            labels: buckets.map((bucket) => tickLabel(bucket, granularity, spansDays)),
            datasets: trendDatasets(buckets, series, visibility),
        },
        plugins: [activeGuide],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            events: ['mousemove', 'mouseout', 'click', 'touchstart'],
            onHover: (event, _elements, chart) => pick(event, chart),
            onClick: (event, _elements, chart) => pick(event, chart),
            onResize: (chart, size) => applyTickLimits(chart, size.width),
            layout: { padding: { top: 8 } },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
                activeGuide: { index: null },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 12, maxTicksLimit: tickLimits(compact).x },
                },
                currency: valueAxis(AXES.currency, 'left', compact, (value) => formatAxis(value, 'currency')),
                count: valueAxis(AXES.count, 'right', compact, (value) => formatAxis(value, 'number')),
            },
        },
    };
}
