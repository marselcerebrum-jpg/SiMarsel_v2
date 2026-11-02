import { http } from '../../http';

/**
 * Funnel and Source share one API shape (docs/api/promo-master-data.md).
 */
const MASTER_ENDPOINTS = {
    funnel: '/api/funnels',
    source: '/api/promo-sources',
};

function masterEndpoint(type) {
    const endpoint = MASTER_ENDPOINTS[type];
    if (!endpoint) throw new Error(`Unknown promo master type: ${type}`);

    return endpoint;
}

export function fetchMasters(type) {
    return http(masterEndpoint(type));
}

export function createMaster(type, name) {
    return http(masterEndpoint(type), { method: 'POST', body: { name } });
}

export function updateMaster(type, id, name) {
    return http(`${masterEndpoint(type)}/${id}`, { method: 'PUT', body: { name } });
}

export function deleteMaster(type, id) {
    return http(`${masterEndpoint(type)}/${id}`, { method: 'DELETE' });
}

/**
 * Read-only list from the global platform master.
 */
export function fetchPlatforms() {
    return http('/api/platforms');
}

/**
 * Promo code master (docs/api/promo-codes.md). The list is paginated: { data, meta }.
 */
const PROMO_CODES = '/api/promo-codes';

export function fetchPromoCodes(query) {
    return http(`${PROMO_CODES}${query ? `?${query}` : ''}`);
}

export function createPromoCode(body) {
    return http(PROMO_CODES, { method: 'POST', body });
}

/**
 * Valid submissions return { data: { summary, rows: [{ row, client_row_id, status, code, promo_code, errors }] } }.
 */
export function bulkCreatePromoCodes(rows) {
    return http(`${PROMO_CODES}/bulk`, { method: 'POST', body: { rows } });
}

export function updatePromoCode(id, body) {
    return http(`${PROMO_CODES}/${id}`, { method: 'PATCH', body });
}

export function deletePromoCode(id) {
    return http(`${PROMO_CODES}/${id}`, { method: 'DELETE' });
}
