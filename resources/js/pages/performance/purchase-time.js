import { errorMessage } from '../../http';
import { fetchPurchaseTimes } from './api';
import { createRequestTracker } from './filter';
import {
    COMPACT_WIDTH,
    NOT_AVAILABLE,
    chartLabel,
    comparisonNotice,
    dayOptions,
    hasComparison,
    peakHoursConfig,
    peakSlot,
    slotDetail,
    slotRows,
    totalsLabel,
    unavailableRanges,
    visibleSeries,
} from './peak-hours';

/** `data.hourly` of GET /api/performance/purchase-times for the filter query and the chosen day. */
async function fetchHourly(query, options) {
    const { data } = await fetchPurchaseTimes(query, options);

    return data?.hourly ?? null;
}

/** Tells the Dashboard export (FIX-14, sheet Jam Ramai Pembelian) the day actually shown, as `{ day_of_week }`. */
export const DAY_EVENT = 'performance-purchase-day';

/** Width of the slot detail card in px (Tailwind w-60). */
const DETAIL_WIDTH = 240;

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

/**
 * Jam Ramai Pembelian (FIX-12-FE-01). Reloads whenever the global filter dispatches `performance-filter`, and when
 * the day changes. The day and Tampilkan Pembanding are local state: a new main filter goes back to Semua Hari
 * (its days may differ), and the comparison choice is kept.
 *
 * `render(canvas, config, previous)` draws the chart (renderChart from ./chart); `resolve(query, options)`
 * returns `data.hourly` of the response.
 */
export default function performancePurchaseTime({ resolve = fetchHourly, render = null } = {}) {
    const track = createRequestTracker();
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        hourlyQuery: null,
        dayOfWeek: '',
        hourly: null,
        showComparison: false,
        slotIndex: null,
        detailLeft: 0,
        hourlyLoading: true,
        hourlyError: '',

        get slots() {
            return this.hourly?.slots ?? [];
        },

        get hasSlots() {
            return this.slots.length > 0;
        },

        get hourlyPlaceholder() {
            if (this.hourlyError) return this.hourlyError;

            return this.hourly === null && !this.hourlyLoading ? 'Data jam ramai belum tersedia untuk filter yang dipilih.' : '';
        },

        get daySelectable() {
            return this.hourly?.day_selectable === true;
        },

        get dayOptions() {
            return dayOptions(this.hourly);
        },

        get dayLabel() {
            return this.hourly?.day_label ?? '';
        },

        get comparisonAvailable() {
            return hasComparison(this.hourly);
        },

        get comparisonNotice() {
            return comparisonNotice(this.hourly);
        },

        get comparisonShown() {
            return this.showComparison && this.comparisonAvailable;
        },

        get legend() {
            return visibleSeries(this.hourly, this.showComparison);
        },

        get activeTotals() {
            return totalsLabel(this.hourly?.active);
        },

        get comparisonTotals() {
            return totalsLabel(this.hourly?.comparison);
        },

        get peakHour() {
            return peakSlot(this.slots);
        },

        get unavailableHours() {
            return unavailableRanges(this.slots);
        },

        get unavailableNote() {
            return this.unavailableHours ? `${NOT_AVAILABLE}: ${this.unavailableHours} WIB` : '';
        },

        get chartLabel() {
            return chartLabel(this.hourly, this.legend);
        },

        get rows() {
            return slotRows(this.slots, this.legend);
        },

        get detail() {
            const slot = this.slotIndex === null ? null : this.slots[this.slotIndex];

            return slot ? slotDetail(slot, this.legend) : null;
        },

        applyFilter(query) {
            this.hourlyQuery = query;
            this.dayOfWeek = '';
            this.closeSlot();
            this.load();
        },

        /** Day choice from the select ('' = Semua Hari); applied to the active period and the comparison. */
        setDay(value) {
            this.dayOfWeek = value ?? '';
            this.closeSlot();
            this.load();
        },

        toggleComparison() {
            if (!this.comparisonAvailable) return;

            this.showComparison = !this.showComparison;
            this.closeSlot();
            this.draw();
        },

        retry() {
            this.load();
        },

        async load() {
            if (this.hourlyQuery === null) return;

            const isCurrent = track();
            this.hourlyLoading = true;
            this.hourlyError = '';

            try {
                const hourly = await resolve(this.hourlyQuery, { dayOfWeek: this.dayOfWeek || null });
                if (!isCurrent()) return;

                this.hourly = hourly;
                this.dayOfWeek = hourly?.day_of_week ? String(hourly.day_of_week) : '';
                this.$dispatch(DAY_EVENT, { day_of_week: hourly?.day_of_week ?? null });
            } catch (error) {
                if (!isCurrent()) return;

                // Drop the previous result so it is not read as the answer for this filter or day.
                this.hourly = null;
                this.hourlyError = errorMessage(error, 'Gagal memuat jam ramai pembelian.');
            } finally {
                if (isCurrent()) {
                    this.hourlyLoading = false;
                    this.draw();
                }
            }
        },

        draw() {
            if (!this.hasSlots || !render) {
                chart?.destroy();
                chart = null;
                return;
            }

            // Wait for Alpine to reveal the canvas so Chart.js can measure it.
            this.$nextTick(() => {
                const canvas = this.$refs.canvas;

                chart = render(canvas, peakHoursConfig({
                    slots: this.slots,
                    series: this.legend,
                    compact: (canvas.parentElement?.clientWidth ?? COMPACT_WIDTH) < COMPACT_WIDTH,
                    onSlot: (index) => this.showSlot(index),
                    onLeave: () => this.closeSlot(),
                }), chart);
            });
        },

        /** Opens the detail of one slot (hover, tap or keyboard) and keeps the card inside the chart. */
        showSlot(index) {
            if (!this.hasSlots) return;

            const next = clamp(index, 0, this.slots.length - 1);
            if (next === this.slotIndex) return;

            this.slotIndex = next;
            if (!chart) return;

            const x = chart.scales.x.getPixelForValue(next);
            this.detailLeft = clamp(x - DETAIL_WIDTH / 2, 0, Math.max(chart.width - DETAIL_WIDTH, 0));
            chart.options.plugins.slotBands.index = next;
            chart.update('none');
        },

        closeSlot() {
            if (this.slotIndex === null) return;

            this.slotIndex = null;
            if (!chart) return;

            chart.options.plugins.slotBands.index = null;
            chart.update('none');
        },

        /** Arrow keys move the open slot; focusing the chart opens the first one. */
        moveSlot(step) {
            this.showSlot(this.slotIndex === null ? 0 : this.slotIndex + step);
        },

        firstSlot() {
            this.showSlot(0);
        },

        lastSlot() {
            this.showSlot(this.slots.length - 1);
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
}
