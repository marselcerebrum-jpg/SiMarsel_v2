import Chart from 'chart.js/auto';
import { formatAxis, formatValue } from '../../format';

const COLORS = {
    primary: '#059669',
    primarySoft: '#a7f3d0',
    navy: '#0f2340',
    ink: '#1e293b',
    label: '#6b7685',
    line: '#e3e6ea',
};

/**
 * Series shown on the promo trend chart, by backend dataset key. Promo transactions are counted on the
 * right axis; promo revenue and the total sales overlay share the currency axis so they can be compared.
 */
export const TREND_SERIES = [
    { key: 'promo_transactions', format: 'number', type: 'bar', axis: 'count', color: COLORS.primarySoft },
    { key: 'promo_revenue', format: 'currency', type: 'line', axis: 'revenue', color: COLORS.primary },
    { key: 'total_sales', format: 'currency', type: 'line', axis: 'revenue', color: COLORS.navy, dashed: true },
];

Chart.defaults.font.family = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.color = COLORS.label;

/**
 * The backend datasets that the chart shows, in TREND_SERIES order, each with its display settings.
 */
export function trendSeries(datasets) {
    return TREND_SERIES
        .map((series) => {
            const dataset = datasets.find(({ key }) => key === series.key);

            return dataset ? { ...series, label: dataset.label, data: dataset.data } : null;
        })
        .filter(Boolean);
}

function dataset(series, points) {
    const base = { label: series.label, data: series.data, yAxisID: series.axis, order: series.type === 'bar' ? 2 : 1 };

    if (series.type === 'bar') {
        return { ...base, type: 'bar', backgroundColor: series.color, borderRadius: points > 60 ? 1 : 4, maxBarThickness: 36 };
    }

    return {
        ...base,
        type: 'line',
        borderColor: series.color,
        backgroundColor: series.color,
        borderWidth: 2,
        borderDash: series.dashed ? [6, 4] : [],
        tension: 0.3,
        pointRadius: points > 31 ? 0 : 2.5,
        pointHoverRadius: 5,
    };
}

/**
 * Chart.js config for GET /api/promo-analysis/trend. A null value (a bucket after today) leaves a gap and shows as "—".
 */
export function trendChartConfig({ labels, datasets }) {
    const series = trendSeries(datasets);

    return {
        type: 'bar',
        data: {
            labels,
            datasets: series.map((item) => dataset(item, labels.length)),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { top: 8 } },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        padding: 16,
                        color: COLORS.ink,
                        // Bars are drawn behind the lines (dataset order); keep the legend in series order.
                        sort: (a, b) => a.datasetIndex - b.datasetIndex,
                    },
                },
                tooltip: {
                    backgroundColor: COLORS.navy,
                    padding: 10,
                    cornerRadius: 8,
                    titleColor: '#ffffff',
                    titleFont: { weight: '600' },
                    bodyColor: '#e2e8f0',
                    usePointStyle: true,
                    itemSort: (a, b) => a.datasetIndex - b.datasetIndex,
                    callbacks: {
                        label: (item) => ` ${item.dataset.label}: ${formatValue(item.raw, series[item.datasetIndex].format)}`,
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 16 },
                },
                revenue: {
                    position: 'left',
                    beginAtZero: true,
                    grid: { color: COLORS.line },
                    border: { display: false },
                    ticks: { maxTicksLimit: 8, padding: 8, callback: (value) => formatAxis(value, 'currency') },
                },
                count: {
                    position: 'right',
                    beginAtZero: true,
                    grid: { display: false },
                    border: { display: false },
                    ticks: { maxTicksLimit: 8, padding: 8, precision: 0, callback: (value) => formatAxis(value, 'number') },
                },
            },
        },
    };
}

/**
 * Replaces the previous chart on the canvas.
 */
export function renderChart(canvas, config, previous) {
    previous?.destroy();

    return new Chart(canvas, config);
}
