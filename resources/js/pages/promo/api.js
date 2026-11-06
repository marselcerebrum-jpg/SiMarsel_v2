import { http, httpBlob } from '../../http';

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

/**
 * Codes used in Markaz transactions that have no master for their platform yet; same query as the list.
 */
export function fetchUnregisteredCodes(query) {
    return http(`${PROMO_CODES}/unregistered${query ? `?${query}` : ''}`);
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

/**
 * Promo analysis (docs/api/promo-analysis.md). Every analysis endpoint takes the same filter query.
 */
const PROMO_ANALYSIS = '/api/promo-analysis';

export function fetchAnalysisFilters() {
    return http(`${PROMO_ANALYSIS}/filters`);
}

export function fetchAnalysisSummary(query) {
    return http(`${PROMO_ANALYSIS}/summary${query ? `?${query}` : ''}`);
}

export function fetchAnalysisProductTypes(query) {
    return http(`${PROMO_ANALYSIS}/product-types${query ? `?${query}` : ''}`);
}

export function fetchAnalysisFunnels(query) {
    return http(`${PROMO_ANALYSIS}/funnels${query ? `?${query}` : ''}`);
}

export function fetchAnalysisSources(query) {
    return http(`${PROMO_ANALYSIS}/sources${query ? `?${query}` : ''}`);
}

/**
 * Takes the shared filter query plus sort, direction, page, and per_page.
 */
export function fetchAnalysisCodes(query) {
    return http(`${PROMO_ANALYSIS}/codes${query ? `?${query}` : ''}`);
}

/**
 * Takes the shared filter query plus the clicked row's detail (platform_id, code, group) and page.
 */
export function fetchAnalysisCodeUsages(query) {
    return http(`${PROMO_ANALYSIS}/codes/usages${query ? `?${query}` : ''}`);
}

/**
 * Takes the shared filter query plus period (daily, weekly, or monthly).
 */
export function fetchAnalysisTrend(query) {
    return http(`${PROMO_ANALYSIS}/trend${query ? `?${query}` : ''}`);
}

export function fetchAnalysisTopCodes(query) {
    return http(`${PROMO_ANALYSIS}/top-codes${query ? `?${query}` : ''}`);
}

/**
 * Excel file of every analysis component, complete (no pagination). Takes the shared filter query plus
 * the trend period and the code summary sort, so the file matches the page. Resolves { blob, fileName }.
 */
export function exportAnalysis(query) {
    return httpBlob(`${PROMO_ANALYSIS}/export${query ? `?${query}` : ''}`);
}
