import Chart from 'chart.js/auto';
import { formatAxis, formatValue } from './format';

const BRAND = {
    indigo: '#5d60a2',
    teal: '#5da29f',
    logo: '#3d4760',
    ink: '#1e293b',
    label: '#6b7f99',
    line: 'rgba(221, 228, 236, 0.9)',
    surface: '#f7f9fb',
};

const PIE_COLORS = ['#5d60a2', '#5da29f', '#5d83a2', '#3d4760', '#9a9cd0', '#94c9c6', '#97b4cc', '#7a849e'];

export function pieColor(index) {
    return PIE_COLORS[index % PIE_COLORS.length];
}

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

Chart.defaults.font.family = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.color = BRAND.label;

/**
 * Vertical gradient that follows the chart area, used for bar and area fills.
 */
function verticalGradient(context, top, bottom) {
    const { ctx, chartArea } = context.chart;
    if (!chartArea) return top;

    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    gradient.addColorStop(0, top);
    gradient.addColorStop(1, bottom);

    return gradient;
}

function dataset(type, metric, values) {
    const base = { label: metric.label, data: values };

    if (type === 'pie') {
        return {
            ...base,
            backgroundColor: values.map((_, index) => pieColor(index)),
            borderColor: BRAND.surface,
            borderWidth: 2,
            hoverOffset: 10,
        };
    }

    if (type === 'bar') {
        return {
            ...base,
            backgroundColor: (context) => verticalGradient(context, BRAND.indigo, BRAND.teal),
            hoverBackgroundColor: BRAND.logo,
            borderRadius: values.length > 60 ? 2 : 8,
            borderSkipped: false,
            maxBarThickness: 42,
        };
    }

    return {
        ...base,
        borderColor: BRAND.indigo,
        borderWidth: 2.5,
        tension: 0.35,
        fill: type === 'area' ? 'origin' : false,
        backgroundColor: type === 'area'
            ? (context) => verticalGradient(context, 'rgba(93, 96, 162, 0.35)', 'rgba(93, 162, 159, 0.02)')
            : BRAND.indigo,
        pointRadius: values.length > 31 ? 0 : 3,
        pointHoverRadius: 6,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: BRAND.indigo,
        pointBorderWidth: 2,
    };
}

/**
 * Chart.js config for one metric over time, built from the backend `rows`.
 * "area" is a filled line chart. A null value (conversion without downloaders)
 * leaves a gap in the chart and shows as "—" in the tooltip.
 */
export function chartConfig({ type, rows, metric }) {
    const isPie = type === 'pie';
    const values = rows.map((row) => row.value);

    return {
        type: type === 'area' ? 'line' : type,
        data: {
            labels: rows.map((row) => row.label),
            datasets: [dataset(type, metric, values)],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion.matches ? false : { duration: 700, easing: 'easeOutQuart' },
            interaction: isPie ? { mode: 'nearest', intersect: true } : { mode: 'index', intersect: false },
            layout: { padding: { top: 8 } },
            plugins: {
                legend: {
                    display: isPie && rows.length <= 12,
                    position: 'bottom',
                    labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 16, color: BRAND.ink },
                },
                tooltip: {
                    backgroundColor: '#1f2639',
                    padding: 12,
                    cornerRadius: 12,
                    titleColor: '#ffffff',
                    titleFont: { weight: '600' },
                    bodyColor: '#e2e8f0',
                    displayColors: isPie,
                    usePointStyle: true,
                    callbacks: {
                        title: (items) => rows[items[0].dataIndex].label,
                        label: (item) => ` ${metric.label}: ${formatValue(rows[item.dataIndex].value, metric.format)}`,
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
                        grid: { color: BRAND.line },
                        border: { display: false },
                        ticks: { maxTicksLimit: 8, padding: 8, callback: (value) => formatAxis(value, metric.format) },
                    },
                },
        },
    };
}

/**
 * Pie chart of revenue per platform. The tooltip shows the platform name with its
 * revenue, transactions, users, and contribution, all taken from the backend.
 */
export function contributionChartConfig(platforms) {
    return {
        type: 'pie',
        data: {
            labels: platforms.map(({ name }) => name),
            datasets: [{
                label: 'Revenue',
                data: platforms.map(({ revenue }) => revenue),
                backgroundColor: platforms.map((_, index) => pieColor(index)),
                borderColor: BRAND.surface,
                borderWidth: 2,
                hoverOffset: 12,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion.matches ? false : { duration: 700, easing: 'easeOutQuart' },
            layout: { padding: 8 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1f2639',
                    padding: 12,
                    cornerRadius: 12,
                    titleColor: '#ffffff',
                    titleFont: { weight: '600' },
                    bodyColor: '#e2e8f0',
                    usePointStyle: true,
                    boxPadding: 4,
                    callbacks: {
                        title: (items) => platforms[items[0].dataIndex].name,
                        label: (item) => {
                            const platform = platforms[item.dataIndex];

                            return [
                                ` Revenue: ${formatValue(platform.revenue, 'currency')}`,
                                ` Transactions: ${formatValue(platform.transactions, 'number')}`,
                                ` Users: ${formatValue(platform.users, 'number')}`,
                                ` Contribution: ${formatValue(platform.contribution_percentage, 'percent')}`,
                            ];
                        },
                    },
                },
            },
        },
    };
}

/**
 * Replaces the previous chart on the canvas. Chart.js cannot switch type in place.
 */
export function renderChart(canvas, config, previous) {
    previous?.destroy();

    return new Chart(canvas, config);
}
