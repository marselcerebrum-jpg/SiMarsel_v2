import { http } from '../../http';

const BASE = '/api/performance';

export function fetchSummary(params) {
    return http(`${BASE}/summary?${new URLSearchParams(params)}`);
}

export function fetchTrend(params) {
    return http(`${BASE}/trend?${new URLSearchParams(params)}`);
}

export function fetchPlatformContribution(params) {
    return http(`${BASE}/platform-contribution?${new URLSearchParams(params)}`);
}

/**
 * Set to true once the payment method integration with the backend is done. While false, the
 * Payment Methods card shows "Data belum tersedia" without calling the API or showing sample data.
 * API contract: docs/api/payment-methods.md
 */
export const PAYMENT_METHOD_API_READY = false;

export function fetchPaymentMethods(params) {
    if (!PAYMENT_METHOD_API_READY) return Promise.reject({ unavailable: true });

    return http(`${BASE}/payment-methods?${new URLSearchParams(params)}`);
}
