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
