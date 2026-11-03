/**
 * Promo analysis filter (docs/api/promo-analysis.md#filter). The same query goes to every analysis
 * component. Only one period mode is sent at a time; payment dates follow Markaz paid_date.
 */
export const PERIOD_MODES = [
    { value: 'date_range', label: 'Date Range' },
    { value: 'month', label: 'Month & Year' },
];

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export const MIN_YEAR = 2000;

export const SEARCH_MAX_LENGTH = 100;

const pad = (number) => String(number).padStart(2, '0');

export function toDateString(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Years the API accepts, newest first.
 */
export function yearOptions(now = new Date()) {
    const years = [];
    for (let year = now.getFullYear(); year >= MIN_YEAR; year--) years.push(year);

    return years;
}

/**
 * Default and Reset state: all platforms and payment methods, no search, the current month.
 */
export function defaultFilter(now = new Date()) {
    return {
        platforms: [],
        payment_methods: [],
        mode: 'month',
        year: String(now.getFullYear()),
        month: String(now.getMonth() + 1),
        date_from: '',
        date_to: '',
        search: '',
    };
}

/**
 * The same filter switched to another period mode, starting on a valid period so data shows right away.
 */
export function withMode(filter, mode, now = new Date()) {
    const { platforms, payment_methods, search } = filter;
    const next = { ...defaultFilter(now), platforms, payment_methods, search, mode };

    if (mode === 'date_range') {
        next.date_from = toDateString(new Date(now.getFullYear(), now.getMonth(), 1));
        next.date_to = toDateString(now);
    }

    return next;
}

/**
 * Months after the current one cannot have paid transactions yet.
 */
export function isFutureMonth(year, month, now = new Date()) {
    return Number(year) > now.getFullYear()
        || (Number(year) === now.getFullYear() && Number(month) > now.getMonth() + 1);
}

/**
 * Checks the filter before a request, so an incomplete choice does not reach the API.
 * The backend validates again and its 422 messages are shown the same way.
 */
export function validateFilter(filter) {
    const errors = {};

    if (filter.mode === 'month') {
        if (!filter.year) errors.year = 'Tahun wajib dipilih.';
        if (!filter.month) errors.month = 'Bulan wajib dipilih.';
    }

    if (filter.mode === 'date_range') {
        if (!filter.date_from) errors.date_from = 'Tanggal mulai wajib diisi.';
        if (!filter.date_to) errors.date_to = 'Tanggal akhir wajib diisi.';
        if (filter.date_from && filter.date_to && filter.date_from > filter.date_to) {
            errors.date_to = 'Tanggal akhir tidak boleh sebelum tanggal mulai.';
        }
    }

    if (filter.search.trim().length > SEARCH_MAX_LENGTH) {
        errors.search = `Pencarian kode promo maksimal ${SEARCH_MAX_LENGTH} karakter.`;
    }

    return errors;
}

export function isCurrentMonth(filter, now = new Date()) {
    return filter.mode === 'month'
        && Number(filter.year) === now.getFullYear()
        && Number(filter.month) === now.getMonth() + 1;
}

/**
 * Query string for the promo analysis endpoints, e.g. "platforms[]=jadiasn&year=2026&month=9".
 * The current month sends no period, the API default: the 1st of this month until today,
 * compared with the whole previous month.
 */
export function toQueryString(filter, now = new Date()) {
    const params = new URLSearchParams();

    filter.platforms.forEach((slug) => params.append('platforms[]', slug));
    filter.payment_methods.forEach((method) => params.append('payment_methods[]', method));

    if (filter.mode === 'month' && !isCurrentMonth(filter, now)) {
        params.set('year', filter.year);
        params.set('month', filter.month);
    }
    if (filter.mode === 'date_range') {
        params.set('date_from', filter.date_from);
        params.set('date_to', filter.date_to);
    }

    const search = filter.search.trim();
    if (search) params.set('search', search);

    return params.toString();
}

/**
 * Maps backend 422 keys (platforms.0, payment_methods.1, filter, ...) to the filter fields.
 */
export function filterErrorField(field) {
    const base = field.split('.')[0];

    return base === 'platform' ? 'platforms' : base;
}

/**
 * Button text for a multi-select: "All …", up to two names, or a count.
 */
export function selectionSummary(options, selected, allLabel, countLabel) {
    const names = options.filter(({ value }) => selected.includes(value)).map(({ label }) => label);
    if (names.length === 0) return allLabel;

    return names.length <= 2 ? names.join(', ') : `${names.length} ${countLabel}`;
}
