/**
 * Display text for the period resolver response (`data.filter.period`, see fixtures/resolved-periods.js).
 * Every date, hour and boundary comes from the response; nothing is computed from the browser clock.
 */

import { PROVISIONAL, UNAVAILABLE, formatWibDate, formatWibDateTime } from './formatters';

const RECORD_BASIS = Object.freeze({
    at_cutoff: 'jam setara',
    latest: 'keadaan terbaru',
});

const wibParts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Jakarta',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

/** ISO timestamp → { date: "YYYY-MM-DD", hour: "14.00" } in WIB. */
function wib(value) {
    const parts = Object.fromEntries(wibParts.formatToParts(new Date(value)).map(({ type, value: part }) => [type, part]));

    return { date: `${parts.year}-${parts.month}-${parts.day}`, hour: `${parts.hour}.${parts.minute}` };
}

/**
 * Hours of a range. The resolver gives `start_at` and an exclusive `end_at`; a midnight `end_at` after the
 * last day reads as 24.00 on that day.
 */
function hours(range) {
    const end = wib(range.end_at);

    return { start: wib(range.start_at).hour, end: end.date > range.date_to ? '24.00' : end.hour };
}

/** → "11 Nov 2026, 00.00–14.00 WIB" or "5 Nov 2026, 00.00 – 11 Nov 2026, 14.00 WIB"; no range is "Belum tersedia". */
export function formatPeriodRange(range) {
    if (!range?.date_from || !range?.date_to || !range.start_at || !range.end_at) return UNAVAILABLE;

    const { start, end } = hours(range);

    if (range.date_from === range.date_to) return `${formatWibDate(range.date_from)}, ${start}–${end} WIB`;

    return `${formatWibDate(range.date_from)}, ${start} – ${formatWibDate(range.date_to)}, ${end} WIB`;
}

const isUnavailable = (window) => !window || window.availability === 'unavailable';

/** The comparison record: "10 Nov 2026, 14.00 WIB (jam setara)", or "Belum tersedia" when it does not exist. */
export function formatComparisonRecord(comparison) {
    if (isUnavailable(comparison) || !comparison.cutoff_at) return UNAVAILABLE;

    const basis = RECORD_BASIS[comparison.state_basis];
    const recorded = formatWibDateTime(comparison.cutoff_at);

    return basis ? `${recorded} (${basis})` : recorded;
}

const days = (count) => `${count} hari`;
const ranges = (list) => list.map(formatPeriodRange).join(', ');

/**
 * Everything the period panel shows. `change` is set only when the change uses a trimmed equivalent part;
 * `durations` only when the custom comparison is not as long as the active range.
 */
export function describePeriod(period) {
    const { active, comparison, change_basis: change } = period;
    const provisional = active.availability === 'partial';
    const customDurations = period.comparison_mode === 'custom' && period.durations_differ === true && comparison;

    return {
        active: formatPeriodRange(active),
        provisional,
        provisionalLabel: provisional ? PROVISIONAL : '',
        comparison: formatPeriodRange(comparison),
        completeUntil: isUnavailable(active) || !active.cutoff_at ? UNAVAILABLE : formatWibDateTime(active.cutoff_at),
        snapshot: formatComparisonRecord(comparison),
        change: change?.is_trimmed ? { active: ranges(change.active), comparison: ranges(change.comparison) } : null,
        durations: customDurations ? { active: days(active.days), comparison: days(comparison.days) } : null,
    };
}
