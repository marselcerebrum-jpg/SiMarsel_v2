/**
 * Global Dashboard filter (FIX-04-FE-01): platforms, period preset and comparison. The same query goes to every
 * Dashboard endpoint and to the export link. The period resolver (backend) turns it into the active range,
 * the comparison range and the completeness boundary; the browser never computes them.
 *
 * Query contract (shared period resolver, PeriodResolverService):
 * - `platforms[]`                      platform slugs; none means every platform
 * - `preset`                           one of PRESETS; always sent, since a request without it uses the old filter
 * - `date_from`, `date_to`             YYYY-MM-DD, only with `preset=date_range`
 * - `comparison`                       one of COMPARISONS; omitted means `auto`
 * - `comparison_from`, `comparison_to` YYYY-MM-DD, only with `comparison=custom`
 */

export const DEFAULT_PRESET = 'today';
export const DEFAULT_COMPARISON = 'auto';
export const DATE_RANGE_PRESET = 'date_range';
export const CUSTOM_COMPARISON = 'custom';

export const PRESETS = Object.freeze([
    { value: 'today', label: 'Hari Ini' },
    { value: 'yesterday', label: 'Kemarin' },
    { value: 'last_7_days', label: '7 Hari Terakhir' },
    { value: 'last_30_days', label: '30 Hari Terakhir' },
    { value: 'last_90_days', label: '90 Hari Terakhir' },
    { value: 'this_month', label: 'Bulan Ini' },
    { value: 'last_month', label: 'Bulan Lalu' },
    { value: DATE_RANGE_PRESET, label: 'Rentang Tanggal' },
    // Business rule: the current year from 1 January up to the completeness boundary.
    { value: 'last_year', label: 'Tahun Lalu' },
]);

export const COMPARISONS = Object.freeze([
    { value: DEFAULT_COMPARISON, label: 'Otomatis' },
    { value: 'previous_period', label: 'Periode Sebelumnya' },
    { value: 'same_period_last_month', label: 'Periode Sama Bulan Lalu' },
    { value: 'same_period_last_year', label: 'Periode Sama Tahun Lalu' },
    { value: CUSTOM_COMPARISON, label: 'Rentang Pembanding Khusus' },
]);

export const MESSAGES = Object.freeze({
    startRequired: 'Tanggal mulai wajib diisi.',
    endRequired: 'Tanggal akhir wajib diisi.',
    endBeforeStart: 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
});

const isPreset = (value) => PRESETS.some((preset) => preset.value === value);
const isComparison = (value) => COMPARISONS.some((comparison) => comparison.value === value);

export function presetLabel(value) {
    return PRESETS.find((preset) => preset.value === value)?.label ?? '';
}

export function comparisonLabel(value) {
    return COMPARISONS.find((comparison) => comparison.value === value)?.label ?? '';
}

export function defaultFilter() {
    return {
        platforms: [],
        preset: DEFAULT_PRESET,
        date_from: '',
        date_to: '',
        comparison: DEFAULT_COMPARISON,
        comparison_from: '',
        comparison_to: '',
    };
}

/**
 * Query string for the Dashboard endpoints and the page URL, e.g. "preset=today" for the default Dashboard
 * (every platform, Hari Ini, Otomatis). Otomatis is the backend default, so it is left out.
 */
export function toQueryString(filter) {
    const params = new URLSearchParams();

    filter.platforms.forEach((slug) => params.append('platforms[]', slug));

    params.set('preset', filter.preset);
    if (filter.preset === DATE_RANGE_PRESET) {
        params.set('date_from', filter.date_from);
        params.set('date_to', filter.date_to);
    }

    if (filter.comparison !== DEFAULT_COMPARISON) params.set('comparison', filter.comparison);
    if (filter.comparison === CUSTOM_COMPARISON) {
        params.set('comparison_from', filter.comparison_from);
        params.set('comparison_to', filter.comparison_to);
    }

    return params.toString();
}

/**
 * Reads the filter back from a page URL query. Older links stay usable: `platform` and `platforms[]` keep
 * their platforms, a `date_from`/`date_to` pair without a preset opens Rentang Tanggal, and the removed
 * year and month modes (`year`, `month`, `years[]`, `year_from`, `year_to`) fall back to the default period.
 */
export function filterFromUrl(search = '') {
    const params = new URLSearchParams(search);
    const filter = defaultFilter();

    filter.platforms = [...new Set([...params.getAll('platforms[]'), ...params.getAll('platform')].filter(Boolean))];

    const preset = params.get('preset');
    const legacyRange = preset === null && (params.get('date_from') || params.get('date_to'));

    if (isPreset(preset)) filter.preset = preset;
    if (legacyRange) filter.preset = DATE_RANGE_PRESET;
    if (filter.preset === DATE_RANGE_PRESET) {
        filter.date_from = params.get('date_from') ?? '';
        filter.date_to = params.get('date_to') ?? '';
    }

    const comparison = params.get('comparison');
    if (isComparison(comparison)) filter.comparison = comparison;
    if (filter.comparison === CUSTOM_COMPARISON) {
        filter.comparison_from = params.get('comparison_from') ?? '';
        filter.comparison_to = params.get('comparison_to') ?? '';
    }

    return filter;
}

export function syncUrl(query) {
    window.history.replaceState(null, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
}

function validateRange(errors, from, to, [fromField, toField]) {
    if (!from) errors[fromField] = MESSAGES.startRequired;
    if (!to) errors[toField] = MESSAGES.endRequired;
    if (from && to && to < from) errors[toField] = MESSAGES.endBeforeStart;
}

/**
 * Checks both custom ranges before a request. The message sits on the end field and nothing is cleared.
 * The backend validates again and its 422 messages are shown the same way.
 */
export function validateFilter(filter) {
    const errors = {};

    if (filter.preset === DATE_RANGE_PRESET) {
        validateRange(errors, filter.date_from, filter.date_to, ['date_from', 'date_to']);
    }
    if (filter.comparison === CUSTOM_COMPARISON) {
        validateRange(errors, filter.comparison_from, filter.comparison_to, ['comparison_from', 'comparison_to']);
    }

    return errors;
}

/**
 * Maps backend 422 keys (platforms.1, platform, ...) to the filter fields.
 */
export function filterErrorField(field) {
    const base = field.split('.')[0];

    return base === 'platform' ? 'platforms' : base;
}

/**
 * Marks the latest request: `const isCurrent = track();` then drop the response when `isCurrent()` is false,
 * so a slow response for an older filter never replaces the newer one.
 */
export function createRequestTracker() {
    let latest = 0;

    return () => {
        const id = ++latest;

        return () => id === latest;
    };
}
