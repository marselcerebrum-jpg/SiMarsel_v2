/**
 * Omzet and Transaksi trend responses in the backend contract (`GET /api/performance/trend/sales`, PR #136).
 * Generated from the real endpoint with a fixed clock of Wednesday 11 Nov 2026, 14.00 WIB, one platform and:
 * - two Success payments at 08.20 and 08.45 (net 180.000 each), so Omzet 08.00–09.00 is 360.000
 * - one Pending checkout at 09.10 and one coupon claim at 09.55: in Total Transaksi only
 * - hourly comparison records of 10 Nov up to 14.00, except 11.00, so 10.00 and 11.00 have no comparison
 * The 14.00 boundary is test data; the real one always follows the completeness metadata.
 * tests/Feature/Web/DashboardTrendContractTest.php keeps this scenario in line with the endpoint.
 */

const SERIES = [
    { key: 'revenue', label: 'Omzet', side: 'active', value: 'revenue', unit: 'currency', axis: 'currency', date_basis: 'paid', visible: true },
    { key: 'comparison_revenue', label: 'Omzet Pembanding', side: 'comparison', value: 'revenue', unit: 'currency', axis: 'currency', date_basis: 'paid', visible: true },
    { key: 'success_payments', label: 'Transaksi Berhasil', side: 'active', value: 'success_payments', unit: 'count', axis: 'count', date_basis: 'paid', visible: true },
    { key: 'total_checkouts', label: 'Total Transaksi', side: 'active', value: 'total_checkouts', unit: 'count', axis: 'count', date_basis: 'created', visible: false },
];

const FILTER = { mode: 'today', period: { active: { availability: 'partial', cutoff_at: '2026-11-11T14:00:00+07:00' } } };
const TOTALS = { availability: 'partial', as_of: '2026-11-11T14:00:00+07:00', values: { revenue: 360000, success_payments: 2, total_checkouts: 4 } };

/** Hari Ini with the default grouping (per jam). */
export const todayHourlyTrend = {
    filter: FILTER,
    granularity: 'hourly',
    default_granularity: 'hourly',
    granularities: ['hourly', 'daily', 'weekly', 'monthly'],
    series: SERIES,
    is_empty: false,
    message: null,
    totals: TOTALS,
    buckets: [
        { index: 0, active: { start_at: '2026-11-11T00:00:00+07:00', end_at: '2026-11-11T01:00:00+07:00', label: '11 Nov 2026 00.00', availability: 'complete', as_of: '2026-11-11T01:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T00:00:00+07:00', end_at: '2026-11-10T01:00:00+07:00', label: '10 Nov 2026 00.00', availability: 'complete', as_of: '2026-11-10T01:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 1, active: { start_at: '2026-11-11T01:00:00+07:00', end_at: '2026-11-11T02:00:00+07:00', label: '11 Nov 2026 01.00', availability: 'complete', as_of: '2026-11-11T02:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T01:00:00+07:00', end_at: '2026-11-10T02:00:00+07:00', label: '10 Nov 2026 01.00', availability: 'complete', as_of: '2026-11-10T02:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 2, active: { start_at: '2026-11-11T02:00:00+07:00', end_at: '2026-11-11T03:00:00+07:00', label: '11 Nov 2026 02.00', availability: 'complete', as_of: '2026-11-11T03:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T02:00:00+07:00', end_at: '2026-11-10T03:00:00+07:00', label: '10 Nov 2026 02.00', availability: 'complete', as_of: '2026-11-10T03:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 3, active: { start_at: '2026-11-11T03:00:00+07:00', end_at: '2026-11-11T04:00:00+07:00', label: '11 Nov 2026 03.00', availability: 'complete', as_of: '2026-11-11T04:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T03:00:00+07:00', end_at: '2026-11-10T04:00:00+07:00', label: '10 Nov 2026 03.00', availability: 'complete', as_of: '2026-11-10T04:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 4, active: { start_at: '2026-11-11T04:00:00+07:00', end_at: '2026-11-11T05:00:00+07:00', label: '11 Nov 2026 04.00', availability: 'complete', as_of: '2026-11-11T05:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T04:00:00+07:00', end_at: '2026-11-10T05:00:00+07:00', label: '10 Nov 2026 04.00', availability: 'complete', as_of: '2026-11-10T05:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 5, active: { start_at: '2026-11-11T05:00:00+07:00', end_at: '2026-11-11T06:00:00+07:00', label: '11 Nov 2026 05.00', availability: 'complete', as_of: '2026-11-11T06:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T05:00:00+07:00', end_at: '2026-11-10T06:00:00+07:00', label: '10 Nov 2026 05.00', availability: 'complete', as_of: '2026-11-10T06:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 6, active: { start_at: '2026-11-11T06:00:00+07:00', end_at: '2026-11-11T07:00:00+07:00', label: '11 Nov 2026 06.00', availability: 'complete', as_of: '2026-11-11T07:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T06:00:00+07:00', end_at: '2026-11-10T07:00:00+07:00', label: '10 Nov 2026 06.00', availability: 'complete', as_of: '2026-11-10T07:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 7, active: { start_at: '2026-11-11T07:00:00+07:00', end_at: '2026-11-11T08:00:00+07:00', label: '11 Nov 2026 07.00', availability: 'complete', as_of: '2026-11-11T08:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T07:00:00+07:00', end_at: '2026-11-10T08:00:00+07:00', label: '10 Nov 2026 07.00', availability: 'complete', as_of: '2026-11-10T08:00:00+07:00', source: 'snapshot', values: { revenue: 150000, success_payments: 1, total_checkouts: 1 } } },
        { index: 8, active: { start_at: '2026-11-11T08:00:00+07:00', end_at: '2026-11-11T09:00:00+07:00', label: '11 Nov 2026 08.00', availability: 'complete', as_of: '2026-11-11T09:00:00+07:00', source: 'latest', values: { revenue: 360000, success_payments: 2, total_checkouts: 2 } }, comparison: { start_at: '2026-11-10T08:00:00+07:00', end_at: '2026-11-10T09:00:00+07:00', label: '10 Nov 2026 08.00', availability: 'complete', as_of: '2026-11-10T09:00:00+07:00', source: 'snapshot', values: { revenue: 180000, success_payments: 1, total_checkouts: 2 } } },
        { index: 9, active: { start_at: '2026-11-11T09:00:00+07:00', end_at: '2026-11-11T10:00:00+07:00', label: '11 Nov 2026 09.00', availability: 'complete', as_of: '2026-11-11T10:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 2 } }, comparison: { start_at: '2026-11-10T09:00:00+07:00', end_at: '2026-11-10T10:00:00+07:00', label: '10 Nov 2026 09.00', availability: 'complete', as_of: '2026-11-10T10:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 1 } } },
        { index: 10, active: { start_at: '2026-11-11T10:00:00+07:00', end_at: '2026-11-11T11:00:00+07:00', label: '11 Nov 2026 10.00', availability: 'complete', as_of: '2026-11-11T11:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T10:00:00+07:00', end_at: '2026-11-10T11:00:00+07:00', label: '10 Nov 2026 10.00', availability: 'unavailable', as_of: null, source: 'snapshot', values: null } },
        { index: 11, active: { start_at: '2026-11-11T11:00:00+07:00', end_at: '2026-11-11T12:00:00+07:00', label: '11 Nov 2026 11.00', availability: 'complete', as_of: '2026-11-11T12:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T11:00:00+07:00', end_at: '2026-11-10T12:00:00+07:00', label: '10 Nov 2026 11.00', availability: 'unavailable', as_of: null, source: 'snapshot', values: null } },
        { index: 12, active: { start_at: '2026-11-11T12:00:00+07:00', end_at: '2026-11-11T13:00:00+07:00', label: '11 Nov 2026 12.00', availability: 'complete', as_of: '2026-11-11T13:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T12:00:00+07:00', end_at: '2026-11-10T13:00:00+07:00', label: '10 Nov 2026 12.00', availability: 'complete', as_of: '2026-11-10T13:00:00+07:00', source: 'snapshot', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } } },
        { index: 13, active: { start_at: '2026-11-11T13:00:00+07:00', end_at: '2026-11-11T14:00:00+07:00', label: '11 Nov 2026 13.00', availability: 'complete', as_of: '2026-11-11T14:00:00+07:00', source: 'latest', values: { revenue: 0, success_payments: 0, total_checkouts: 0 } }, comparison: { start_at: '2026-11-10T13:00:00+07:00', end_at: '2026-11-10T14:00:00+07:00', label: '10 Nov 2026 13.00', availability: 'complete', as_of: '2026-11-10T14:00:00+07:00', source: 'snapshot', values: { revenue: 120000, success_payments: 1, total_checkouts: 1 } } },
        { index: 14, active: { start_at: '2026-11-11T14:00:00+07:00', end_at: '2026-11-11T15:00:00+07:00', label: '11 Nov 2026 14.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T14:00:00+07:00', end_at: '2026-11-10T15:00:00+07:00', label: '10 Nov 2026 14.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 15, active: { start_at: '2026-11-11T15:00:00+07:00', end_at: '2026-11-11T16:00:00+07:00', label: '11 Nov 2026 15.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T15:00:00+07:00', end_at: '2026-11-10T16:00:00+07:00', label: '10 Nov 2026 15.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 16, active: { start_at: '2026-11-11T16:00:00+07:00', end_at: '2026-11-11T17:00:00+07:00', label: '11 Nov 2026 16.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T16:00:00+07:00', end_at: '2026-11-10T17:00:00+07:00', label: '10 Nov 2026 16.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 17, active: { start_at: '2026-11-11T17:00:00+07:00', end_at: '2026-11-11T18:00:00+07:00', label: '11 Nov 2026 17.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T17:00:00+07:00', end_at: '2026-11-10T18:00:00+07:00', label: '10 Nov 2026 17.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 18, active: { start_at: '2026-11-11T18:00:00+07:00', end_at: '2026-11-11T19:00:00+07:00', label: '11 Nov 2026 18.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T18:00:00+07:00', end_at: '2026-11-10T19:00:00+07:00', label: '10 Nov 2026 18.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 19, active: { start_at: '2026-11-11T19:00:00+07:00', end_at: '2026-11-11T20:00:00+07:00', label: '11 Nov 2026 19.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T19:00:00+07:00', end_at: '2026-11-10T20:00:00+07:00', label: '10 Nov 2026 19.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 20, active: { start_at: '2026-11-11T20:00:00+07:00', end_at: '2026-11-11T21:00:00+07:00', label: '11 Nov 2026 20.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T20:00:00+07:00', end_at: '2026-11-10T21:00:00+07:00', label: '10 Nov 2026 20.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 21, active: { start_at: '2026-11-11T21:00:00+07:00', end_at: '2026-11-11T22:00:00+07:00', label: '11 Nov 2026 21.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T21:00:00+07:00', end_at: '2026-11-10T22:00:00+07:00', label: '10 Nov 2026 21.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 22, active: { start_at: '2026-11-11T22:00:00+07:00', end_at: '2026-11-11T23:00:00+07:00', label: '11 Nov 2026 22.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T22:00:00+07:00', end_at: '2026-11-10T23:00:00+07:00', label: '10 Nov 2026 22.00', availability: 'unavailable', as_of: null, source: null, values: null } },
        { index: 23, active: { start_at: '2026-11-11T23:00:00+07:00', end_at: '2026-11-12T00:00:00+07:00', label: '11 Nov 2026 23.00', availability: 'unavailable', as_of: null, source: 'latest', values: null }, comparison: { start_at: '2026-11-10T23:00:00+07:00', end_at: '2026-11-11T00:00:00+07:00', label: '10 Nov 2026 23.00', availability: 'unavailable', as_of: null, source: null, values: null } },
    ],
};

/** Hari Ini regrouped per hari: one partial bucket up to the 14.00 boundary. */
export const todayDailyTrend = {
    ...todayHourlyTrend,
    granularity: 'daily',
    buckets: [
        { index: 0, active: { start_at: '2026-11-11T00:00:00+07:00', end_at: '2026-11-12T00:00:00+07:00', label: '11 Nov 2026', availability: 'partial', as_of: '2026-11-11T14:00:00+07:00', source: 'latest', values: { revenue: 360000, success_payments: 2, total_checkouts: 4 } }, comparison: { start_at: '2026-11-10T00:00:00+07:00', end_at: '2026-11-11T00:00:00+07:00', label: '10 Nov 2026', availability: 'partial', as_of: '2026-11-10T14:00:00+07:00', source: 'snapshot', values: { revenue: 650000, success_payments: 4, total_checkouts: 6 } } },
    ],
};

/** 422 for per jam on 90 Hari Terakhir (`granularities` there is daily, weekly, monthly). */
export const hourlyTooLongError = {
    status: 422,
    data: {
        message: 'Rentang tanggal untuk pengelompokan hourly maksimal 31 hari.',
        errors: { granularity: ['Rentang tanggal untuk pengelompokan hourly maksimal 31 hari.'] },
    },
};
