/**
 * Downloader chart and Lihat Rincian table (FIX-08-FE-01). Series, values, times and changes come from
 * `GET /api/performance/trend/downloaders` (see fixtures/downloader.js); this module only maps them to Chart.js
 * and display text. The point detail and the table share downloaderRow(), so both always show the same numbers.
 *
 * Free of Chart.js and Alpine so it can be tested in Node.
 */

import { formatAxis } from '../../format';
import { COMPACT_WIDTH, GRANULARITIES, activeGuide, bucketIndexAt, granularityLabel, tickLimits, wib } from './combined-trend';
import {
    COMPARISON_UNAVAILABLE,
    NOT_COMPUTABLE,
    PROVISIONAL,
    UNAVAILABLE,
    formatCount,
    formatRelativeChange,
    formatWibDateTime,
} from './formatters';

const COLORS = Object.freeze({ active: '#059669', comparison: '#64748b' });
const FALLBACK_COLOR = '#94a3b8';
const DENSE_POINTS = 48;
const GRID_COLOR = '#e3e6ea';
const INK = '#1e293b';

const isMissing = (value) => value === null || value === undefined;

/** The response `series` with how each is drawn: the active line solid, the comparison line dashed. */
export function downloaderSeries(series = []) {
    return series.map((item) => ({
        ...item,
        pattern: item.side === 'comparison' ? 'dashed' : 'solid',
        color: COLORS[item.side] ?? FALLBACK_COLOR,
    }));
}

/** The groupings the backend accepts for the active range (`granularities`), in display order. */
export function granularityOptions(available = []) {
    return GRANULARITIES.filter(({ value }) => available.includes(value));
}

/** Value of one side of a bucket; null when that side is missing or not available. */
function sideValue(bucket, side) {
    const value = bucket?.[side]?.value;

    return isMissing(value) ? null : Number(value);
}

/**
 * Period of one side of a point. Per jam the value is cumulative, so the time is its cutoff (`as_of`):
 * "11 Nov 2026, sampai 14.00 WIB". Other groupings show their dates, plus the cutoff when partial:
 * "10 Nov 2026", "4 Nov 2026 – 10 Nov 2026", "11 Nov 2026, sampai 14.00 WIB".
 */
export function formatDownloaderPeriod(side, granularity) {
    if (!side?.start_at || !side?.end_at) return UNAVAILABLE;

    if (granularity === 'hourly') {
        const cutoff = wib(side.as_of ?? side.end_at, { exclusiveEnd: true });

        return `${cutoff.date}, sampai ${cutoff.time} WIB`;
    }

    const partial = side.availability === 'partial' && Boolean(side.as_of);
    const start = wib(side.start_at);
    const end = wib(partial ? side.as_of : side.end_at, { exclusiveEnd: true });
    const dates = start.date === end.date ? start.date : `${start.date} – ${end.date}`;

    return partial ? `${dates}, sampai ${end.time} WIB` : dates;
}

/** "+10%", "Belum dapat dihitung" for a comparison of 0, or empty when the two sides cannot be compared. */
export function formatDownloaderChange(change) {
    if (!change?.is_comparable) return '';

    return isMissing(change.change_percentage) ? NOT_COMPUTABLE : formatRelativeChange(change.change_percentage);
}

/** One table row, also used as the point detail. A real 0 stays "0"; a missing value is never 0. */
export function downloaderRow(bucket, granularity) {
    const { active, comparison } = bucket;

    return {
        key: String(bucket.index),
        activePeriod: formatDownloaderPeriod(active, granularity),
        provisional: active?.availability === 'partial' ? PROVISIONAL : '',
        downloader: isMissing(active?.value) ? UNAVAILABLE : formatCount(active.value),
        comparisonPeriod: comparison ? formatDownloaderPeriod(comparison, granularity) : COMPARISON_UNAVAILABLE,
        comparisonDownloader: isMissing(comparison?.value) ? COMPARISON_UNAVAILABLE : formatCount(comparison.value),
        change: formatDownloaderChange(bucket.change),
    };
}

export function completenessLabel(cutoffAt) {
    return `Batas lengkap: ${formatWibDateTime(cutoffAt)}`;
}

/** Accessible chart name, e.g. "Grafik Downloader per jam, 11 Nov 2026, sampai 01.00 WIB sampai …". */
export function downloaderChartLabel(buckets, granularity) {
    if (buckets.length === 0) return 'Grafik Downloader';

    const first = formatDownloaderPeriod(buckets[0].active, granularity);
    const last = formatDownloaderPeriod(buckets[buckets.length - 1].active, granularity);

    return `Grafik Downloader ${granularityLabel(granularity).toLowerCase()}, ${first} sampai ${last}`;
}

/**
 * X-axis label: the cutoff hour of a cumulative point ("14.00", or "5 Nov 14.00" over several days), the day
 * ("11 Nov") or the month ("Nov 2026").
 */
export function downloaderTick(bucket, granularity, spansDays = false) {
    const start = wib(bucket.active.start_at);

    if (granularity === 'hourly') {
        const cutoff = wib(bucket.active.as_of ?? bucket.active.end_at, { exclusiveEnd: true });

        return spansDays ? `${cutoff.day} ${cutoff.month} ${cutoff.time}` : cutoff.time;
    }
    if (granularity === 'monthly') return `${start.month} ${start.year}`;

    return `${start.day} ${start.month}`;
}

/** Dense lines hide their dots, except a point between two gaps, which would otherwise disappear. */
function pointRadii(data) {
    return data.map((_, index) => {
        const isolated = isMissing(data[index - 1]) && isMissing(data[index + 1]);

        return data.length <= DENSE_POINTS || isolated ? 2.5 : 0;
    });
}

/** Chart.js datasets in `series` order. A null stays null and spanGaps is off, so a missing point breaks the line. */
export function downloaderDatasets(buckets, series) {
    return series.map((item) => {
        const data = buckets.map((bucket) => sideValue(bucket, item.side));

        return {
            label: item.label,
            data,
            borderColor: item.color,
            backgroundColor: item.color,
            borderWidth: 2,
            borderDash: item.pattern === 'dashed' ? [6, 4] : [],
            spanGaps: false,
            tension: 0.25,
            pointRadius: pointRadii(data),
            pointHoverRadius: 4,
            pointBackgroundColor: item.color,
        };
    });
}

/** Fewer visible axis labels on narrow charts; called on every Chart.js resize. */
export function applyDownloaderTicks(chart, width) {
    const limits = tickLimits(width < COMPACT_WIDTH);
    const { x, y } = chart.options.scales;

    x.ticks.maxTicksLimit = limits.x;
    y.ticks.maxTicksLimit = limits.y;
}

/**
 * Chart.js config. The Chart.js legend and tooltip are off: the legend is HTML and the point detail is rendered
 * from downloaderRow() for hover and tap.
 */
export function downloaderChartConfig({ buckets, series, granularity, compact = false, onPoint = () => {}, onLeave = () => {} }) {
    const spansDays = buckets.length > 0
        && wib(buckets[0].active.start_at).date !== wib(buckets[buckets.length - 1].active.start_at).date;
    const pick = (event, chart) => {
        if (event.type === 'mouseout') return onLeave();

        const index = bucketIndexAt(chart, event, buckets.length);
        if (index !== null) onPoint(index);
    };

    return {
        type: 'line',
        data: {
            labels: buckets.map((bucket) => downloaderTick(bucket, granularity, spansDays)),
            datasets: downloaderDatasets(buckets, series),
        },
        plugins: [activeGuide],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            events: ['mousemove', 'mouseout', 'click', 'touchstart'],
            onHover: (event, _elements, chart) => pick(event, chart),
            onClick: (event, _elements, chart) => pick(event, chart),
            onResize: (chart, size) => applyDownloaderTicks(chart, size.width),
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
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Downloader', color: INK, font: { weight: '600' } },
                    grid: { color: GRID_COLOR },
                    border: { display: false },
                    ticks: { maxTicksLimit: tickLimits(compact).y, padding: 6, precision: 0, callback: (value) => formatAxis(value, 'number') },
                },
            },
        },
    };
}
