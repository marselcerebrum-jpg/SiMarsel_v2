import Alpine from 'alpinejs';
import { errorMessage } from '../../http';
import { fetchPlatformContribution } from './api';
import { contributionChartConfig, renderChart } from './chart';
import { contributionRows, contributionTotals } from './table';

/**
 * Platform contribution. Uses the same filter as the Performance Summary:
 * it reloads whenever the main filter dispatches `performance-filter`.
 */
export default () => {
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        contribution: null,
        contributionLoading: true,
        contributionError: '',
        contributionRequestId: 0,

        get contributionPlatforms() {
            return this.contribution?.platforms ?? [];
        },

        get contributionEmpty() {
            return this.contribution?.is_empty === true || (this.contribution !== null && this.contributionPlatforms.length === 0);
        },

        get contributionMessage() {
            if (this.contributionError) return this.contributionError;

            return this.contribution?.message || 'Data tidak tersedia untuk filter yang dipilih.';
        },

        get showContribution() {
            return this.contribution !== null && !this.contributionEmpty && !this.contributionError;
        },

        get contributionTableRows() {
            return contributionRows(this.contributionPlatforms);
        },

        get contributionTotalRow() {
            return contributionTotals(this.contribution?.totals);
        },

        async loadContribution(query) {
            const requestId = ++this.contributionRequestId;
            this.contributionLoading = true;
            this.contributionError = '';

            try {
                const { data } = await fetchPlatformContribution(query);
                if (requestId !== this.contributionRequestId) return;

                this.contribution = data;
            } catch (error) {
                if (requestId !== this.contributionRequestId) return;

                this.contribution = null;
                this.contributionError = errorMessage(error, 'Gagal memuat kontribusi platform.');
            } finally {
                if (requestId === this.contributionRequestId) {
                    this.contributionLoading = false;
                    this.drawContribution();
                }
            }
        },

        drawContribution() {
            if (!this.showContribution) {
                chart?.destroy();
                chart = null;
                return;
            }

            // Wait for x-show to reveal the canvas so Chart.js can measure it.
            this.$nextTick(() => {
                chart = renderChart(this.$refs.contributionCanvas, contributionChartConfig(Alpine.raw(this.contributionPlatforms)), chart);
            });
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
};
