export const PER_PAGE = 15;

const SEARCH_MAX_LENGTH = 100;

export const defaultFilter = () => ({ search: '', platform_id: '', page: 1 });

/**
 * Query string for GET /api/promo-codes.
 */
export function toQueryString(filter) {
    const params = new URLSearchParams({ page: filter.page, per_page: PER_PAGE });

    const search = filter.search.trim().slice(0, SEARCH_MAX_LENGTH);
    if (search) params.set('search', search);
    if (filter.platform_id) params.set('platform_id', filter.platform_id);

    return params.toString();
}

/**
 * Prefill for Single Entry when the page is opened from an "unregistered code" alert
 * (?tab=promo-codes&code=...&platform_id=...). The keys are removed so a reload does not reopen the form.
 */
export function takePrefillFromUrl() {
    const url = new URL(window.location.href);
    const code = url.searchParams.get('code')?.trim() ?? '';
    const platformId = url.searchParams.get('platform_id') ?? '';

    if (!code && !platformId) return null;

    url.searchParams.delete('code');
    url.searchParams.delete('platform_id');
    window.history.replaceState(null, '', url);

    return { code, platform_id: /^\d+$/.test(platformId) ? platformId : '' };
}
