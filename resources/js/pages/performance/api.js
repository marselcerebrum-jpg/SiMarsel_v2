import { http } from '../../http';

const BASE = '/api/performance';

export function fetchSummary(params) {
    return http(`${BASE}/summary?${new URLSearchParams(params)}`);
}

export function fetchTrend(params) {
    return http(`${BASE}/trend?${new URLSearchParams(params)}`);
}
