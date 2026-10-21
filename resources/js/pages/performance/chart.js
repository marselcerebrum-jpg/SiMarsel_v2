import Chart from 'chart.js/auto';
import { formatAxis, formatValue } from './format';

const COLORS = {
    primary: '#059669',
    primaryHover: '#047857',
    navy: '#0f2340',
    ink: '#1e293b',
    label: '#6b7685',
    line: '#e3e6ea',
    surface: '#ffffff',
};

const PIE_COLORS = ['#059669', '#0f2340', '#34d399', '#3b5b8c', '#f59e0b', '#94a3b8', '#065f46', '#7dd3fc'];

// One colour per compared year (at most five).
const SERIES_COLORS = ['#059669', '#0f2340', '#f59e0b', '#3b82f6', '#94a3b8'];

export function pieColor(index) {
    return PIE_COLORS[index % PIE_COLORS.length];
}

const TOOLTIP = {
    backgroundColor: COLORS.navy,
    padding: 10,
    cornerRadius: 8,
    titleColor: '#ffffff',
    titleFont: { weight: '600' },
    bodyColor: '#e2e8f0',
    usePointStyle: true,
};

Chart.defaults.font.family = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.color = COLORS.label;

function dataset(type, { label, data }, index, single) {
    const color = single ? COLORS.primary : SERIES_COLORS[index % SERIES_COLORS.length];
    const base = { label, data };

    if (type === 'pie') {
        return {
            ...base,
            backgroundColor: data.map((_, slice) => pieColor(slice)),
            borderColor: COLORS.surface,
            borderWidth: 2,
        };
    }

    if (type === 'bar') {
        return {
            ...base,
            backgroundColor: color,
            hoverBackgroundColor: single ? COLORS.primaryHover : color,
            borderRadius: data.length > 60 ? 1 : 4,
            maxBarThickness: 36,
        };
    }

    return {
        ...base,
        borderColor: color,
        borderWidth: 2,
        tension: 0.3,
        fill: type === 'area' ? 'origin' : false,
        backgroundColor: type === 'area' ? `${color}26` : color,
        pointRadius: data.length > 31 ? 0 : 2.5,
        pointHoverRadius: 5,
        pointBackgroundColor: color,
    };
}

/**
 * Chart.js config for one metric over time, built from the backend `labels` and `datasets`.
 * Several datasets (one per compared year) get a legend; "area" is a filled line chart.
 * A null value (conversion without downloaders, or a period after today) leaves a gap and shows as "—".
 */
export function chartConfig({ type, labels, datasets, metric }) {
    const isPie = type === 'pie';
    const single = datasets.length === 1;

    return {
        type: type === 'area' ? 'line' : type,
        data: {
            labels,
            datasets: datasets.map((set, index) => dataset(type, set, index, single)),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: isPie ? { mode: 'nearest', intersect: true } : { mode: 'index', intersect: false },
            layout: { padding: { top: 8 } },
            plugins: {
                legend: {
                    display: (isPie && labels.length <= 12) || !single,
                    position: 'bottom',
                    labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 16, color: COLORS.ink },
                },
                tooltip: {
                    ...TOOLTIP,
                    displayColors: isPie || !single,
                    callbacks: {
                        title: (items) => labels[items[0].dataIndex],
                        label: (item) => ` ${single ? metric.label : item.dataset.label}: ${formatValue(item.raw, metric.format)}`,
                    },
                },
            },
            scales: isPie
                ? {}
                : {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 16 },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: COLORS.line },
                        border: { display: false },
                        ticks: { maxTicksLimit: 8, padding: 8, callback: (value) => formatAxis(value, metric.format) },
                    },
                },
        },
    };
}

const paymentTooltip = {
    title: (row) => row.name,
    label: (row) => `Contribution: ${row.percentage}`,
    details: (row) => [`Transactions: ${row.transactions}`, `Revenue: ${row.revenue}`],
};

/**
 * Pie chart for a distribution such as payment methods or purchase times. Rows bring their own
 * color so the HTML legend beside the chart matches the slices; hovering a slice shows the
 * detail lines from `tooltip` (defaults to the payment method detail).
 */
export function distributionChartConfig({ rows, metric, tooltip = paymentTooltip }) {
    return {
        type: 'pie',
        data: {
            labels: rows.map((row) => row.name),
            datasets: [{
                label: metric.label,
                data: rows.map((row) => row.value),
                backgroundColor: rows.map((row) => row.color),
                borderColor: COLORS.surface,
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            layout: { padding: 12 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP,
                    callbacks: {
                        title: (items) => tooltip.title(rows[items[0].dataIndex]),
                        label: (item) => ` ${tooltip.label(rows[item.dataIndex])}`,
                        afterLabel: (item) => tooltip.details(rows[item.dataIndex]).map((line) => `   ${line}`),
                    },
                },
            },
        },
    };
}

/**
 * Highlights one slice and opens its tooltip, e.g. while the matching legend row is hovered.
 * A null index clears the highlight.
 */
export function highlightSlice(chart, index) {
    if (!chart) return;

    const active = index === null ? [] : [{ datasetIndex: 0, index }];
    const arc = index === null ? null : chart.getDatasetMeta(0).data[index];

    chart.setActiveElements(active);
    chart.tooltip.setActiveElements(active, arc ? arc.tooltipPosition() : { x: 0, y: 0 });
    chart.update();
}

/**
 * Replaces the previous chart on the canvas. Chart.js cannot switch type in place.
 */
export function renderChart(canvas, config, previous) {
    previous?.destroy();

    return new Chart(canvas, config);
}
