/**
 * Jam Ramai Pembelian responses in the backend contract (`hourly` of `GET /api/performance/purchase-times`,
 * docs/api/purchase-times.md). Times, counts and amounts are test data:
 * - 7 Hari Terakhir (4–10 Nov 2026), complete: one group package (net 300.000) paid Friday 6 Nov at 19.20 WIB, and
 *   one payment of 200.000 at 19.40 on Friday 30 Oct in the comparison. Claims, Failed payments and payments without
 *   a valid payment time are not in any slot, so they are not in the response either.
 * - Hari Ini (Wed 11 Nov 2026) complete up to 14.00: 00.00–13.59 complete (11.00 complete with no payment),
 *   14.00–23.59 Belum Tersedia. The 14.00 boundary is test data; the real one follows the completeness metadata.
 */

const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
const pad = (value) => String(value).padStart(2, '0');

function side(hour, { date, cutoffHour = 24, values = {} }) {
    if (hour >= cutoffHour) return { success_payments: null, revenue: null, availability: 'unavailable', as_of: null };

    const [payments, revenue] = values[hour] ?? [0, 0];

    return { success_payments: payments, revenue, availability: 'complete', as_of: `${date}T${pad(hour)}:59:59+07:00` };
}

function slots(active, comparison) {
    return Array.from({ length: 24 }, (_, hour) => ({
        hour,
        label: `${pad(hour)}.00–${pad(hour)}.59`,
        start: `${pad(hour)}:00`,
        end: `${pad(hour)}:59`,
        active: side(hour, active),
        comparison: comparison ? side(hour, comparison) : null,
    }));
}

function summary({ date_from, date_to, days, availability, as_of, payments, revenue }) {
    return { date_from, date_to, days, availability, as_of, totals: { success_payments: payments, revenue } };
}

const WEEK_OPTIONS = DAYS.map((label, index) => ({ value: index + 1, label }));

/** 7 Hari Terakhir with a day of the week (1–7) or Semua Hari (null). */
export function weekPeakHours(dayOfWeek = null) {
    const friday = dayOfWeek === null || dayOfWeek === 5;
    const days = dayOfWeek === null ? 7 : 1;

    return {
        timezone: 'Asia/Jakarta',
        day_selectable: true,
        day_of_week: dayOfWeek,
        day_label: dayOfWeek === null ? 'Semua Hari' : DAYS[dayOfWeek - 1],
        day_options: WEEK_OPTIONS,
        active: summary({ date_from: '2026-11-04', date_to: '2026-11-10', days, availability: 'complete', as_of: '2026-11-11T00:00:00+07:00', payments: friday ? 1 : 0, revenue: friday ? 300000 : 0 }),
        comparison: summary({ date_from: '2026-10-28', date_to: '2026-11-03', days, availability: 'complete', as_of: '2026-11-04T00:00:00+07:00', payments: friday ? 1 : 0, revenue: friday ? 200000 : 0 }),
        comparison_message: null,
        slots: slots(
            { date: '2026-11-10', values: friday ? { 19: [1, 300000] } : {} },
            { date: '2026-11-03', values: friday ? { 19: [1, 200000] } : {} },
        ),
    };
}

/** Hari Ini, one day: no day choice; complete up to 14.00. */
export const todayPeakHours = {
    timezone: 'Asia/Jakarta',
    day_selectable: false,
    day_of_week: null,
    day_label: 'Semua Hari',
    day_options: [],
    active: summary({ date_from: '2026-11-11', date_to: '2026-11-11', days: 1, availability: 'partial', as_of: '2026-11-11T14:00:00+07:00', payments: 2, revenue: 360000 }),
    comparison: summary({ date_from: '2026-11-10', date_to: '2026-11-10', days: 1, availability: 'partial', as_of: '2026-11-10T14:00:00+07:00', payments: 1, revenue: 180000 }),
    comparison_message: null,
    slots: slots(
        { date: '2026-11-11', cutoffHour: 14, values: { 8: [2, 360000] } },
        { date: '2026-11-10', cutoffHour: 14, values: { 8: [1, 180000] } },
    ),
};

/** A comparison period that does not contain the chosen day. */
export function noMatchingDayPeakHours(dayOfWeek = 5) {
    return {
        ...weekPeakHours(dayOfWeek),
        comparison: null,
        comparison_message: 'Tidak ada hari yang sama di periode pembanding.',
        slots: weekPeakHours(dayOfWeek).slots.map((slot) => ({ ...slot, comparison: null })),
    };
}
