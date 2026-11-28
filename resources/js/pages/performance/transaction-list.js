/**
 * Daftar Transaksi (FIX-13-FE-01): local list filters, the list query, and display text for rows and the detail.
 * Rows, statuses, dates and amounts come from `GET /api/performance/transactions` and `/{id}`
 * (docs/api/performance-transactions.md); this module only builds the query and maps values to text.
 *
 * Free of Alpine and fetch() so it can be tested in Node.
 */

import { UNAVAILABLE, formatCount, formatPaidAt, formatRupiah, formatWibDate, formatWibDateTime } from './formatters';

export const PER_PAGE = 25;

/** Status filter values in the backend contract; the labels match the row status labels. */
export const STATUS_OPTIONS = Object.freeze([
    { value: 'pending', label: 'Pending' },
    { value: 'success', label: 'Success' },
    { value: 'expired', label: 'Expired' },
    { value: 'canceled', label: 'Canceled' },
    { value: 'failed', label: 'Failed' },
    { value: 'unavailable', label: 'Status belum tersedia' },
]);

/** Pilihan cepat Tidak Berhasil. */
export const UNSUCCESSFUL = Object.freeze(['expired', 'canceled', 'failed']);

export const DATE_BASES = Object.freeze([
    { value: 'created', label: 'Tanggal Dibuat' },
    { value: 'paid', label: 'Tanggal Dibayar' },
]);
export const DEFAULT_DATE_BASIS = 'created';

export const PROMO_OPTIONS = Object.freeze([
    { value: '', label: 'Semua' },
    { value: 'with', label: 'Dengan Promo' },
    { value: 'without', label: 'Tanpa Promo' },
]);

/** Filter Lainnya lists: local filter key, query parameter, label, and the key in the options response. */
export const MORE_FILTERS = Object.freeze([
    { key: 'products', param: 'products[]', label: 'Produk' },
    { key: 'payment_methods', param: 'payment_methods[]', label: 'Metode Pembayaran' },
    { key: 'product_types', param: 'product_types[]', label: 'Tipe Produk' },
    { key: 'promo_codes', param: 'promo_codes[]', label: 'Kode Promo' },
]);

export const NO_VALUE = '—';
export const CONTACT_UNAVAILABLE = 'Tidak tersedia';
export const EMPTY_MESSAGE = 'Tidak ada transaksi yang cocok dengan filter daftar.';

/** Default local filters, also the result of Reset Filter Daftar. */
export function defaultLocalFilter() {
    return {
        search: '',
        statuses: [],
        date_basis: DEFAULT_DATE_BASIS,
        products: [],
        payment_methods: [],
        product_types: [],
        promo_codes: [],
        promo: '',
    };
}

/**
 * Query string of the list: the main filter query (period and platforms) followed by the local filters. Default
 * local values are left out, so a reset list sends only the main filter.
 */
export function listQuery(mainQuery, local, { page = 1, perPage = PER_PAGE } = {}) {
    const params = new URLSearchParams(mainQuery ?? '');
    const search = local.search.trim();

    if (local.date_basis && local.date_basis !== DEFAULT_DATE_BASIS) params.set('date_basis', local.date_basis);
    if (search) params.set('search', search);
    local.statuses.forEach((status) => params.append('statuses[]', status));
    MORE_FILTERS.forEach(({ key, param }) => local[key].forEach((value) => params.append(param, value)));
    if (local.promo) params.set('promo', local.promo);
    if (page > 1) params.set('page', String(page));
    if (perPage !== PER_PAGE) params.set('per_page', String(perPage));

    return params.toString();
}

/** Query of the Filter Lainnya options: the main filter and the date basis only. */
export function optionsQuery(mainQuery, local) {
    return listQuery(mainQuery, { ...defaultLocalFilter(), date_basis: local.date_basis });
}

/** Adds or removes one value of a list filter, keeping the order of first selection. */
export function toggleValue(values, value) {
    return values.includes(value) ? values.filter((item) => item !== value) : [...values, value];
}

export function isUnsuccessfulSelected(statuses) {
    return UNSUCCESSFUL.every((status) => statuses.includes(status));
}

/** Tidak Berhasil selects Expired, Canceled and Failed; when all three are already selected it clears them. */
export function toggleUnsuccessful(statuses) {
    if (isUnsuccessfulSelected(statuses)) return statuses.filter((status) => !UNSUCCESSFUL.includes(status));

    return [...statuses, ...UNSUCCESSFUL.filter((status) => !statuses.includes(status))];
}

/** Number of Filter Lainnya choices in use, for the button badge. */
export function moreFilterCount(local) {
    return MORE_FILTERS.reduce((total, { key }) => total + local[key].length, 0) + (local.promo ? 1 : 0);
}

/** Whether any local filter differs from its default. */
export function hasLocalFilters(local) {
    return local.search.trim() !== ''
        || local.statuses.length > 0
        || local.date_basis !== DEFAULT_DATE_BASIS
        || moreFilterCount(local) > 0;
}

/** Kode promo and Tanpa Promo cannot be combined (the backend answers 422), so one choice clears the other. */
export function withPromoChoice(local, promo) {
    return { ...local, promo, promo_codes: promo === 'without' ? [] : local.promo_codes };
}

export function withPromoCodes(local, codes) {
    return { ...local, promo_codes: codes, promo: codes.length > 0 && local.promo === 'without' ? '' : local.promo };
}

export function statusSummary(statuses) {
    if (statuses.length === 0) return 'Semua Status';
    if (statuses.length === UNSUCCESSFUL.length && isUnsuccessfulSelected(statuses)) return 'Tidak Berhasil';
    if (statuses.length <= 2) return statuses.map((value) => STATUS_OPTIONS.find((option) => option.value === value)?.label ?? value).join(', ');

    return `${statuses.length} status`;
}

/** Badge look of a report status code; the label text always carries the meaning. */
export function statusTone(code) {
    if (code === 1) return 'success';
    if (code === 0) return 'pending';
    if (code === 2 || code === 3 || code === 4) return 'unsuccessful';

    return 'unknown';
}

const TONE_CLASSES = Object.freeze({
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    unsuccessful: 'bg-red-50 text-red-700 ring-red-200',
    unknown: 'bg-soft text-label ring-line',
});

/** Badge colors of a status tone; the label text always carries the meaning. */
export function toneClass(tone) {
    return TONE_CLASSES[tone] ?? TONE_CLASSES.unknown;
}

/** Tanggal Dibayar: the last valid payment date (and time when present), or Belum tersedia. */
export function paidAt(paidDate, paidTime) {
    return paidDate ? formatPaidAt(paidDate, paidTime) : UNAVAILABLE;
}

const text = (value) => (value === null || value === undefined || value === '' ? NO_VALUE : String(value));

/** One table row in the column order of the ticket. */
export function transactionRow(item) {
    return {
        id: item.id,
        trxId: text(item.trx_id),
        createdAt: formatWibDateTime(item.created_at),
        paidAt: paidAt(item.paid_date, item.paid_time),
        platform: text(item.platform?.name),
        product: text(item.product),
        customer: text(item.customer_name),
        paymentMethod: text(item.payment_method),
        nominal: formatRupiah(item.nominal),
        status: item.status?.label ?? 'Status belum tersedia',
        statusTone: statusTone(item.status?.code ?? null),
        isClaim: item.is_claim_coupon === true,
        groupMembers: Number(item.group_member_count) || 0,
    };
}

/** Active period of the main filter the list used (`meta.filter`), e.g. "1 Nov 2026 – 30 Nov 2026". */
export function periodLabel(meta) {
    const { date_from: from, date_to: to } = meta?.filter ?? {};
    if (!from || !to) return '';

    return from === to ? formatWibDate(from) : `${formatWibDate(from)} – ${formatWibDate(to)}`;
}

/** "1–25 dari 120 transaksi", or '' without results. */
export function pageSummary(meta) {
    const total = Number(meta?.total) || 0;
    if (total === 0) return '';

    const from = (meta.current_page - 1) * meta.per_page + 1;
    const to = Math.min(meta.current_page * meta.per_page, total);

    return `${formatCount(from)}–${formatCount(to)} dari ${formatCount(total)} transaksi`;
}

const money = (value) => (value === null || value === undefined ? NO_VALUE : formatRupiah(value));
const contact = (value) => (value ? String(value) : CONTACT_UNAVAILABLE);

function person(item) {
    return {
        id: item.id ?? null,
        trxId: text(item.trx_id),
        name: text(item.customer?.name),
        email: contact(item.customer?.email),
        phone: contact(item.customer?.phone),
        status: item.status?.label ?? 'Status belum tersedia',
        statusTone: statusTone(item.status?.code ?? null),
        isClaim: item.is_claim_coupon === true,
    };
}

/**
 * Detail panel text. The five financial rows follow the ticket; a missing Kontribusi Omzet (not counted in Omzet,
 * e.g. Pending) is "—". Contact fields the account cannot see, or that are empty, read "Tidak tersedia".
 */
export function transactionDetail(data) {
    const financials = data.financials ?? {};
    const role = data.group?.role ?? null;

    return {
        id: data.id,
        trxId: text(data.trx_id),
        status: data.status?.label ?? 'Status belum tersedia',
        statusTone: statusTone(data.status?.code ?? null),
        isClaim: data.is_claim_coupon === true,
        info: [
            { label: 'Tanggal Dibuat', value: formatWibDateTime(data.created_at) },
            { label: 'Tanggal Dibayar', value: paidAt(data.paid_date, data.paid_time) },
            { label: 'Platform', value: text(data.platform?.name) },
            { label: 'Produk', value: text(data.product) },
            { label: 'Tipe Produk', value: text(data.product_type) },
            { label: 'Metode Pembayaran', value: text(data.payment_method) },
            { label: 'Kode Promo', value: data.promo_codes?.length ? data.promo_codes.join(', ') : NO_VALUE },
        ],
        customer: [
            { label: 'Nama', value: text(data.customer?.name) },
            { label: 'Email', value: contact(data.customer?.email) },
            { label: 'Telepon', value: contact(data.customer?.phone) },
        ],
        financials: [
            { key: 'product_price', label: 'Harga Paket', value: money(financials.product_price) },
            { key: 'voucher_discount', label: 'Potongan Voucher', value: money(financials.voucher_discount) },
            { key: 'admin_fee', label: 'Biaya Admin', value: money(financials.admin_fee) },
            { key: 'nominal', label: 'Nominal', value: money(financials.nominal) },
            { key: 'revenue_contribution', label: 'Kontribusi Omzet', value: money(financials.revenue_contribution) },
        ],
        group: {
            role,
            label: role === 'buyer' ? 'Pembeli paket grup' : role === 'member' ? 'Anggota paket grup' : '',
            buyer: role === 'member' && data.group.buyer ? person(data.group.buyer) : null,
            members: role === 'buyer' ? (data.group.members ?? []).map(person) : [],
        },
    };
}
