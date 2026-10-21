import { http } from '../../http';

const BASE = '/api/performance';

/**
 * Every endpoint takes the filter query string built by toQueryString() in ./filter.
 */
function get(path, query, extra = {}) {
    const params = new URLSearchParams(query);
    Object.entries(extra).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') params.set(key, value);
    });

    const search = params.toString();

    return http(`${BASE}/${path}${search ? `?${search}` : ''}`);
}

export function fetchSummary(query) {
    return get('summary', query);
}

export function fetchTrend(query, { metric, period }) {
    return get('trend', query, { metric, period });
}

export function fetchPlatformContribution(query) {
    return get('platform-contribution', query);
}

export function fetchPaymentMethods(query) {
    return get('payment-methods', query);
}

export function fetchPurchaseTimes(query) {
    return get('purchase-times', query);
}

/**
 * Download link for the export (docs/api/performance-export.md). The browser follows it with the
 * session cookie, so no fetch() is needed.
 */
export function exportUrl(format, query) {
    return `${BASE}/export?format=${format}${query ? `&${query}` : ''}`;
}
