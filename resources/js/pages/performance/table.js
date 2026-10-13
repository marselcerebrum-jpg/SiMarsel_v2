import { formatPeriod, formatValue, trendBadge } from './format';

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
 * Rows for the trend table: one row per period bucket returned by the backend.
 */
export function trendRows(points, format, granularity) {
    return points.map((point) => ({
        key: point.period_start,
        period: formatPeriod(point, granularity, { long: true }),
        value: formatValue(point.value, format),
        trend: trendBadge(point),
    }));
}
