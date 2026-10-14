export const PERIOD_PRESETS = [
    { value: '7d', label: 'Last 7 Days' },
    { value: '30d', label: 'Last 30 Days' },
    { value: 'this_month', label: 'This Month' },
    { value: 'last_month', label: 'Last Month' },
    { value: 'custom', label: 'Custom Range' },
];

const DEFAULT_PRESET = 'this_month';

export function toDateString(date) {
    const pad = (number) => String(number).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function today() {
    return toDateString(new Date());
}

function daysAgo(days) {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return toDateString(date);
}

/**
 * Returns the { start_date, end_date } a preset covers. Custom keeps the dates the user picked.
 */
export function presetRange(preset, custom = {}) {
    const now = new Date();

    switch (preset) {
        case '7d':
            return { start_date: daysAgo(6), end_date: today() };
        case '30d':
            return { start_date: daysAgo(29), end_date: today() };
        case 'last_month':
            return {
                start_date: toDateString(new Date(now.getFullYear(), now.getMonth() - 1, 1)),
                end_date: toDateString(new Date(now.getFullYear(), now.getMonth(), 0)),
            };
        case 'custom':
            return { start_date: custom.start_date ?? '', end_date: custom.end_date ?? '' };
        default:
            return { start_date: toDateString(new Date(now.getFullYear(), now.getMonth(), 1)), end_date: today() };
    }
}

export function defaultFilter() {
    return { preset: DEFAULT_PRESET, platform: '', ...presetRange(DEFAULT_PRESET) };
}

/**
 * Reads the filter from the page URL so a filtered view can be shared or reloaded.
 */
export function filterFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const preset = PERIOD_PRESETS.some(({ value }) => value === params.get('preset')) ? params.get('preset') : DEFAULT_PRESET;

    return {
        preset,
        platform: params.get('platform') ?? '',
        ...presetRange(preset, { start_date: params.get('start_date'), end_date: params.get('end_date') }),
    };
}

export function syncUrl(filter) {
    const params = new URLSearchParams({ preset: filter.preset });

    if (filter.preset === 'custom') {
        params.set('start_date', filter.start_date);
        params.set('end_date', filter.end_date);
    }
    if (filter.platform) params.set('platform', filter.platform);

    window.history.replaceState(null, '', `${window.location.pathname}?${params}`);
}

/**
 * Query sent to the performance endpoints. An empty platform means all platforms.
 */
export function toQuery(filter) {
    const query = { date_from: filter.start_date, date_to: filter.end_date };
    if (filter.platform) query.platform = filter.platform;

    return query;
}

export function validateRange({ start_date, end_date }) {
    const errors = {};

    if (!start_date) errors.start_date = 'Tanggal mulai wajib diisi.';
    if (!end_date) errors.end_date = 'Tanggal akhir wajib diisi.';
    if (start_date && end_date && start_date > end_date) errors.end_date = 'Tanggal akhir tidak boleh sebelum tanggal mulai.';
    if (end_date && end_date > today()) errors.end_date = 'Tanggal akhir tidak boleh melewati hari ini.';

    return errors;
}
