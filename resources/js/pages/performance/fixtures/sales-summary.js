/**
 * Ringkasan Sales responses in the backend contract: `data.sales_summary` of GET /api/performance/summary
 * (FIX-05-BE-01, PR #145 and #146, docs/api/performance.md). Test data for the cards;
 * tests/Feature/Web/DashboardSalesSummaryContractTest.php keeps the shape and the claim scenario in line with the endpoint.
 */

const AS_OF = '2026-11-11T00:00:00+07:00';
const COMPARISON_AS_OF = '2026-11-04T00:00:00+07:00';

const PAID = 'Berdasarkan tanggal pembayaran';
const CREATED = 'Berdasarkan tanggal transaksi dibuat';

const GROUPS = [
    { key: 'sales_result', label: 'Hasil Penjualan', date_basis: 'paid', date_basis_label: PAID },
    { key: 'transaction_activity', label: 'Aktivitas Transaksi', date_basis: 'created', date_basis_label: CREATED },
    { key: 'supporting', label: 'Metrik Pendukung', date_basis: null, date_basis_label: null },
];

/** Key => [label, group, date basis, unit]. */
const METRICS = {
    revenue: ['Omzet', 'sales_result', 'paid', 'currency'],
    success_payments: ['Transaksi Berhasil', 'sales_result', 'paid', 'count'],
    total_checkouts: ['Total Transaksi', 'transaction_activity', 'created', 'count'],
    conversion: ['Konversi Transaksi', 'transaction_activity', 'created', 'percentage'],
    average_order_value: ['Rata-rata Nilai Order', 'supporting', 'paid', 'currency'],
    unique_buyers: ['Pembeli Unik', 'supporting', 'paid', 'count'],
    downloaders: ['Downloader', 'supporting', null, 'count'],
};

const BASIS_LABELS = { paid: PAID, created: CREATED };

/**
 * Values of one side: created-date counts (claims count in Total only) and payment-date values.
 * `null` makes the whole side unavailable.
 */
function values({ attempts, claims = 0, success, unsuccessful = 0, unknown = 0, conversion, payments, revenue, nominal, aov, buyers, downloaders }) {
    return {
        revenue: { value: revenue, nominal_total: nominal },
        success_payments: { value: payments },
        total_checkouts: {
            value: attempts + claims,
            breakdown: { purchase_attempts: attempts, success_purchases: success, unsuccessful_purchases: unsuccessful, unknown_status_count: unknown, claim_checkouts: claims },
        },
        conversion: {
            value: conversion,
            success_purchases: success,
            purchase_attempts: attempts,
            unknown_status_count: unknown,
            is_provisional: unknown > 0,
            provisional_label: unknown > 0 ? 'Sementara' : null,
        },
        average_order_value: { value: aov },
        unique_buyers: { value: buyers },
        downloaders: { value: downloaders },
    };
}

const UNAVAILABLE_DETAILS = {
    revenue: { nominal_total: null },
    total_checkouts: { breakdown: null },
    conversion: { success_purchases: null, purchase_attempts: null, unknown_status_count: null, is_provisional: null, provisional_label: null },
};

function side(key, sideValues, { availability = 'complete', asOf = AS_OF, source = 'latest' } = {}) {
    if (sideValues === null) {
        return { value: null, availability: 'unavailable', as_of: null, source, ...(UNAVAILABLE_DETAILS[key] ?? {}) };
    }

    return { availability, as_of: asOf, source, ...sideValues[key] };
}

/** Change as the backend returns it: percentage points for Konversi, relative for the rest, null with an empty or 0 side. */
function change(unit, active, comparison) {
    const changeUnit = unit === 'percentage' ? 'percentage_point' : 'percent';
    const known = active !== null && comparison !== null;
    let value = null;

    if (known && changeUnit === 'percentage_point') value = Math.round((active - comparison) * 100) / 100;
    if (known && changeUnit === 'percent' && comparison !== 0) value = Math.round(((active - comparison) / comparison) * 10000) / 100;

    let trend = null;
    if (known) trend = active > comparison ? 'up' : active < comparison ? 'down' : 'same';

    return { value, unit: changeUnit, trend, is_trimmed: false, active_value: known ? active : null, comparison_value: known ? comparison : null };
}

/**
 * `comparison`: values of the comparison side, `null` for an unavailable side (missing snapshot), or `false` when the
 * active period has no equivalent dates (every `comparison` is null).
 */
function salesSummary(active, comparison = false, { activeSide = {}, comparisonSide = { asOf: COMPARISON_AS_OF } } = {}) {
    return {
        groups: GROUPS,
        metrics: Object.entries(METRICS).map(([key, [label, group, dateBasis, unit]]) => {
            const activeValue = side(key, active, activeSide);
            const comparisonValue = comparison === false ? null : side(key, comparison, comparisonSide);
            const known = (item) => (item && item.availability !== 'unavailable' ? item.value : null);

            return {
                key,
                label,
                group,
                date_basis: dateBasis,
                date_basis_label: BASIS_LABELS[dateBasis] ?? null,
                unit,
                active: activeValue,
                comparison: comparisonValue,
                change: change(unit, known(activeValue), known(comparisonValue)),
            };
        }),
    };
}

const TODAY_ACTIVE = values({
    attempts: 15, claims: 1, success: 9, unsuccessful: 3, unknown: 1, conversion: 60,
    payments: 9, revenue: 1462500, nominal: 1472000, aov: 162500, buyers: 7, downloaders: 73,
});
const TODAY_COMPARISON = values({
    attempts: 10, claims: 2, success: 5, conversion: 50,
    payments: 8, revenue: 1250000, nominal: 1260000, aov: 156250, buyers: 6, downloaders: 70,
});
const TODAY_SIDE = { activeSide: { availability: 'partial', asOf: '2026-11-11T13:00:00+07:00' }, comparisonSide: { asOf: '2026-11-10T13:00:00+07:00', source: 'snapshot' } };

/** Hari Ini until 13.00: Konversi 60% vs 50%, Sementara with 1 status not available yet. */
export const todaySalesSummary = salesSummary(TODAY_ACTIVE, TODAY_COMPARISON, TODAY_SIDE);

/** Hari Ini without the equivalent-hour snapshot: every active value stays, every comparison is unavailable. */
export const missingComparisonSalesSummary = salesSummary(TODAY_ACTIVE, null, TODAY_SIDE);

const EMPTY = values({ attempts: 0, success: 0, conversion: null, payments: 0, revenue: 0, nominal: 0, aov: null, buyers: 0, downloaders: 0 });

/** Complete day without data: counts are a real 0, ratios cannot be computed. */
export const emptyCompleteSalesSummary = salesSummary(EMPTY, EMPTY);

/** Coverage unknown: nothing is shown as 0. */
export const incompleteSalesSummary = salesSummary(null, null);

const CROSS_DAY_ACTIVE = values({
    attempts: 10, success: 6, unsuccessful: 3, conversion: 60,
    payments: 8, revenue: 1440000, nominal: 1462000, aov: 180000, buyers: 7, downloaders: 120,
});

/** 10 transactions created, 6 Success, and 8 payments in the active period (parent AC-03). */
export const crossDaySalesSummary = salesSummary(CROSS_DAY_ACTIVE, values({
    attempts: 8, success: 4, conversion: 50,
    payments: 5, revenue: 900000, nominal: 912000, aov: 180000, buyers: 5, downloaders: 100,
}));

/** The comparison period is complete and has no data: relative changes cannot be computed (pembagi nol). */
export const zeroComparisonSalesSummary = salesSummary(CROSS_DAY_ACTIVE, EMPTY);

/** Only the Omzet comparison is missing; every other card keeps its comparison. */
export const missingRevenueComparisonSalesSummary = {
    ...crossDaySalesSummary,
    metrics: crossDaySalesSummary.metrics.map((metric) => (metric.key === 'revenue'
        ? { ...metric, comparison: { ...metric.comparison, value: null, availability: 'unavailable', as_of: null, nominal_total: null }, change: change('currency', metric.active.value, null) }
        : metric)),
};

/** 1 Success and 1 Pending purchase plus 1 claim (Success), all created in the same period. */
export const claimSalesSummary = salesSummary(values({
    attempts: 2, claims: 1, success: 1, conversion: 50,
    payments: 1, revenue: 180000, nominal: 182000, aov: 180000, buyers: 1, downloaders: 12,
}));

/** Only a claim was created: Total 1, Konversi has a zero divisor. */
export const onlyClaimSalesSummary = salesSummary(values({
    attempts: 0, claims: 1, success: 0, conversion: null,
    payments: 0, revenue: 0, nominal: 0, aov: null, buyers: 0, downloaders: 3,
}));

/** Two Success payments of nominal 182.000 and 185.000 (admin fee 2.000 and 5.000), net 180.000 each, one buyer. */
export const revenueSalesSummary = salesSummary(values({
    attempts: 2, success: 2, conversion: 100,
    payments: 2, revenue: 360000, nominal: 367000, aov: 180000, buyers: 1, downloaders: 5,
}));

/** 6 Success, 3 Pending and 1 purchase without status: Konversi 60% Sementara. */
export const provisionalSalesSummary = salesSummary(values({
    attempts: 10, success: 6, unknown: 1, conversion: 60,
    payments: 6, revenue: 1080000, nominal: 1092000, aov: 180000, buyers: 6, downloaders: 40,
}));

/** The same 10 purchases after the empty status is filled with Success: Konversi 70%, final. */
export const resolvedSalesSummary = salesSummary(values({
    attempts: 10, success: 7, conversion: 70,
    payments: 7, revenue: 1260000, nominal: 1274000, aov: 180000, buyers: 7, downloaders: 40,
}));
