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

