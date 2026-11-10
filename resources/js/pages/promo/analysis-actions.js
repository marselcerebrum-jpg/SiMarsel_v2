import { download } from '../../export';
import { errorMessage, validationErrors } from '../../http';
import { showToast } from '../../components/toast';
import { exportAnalysis } from './api';
import { COPY_TARGETS, runCopy } from './analysis-copy';

const FALLBACK_FILE_NAME = 'promo-analisis.xlsx';

/**
 * Ekspor Excel (Export) and Salin (Copy) for the promo analysis. Placed inside `promoAnalysis`, so it reads the
 * active filter (`query`, `filterInvalid`) from that scope. Both actions only read data.
 *
 * - Export asks the components for their view state with the synchronous `promo-analysis-export-params`
 *   event (the trend adds its period, the code summary its sort), then downloads the backend file.
 * - Copy asks the selected component for its data with `promo-analysis-copy` (see analysis-copy.js).
 */
export default function promoAnalysisActions() {
    return {
        copyTargets: COPY_TARGETS,
        copyTarget: COPY_TARGETS[0].value,
        exporting: false,
        copying: false,
        status: null,

        /**
         * One action at a time, so their status messages do not overwrite each other.
         */
        get busy() {
            return this.exporting || this.copying;
        },

        get actionsDisabled() {
            return this.query === null || this.filterInvalid;
        },

        get copyLabel() {
            return COPY_TARGETS.find(({ value }) => value === this.copyTarget)?.label ?? '';
        },

        clearStatus() {
            if (!this.busy) this.status = null;
        },

        exportQuery() {
            const detail = { params: new URLSearchParams(this.query ?? '') };
            this.$dispatch('promo-analysis-export-params', detail);

            return detail.params.toString();
        },

        async exportExcel() {
            if (this.busy || this.actionsDisabled) return;

            this.exporting = true;
            this.status = { type: 'loading', message: 'Menyiapkan file Excel sesuai filter aktif…' };

            try {
                const { blob, fileName } = await exportAnalysis(this.exportQuery());
                const name = fileName ?? FALLBACK_FILE_NAME;

                download(blob, name);
                this.status = null;
                showToast({ title: 'Ekspor Berhasil', text: `File ${name} berhasil diunduh.` });
            } catch (error) {
                if (error?.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                }

                this.status = { type: 'error', message: errorMessage(error, 'Gagal mengekspor data analisis promo. Silakan coba lagi.') };
            } finally {
                this.exporting = false;
            }
        },

        async copy() {
            if (this.busy || this.actionsDisabled) return;

            const label = this.copyLabel;
            const detail = { target: this.copyTarget, snapshot: null };
            this.$dispatch('promo-analysis-copy', detail);

            this.copying = true;
            this.status = { type: 'loading', message: `Menyalin data ${label} sesuai filter aktif…` };

            try {
                const { message, error } = await runCopy(detail.snapshot, label);

                this.status = error ? { type: 'error', message: error } : null;
                if (message) showToast({ title: 'Data Disalin', text: message });
            } finally {
                this.copying = false;
            }
        },
    };
}
