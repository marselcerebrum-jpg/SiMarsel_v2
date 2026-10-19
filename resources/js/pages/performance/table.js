import { pieColor } from './chart';
import { formatShare, formatValue, trendBadge } from './format';

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
 * Ranking rows for the platform contribution table, in the backend order (largest revenue first).
 * When total revenue is zero the share cannot be computed, so it shows as N/A with an empty bar.
 */
export function contributionRows(platforms, totalRevenue) {
    const hasRevenue = Number(totalRevenue) > 0;

    return platforms.map((platform, index) => {
        const share = hasRevenue ? Math.min(Math.max(Number(platform.contribution_percentage) || 0, 0), 100) : null;

        return {
            key: platform.slug,
            rank: index + 1,
            name: platform.name,
            revenue: formatValue(platform.revenue, 'currency'),
            transactions: formatValue(platform.transactions, 'number'),
            users: formatValue(platform.users, 'number'),
            width: `${share ?? 0}%`,
            contribution: formatShare(share),
        };
    });
}

/**
 * Rows for the payment method pie chart and its legend. Colors follow the backend order
 * (largest revenue first) so a method keeps its color when the parameter changes; rows are
 * then sorted by the selected parameter, largest first.
 */
export function paymentMethodRows(methods, { value, share }) {
    return methods
        .map((method, index) => ({
            key: method.name,
            name: method.name,
            color: pieColor(index),
            value: Number(method[value]) || 0,
            percentage: formatValue(method[share] ?? 0, 'percent'),
            transactions: formatValue(method.transactions, 'number'),
            revenue: formatValue(method.revenue, 'currency'),
        }))
        .sort((a, b) => b.value - a.value);
}
