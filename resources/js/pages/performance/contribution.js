import { errorMessage } from '../../http';
import { fetchPlatformContribution } from './api';
import { formatValue } from './format';
import { contributionRows } from './table';

const EMPTY_MESSAGE = 'Tidak ada data kontribusi platform untuk filter yang dipilih.';

// Keeps the fixed tooltip inside the viewport (half of its max width plus a gutter).
const TIP_EDGE = 136;

/**
 * Platform contribution ranking. Uses the same filter as the Performance Summary and the
 * trend analysis: it reloads whenever the main filter dispatches `performance-filter`.
 */
export default () => ({
    contribution: null,
    contributionLoading: true,
    contributionError: '',
    contributionRequestId: 0,
    contributionQuery: null,
    tip: null,

    get contributionPlatforms() {
        return this.contribution?.platforms ?? [];
    },

    get contributionEmpty() {
        return this.contribution?.is_empty === true || this.contributionPlatforms.length === 0;
    },

    get showContribution() {
        return this.contribution !== null && !this.contributionEmpty && !this.contributionError;
    },

    get contributionState() {
        if (this.showContribution) return 'ready';
        if (this.contributionLoading) return 'loading';

        return this.contributionError ? 'error' : 'empty';
    },

    get contributionMessage() {
        return this.contributionError || EMPTY_MESSAGE;
    },

    get contributionRevenue() {
        return formatValue(this.contribution?.totals?.revenue, 'currency');
    },

    get contributionPlatformCount() {
        return formatValue(this.contributionPlatforms.length, 'number');
    },

    get contributionTableRows() {
        return contributionRows(this.contributionPlatforms, this.contribution?.totals?.revenue);
    },

    async loadContribution(query) {
        const requestId = ++this.contributionRequestId;
        this.contributionQuery = query;
        this.contributionLoading = true;
        this.contributionError = '';
        this.hideTip();

        try {
            const { data } = await fetchPlatformContribution(query);
            if (requestId !== this.contributionRequestId) return;

            this.contribution = data;
        } catch (error) {
            if (requestId !== this.contributionRequestId) return;

            this.contribution = null;
            this.contributionError = errorMessage(error, 'Gagal memuat kontribusi platform.');
        } finally {
            if (requestId === this.contributionRequestId) this.contributionLoading = false;
        }
    },

    retryContribution() {
        if (this.contributionQuery !== null) this.loadContribution(this.contributionQuery);
    },

    /**
     * Tooltip for a progress bar, positioned above it. It is teleported to <body> with fixed
     * positioning so the table's horizontal scroll container cannot clip it.
     */
    showTip(row, event) {
        const rect = event.currentTarget.getBoundingClientRect();
        const center = rect.left + rect.width / 2;

        this.tip = {
            ...row,
            x: Math.min(Math.max(center, TIP_EDGE), window.innerWidth - TIP_EDGE),
            y: rect.top,
        };
    },

    hideTip() {
        this.tip = null;
    },
});
