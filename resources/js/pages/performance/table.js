import { pieColor } from './chart';
import { formatValue, trendBadge } from './format';

/**
 * Rows for the period comparison table, in the same order as the KPI cards.
 */
export function comparisonRows(definitions, metrics) {
    return definitions.map(({ key, label, format }) => {
        const metric = metrics?.[key] ?? null;

        return {
            key,
            label,
            current: formatValue(metric?.value, format),
            previous: formatValue(metric?.previous_value, format),
            trend: trendBadge(metric),
        };
    });
}

/**
 * Rows for the trend table, one per backend row. The change column uses the
 * backend's change_percentage and trend (null for the first row).
 */
export function trendRows(rows, format) {
    return rows.map((row) => ({
        key: row.period_start,
        period: row.label,
        value: formatValue(row.value, format),
        trend: trendBadge(row),
    }));
}

/**
 * Rows for the platform contribution table, in the backend order (largest revenue first).
 * The color matches the platform's slice in the pie chart.
 */
export function contributionRows(platforms) {
    return platforms.map((platform, index) => ({
        key: platform.slug,
        name: platform.name,
        color: pieColor(index),
        revenue: formatValue(platform.revenue, 'currency'),
        transactions: formatValue(platform.transactions, 'number'),
        users: formatValue(platform.users, 'number'),
        share: Math.min(Math.max(Number(platform.contribution_percentage) || 0, 0), 100),
        contribution: formatValue(platform.contribution_percentage, 'percent'),
    }));
}

export function contributionTotals(totals) {
    return {
        revenue: formatValue(totals?.revenue, 'currency'),
        transactions: formatValue(totals?.transactions, 'number'),
        users: formatValue(totals?.users, 'number'),
    };
}
