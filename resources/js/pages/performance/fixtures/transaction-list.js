/**
 * Daftar Transaksi responses in the backend contract (docs/api/performance-transactions.md). Values are test data
 * following the FIX-13 scenarios:
 * - 812: Success, paid 12 Nov 10.05; Harga Paket 200.000, voucher 20.000, admin 3.000, Nominal 183.000, Kontribusi
 *   Omzet 180.000. Group buyer with members 813 and 814 (members are not list rows).
 * - 820: Pending with the same amounts; Kontribusi Omzet not available. No phone.
 * - 830: raw status Pending, report status Expired; never had a valid payment date (Belum tersedia).
 * - 840: coupon claim, its own row.
 */

const FILTER = { mode: 'date_range', date_from: '2026-11-01', date_to: '2026-11-30', platforms: ['jadiasn'] };
const PLATFORM = { slug: 'jadiasn', name: 'JadiASN' };
const PRODUCT = 'Bimbel SKD CPNS 2026 - Masa Aktif 1 Tahun';

export const transactionRows = [
    { id: 812, trx_id: 'jadiasn-476770', created_at: '2026-11-12T10:00:00+07:00', paid_date: '2026-11-12', paid_time: '10:05:00', platform: PLATFORM, product: PRODUCT, customer_name: 'Rina', payment_method: 'QRIS', nominal: 183000, status: { code: 1, label: 'Success' }, is_claim_coupon: false, group_member_count: 2 },
    { id: 820, trx_id: 'jadiasn-476790', created_at: '2026-11-11T09:00:00+07:00', paid_date: null, paid_time: null, platform: PLATFORM, product: PRODUCT, customer_name: 'Budi', payment_method: 'QRIS', nominal: 183000, status: { code: 0, label: 'Pending' }, is_claim_coupon: false, group_member_count: 0 },
    { id: 830, trx_id: 'jadiasn-476800', created_at: '2026-11-10T08:00:00+07:00', paid_date: null, paid_time: null, platform: PLATFORM, product: PRODUCT, customer_name: 'Sari', payment_method: 'BCA', nominal: 150000, status: { code: 2, label: 'Expired' }, is_claim_coupon: false, group_member_count: 0 },
    { id: 840, trx_id: 'jadiasn-476810', created_at: '2026-11-09T07:00:00+07:00', paid_date: '2026-11-09', paid_time: null, platform: PLATFORM, product: PRODUCT, customer_name: 'Andi', payment_method: null, nominal: 0, status: { code: 1, label: 'Success' }, is_claim_coupon: true, group_member_count: 0 },
];

export function transactionListResponse({ rows = transactionRows, page = 1, perPage = 25, total = rows.length, dateBasis = 'created' } = {}) {
    return {
        data: rows,
        meta: { current_page: page, per_page: perPage, total, last_page: Math.max(Math.ceil(total / perPage), 1), date_basis: dateBasis, filter: FILTER },
    };
}

export const transactionOptions = {
    data: {
        products: [PRODUCT, 'Paket B'],
        payment_methods: ['BCA', 'QRIS'],
        product_types: ['Perdana', 'Perpanjangan'],
        promo_codes: ['CPNS10', 'RES1'],
    },
    meta: { date_basis: 'created', filter: FILTER },
};

const FINANCIALS = { product_price: 200000, voucher_discount: 20000, admin_fee: 3000, nominal: 183000 };

const member = (id, trxId, name, phone) => ({
    id, trx_id: trxId, customer: { name, markaz_customer_id: id, email: `${name.toLowerCase()}@example.test`, phone }, status: { code: 1, label: 'Success' }, is_claim_coupon: false,
});

export const transactionDetails = {
    812: {
        data: {
            id: 812, trx_id: 'jadiasn-476770', markaz_transaction_id: 476770,
            created_at: '2026-11-12T10:00:00+07:00', paid_date: '2026-11-12', paid_time: '10:05:00',
            platform: PLATFORM, product: PRODUCT, product_type: 'Perdana', payment_method: 'QRIS', promo_codes: ['CPNS10'],
            status: { code: 1, label: 'Success' }, is_claim_coupon: false,
            customer: { name: 'Rina', markaz_customer_id: 501, email: 'rina@example.test', phone: '081200000001' },
            financials: { ...FINANCIALS, revenue_contribution: 180000 },
            group: { role: 'buyer', buyer: null, members: [member(813, 'jadiasn-476771', 'Dewi', '081200000002'), member(814, 'jadiasn-476772', 'Eko', null)] },
        },
    },
    820: {
        data: {
            id: 820, trx_id: 'jadiasn-476790', markaz_transaction_id: 476790,
            created_at: '2026-11-11T09:00:00+07:00', paid_date: null, paid_time: null,
            platform: PLATFORM, product: PRODUCT, product_type: 'Perpanjangan', payment_method: 'QRIS', promo_codes: [],
            status: { code: 0, label: 'Pending' }, is_claim_coupon: false,
            customer: { name: 'Budi', markaz_customer_id: 502, email: 'budi@example.test', phone: null },
            financials: { ...FINANCIALS, revenue_contribution: null },
            group: { role: null, buyer: null, members: [] },
        },
    },
    813: {
        data: {
            id: 813, trx_id: 'jadiasn-476771', markaz_transaction_id: 476771,
            created_at: '2026-11-12T10:00:00+07:00', paid_date: '2026-11-12', paid_time: '10:05:00',
            platform: PLATFORM, product: PRODUCT, product_type: 'Perdana', payment_method: 'QRIS', promo_codes: [],
            status: { code: 1, label: 'Success' }, is_claim_coupon: false,
            // An account that cannot see contacts gets no email or phone keys.
            customer: { name: 'Dewi', markaz_customer_id: 503 },
            financials: { product_price: 0, voucher_discount: 0, admin_fee: 0, nominal: 0, revenue_contribution: null },
            group: { role: 'member', buyer: member(812, 'jadiasn-476770', 'Rina', '081200000001'), members: [] },
        },
    },
    840: {
        data: {
            id: 840, trx_id: 'jadiasn-476810', markaz_transaction_id: 476810,
            created_at: '2026-11-09T07:00:00+07:00', paid_date: '2026-11-09', paid_time: null,
            platform: PLATFORM, product: PRODUCT, product_type: 'Perdana', payment_method: null, promo_codes: ['RES1'],
            status: { code: 1, label: 'Success' }, is_claim_coupon: true,
            customer: { name: 'Andi', markaz_customer_id: 504, email: 'andi@example.test', phone: '081200000004' },
            financials: { product_price: 0, voucher_discount: 0, admin_fee: 0, nominal: 0, revenue_contribution: null },
            group: { role: null, buyer: null, members: [] },
        },
    },
};
