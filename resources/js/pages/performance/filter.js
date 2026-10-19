/**
 * Performance Overview filter (docs/api/performance-filter.md). The same query goes to every
 * performance endpoint and to the export link. Only one period mode is sent at a time.
 */
export const PERIOD_MODES = [
    { value: 'current_month', label: 'This Month' },
    { value: 'years', label: 'Year & Month' },
    { value: 'year_range', label: 'Year Range' },
    { value: 'date_range', label: 'Date Range' },
];

export const MAX_YEARS = 5;

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export function today() {
    const date = new Date();
    const pad = (number) => String(number).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function defaultFilter() {
    return { platforms: [], mode: 'current_month', years: [], month: '', year_from: '', year_to: '', date_from: '', date_to: '' };
}

/**
 * A month can only be picked when exactly one year is selected.
 */
export function canPickMonth(filter) {
    return filter.mode === 'years' && filter.years.length === 1;
}

/**
 * Query string for the performance endpoints, e.g. "platforms[]=a&years[]=2025&years[]=2026".
 * An empty string means the default: all platforms, current month.
 */
export function toQueryString(filter) {
    const params = new URLSearchParams();

    filter.platforms.forEach((slug) => params.append('platforms[]', slug));

    if (filter.mode === 'years' && filter.years.length) {
        if (canPickMonth(filter) && filter.month) {
            params.set('year', filter.years[0]);
            params.set('month', filter.month);
        } else {
            [...filter.years].sort().forEach((year) => params.append('years[]', year));
        }
    }
    if (filter.mode === 'year_range') {
        params.set('year_from', filter.year_from);
        params.set('year_to', filter.year_to);
    }
    if (filter.mode === 'date_range') {
        params.set('date_from', filter.date_from);
        params.set('date_to', filter.date_to);
    }

    return params.toString();
}

/**
 * Reads the filter back from the page URL, which uses the same query as the API.
 */
export function filterFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const filter = { ...defaultFilter(), platforms: params.getAll('platforms[]') };

    if (params.get('date_from') || params.get('date_to')) {
        return { ...filter, mode: 'date_range', date_from: params.get('date_from') ?? '', date_to: params.get('date_to') ?? '' };
    }
    if (params.get('year_from') || params.get('year_to')) {
        return { ...filter, mode: 'year_range', year_from: params.get('year_from') ?? '', year_to: params.get('year_to') ?? '' };
    }
    if (params.get('year')) {
        return { ...filter, mode: 'years', years: [Number(params.get('year'))], month: params.get('month') ?? '' };
    }
    if (params.getAll('years[]').length) {
        return { ...filter, mode: 'years', years: params.getAll('years[]').map(Number).slice(0, MAX_YEARS) };
    }

    return filter;
}

export function syncUrl(query) {
    window.history.replaceState(null, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
}

/**
 * Checks the period before a request, so an incomplete choice does not reach the API.
 * The backend validates again and its 422 messages are shown the same way.
 */
export function validateFilter(filter) {
    const errors = {};

    if (filter.mode === 'years' && filter.years.length === 0) errors.years = 'Pilih minimal satu tahun.';

    if (filter.mode === 'year_range') {
        if (!filter.year_from) errors.year_from = 'Tahun mulai wajib diisi.';
        if (!filter.year_to) errors.year_to = 'Tahun akhir wajib diisi.';
        if (filter.year_from && filter.year_to && Number(filter.year_to) < Number(filter.year_from)) {
            errors.year_to = 'Tahun akhir tidak boleh sebelum tahun mulai.';
        }
    }

    if (filter.mode === 'date_range') {
        if (!filter.date_from) errors.date_from = 'Tanggal mulai wajib diisi.';
        if (!filter.date_to) errors.date_to = 'Tanggal akhir wajib diisi.';
        if (filter.date_from && filter.date_to && filter.date_from > filter.date_to) {
            errors.date_to = 'Tanggal akhir tidak boleh sebelum tanggal mulai.';
        }
    }

    return errors;
}

/**
 * Maps backend 422 keys (years.0, platforms.1, year, ...) to the filter fields.
 */
export function filterErrorField(field) {
    const base = field.split('.')[0];

    if (base === 'platform') return 'platforms';
    if (base === 'year') return 'years';

    return base;
}
