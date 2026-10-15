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
