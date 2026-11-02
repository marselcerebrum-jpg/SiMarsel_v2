import { showToast } from '../../components/toast';
import { errorMessage, validationErrors } from '../../http';
import { bulkCreatePromoCodes, fetchMasters, fetchPlatforms } from './api';

export const MAX_ROWS = 500;
const INITIAL_ROWS = 5;

let nextKey = 0;

const emptyRow = () => ({
    key: ++nextKey,
    code: '',
    platform_id: '',
    funnel_id: '',
    source_id: '',
    status: null,
    errors: {},
    unmatched: {},
});

const toId = (value) => (value === '' || value === null || value === undefined ? null : Number(value));
const isBlank = (row) => !row.code.trim() && !row.platform_id && !row.funnel_id && !row.source_id;

/**
 * Finds the option whose name (or platform slug) matches a pasted cell, ignoring case.
 */
function matchOption(options, value) {
    const needle = value.trim().toLowerCase();
    if (!needle) return '';

    const found = options.find((option) => [option.name, option.slug].some((label) => label?.toLowerCase() === needle));

    return found ? String(found.id) : null;
}

export default function promoCodeBulkEntry() {
    return {
        isOpen: false,
        rows: [],
        summary: null,
        formError: '',
        saving: false,

        options: { platforms: [], funnels: [], sources: [] },
        optionsLoading: false,
        optionsError: '',

        maxRows: MAX_ROWS,

        get pendingRows() {
            return this.rows.filter((row) => row.status !== 'created' && !isBlank(row));
        },

        get canAddRows() {
            return this.rows.length < MAX_ROWS;
        },

        open() {
            if (this.saving) return;
            // Keep unsaved rows between openings; start fresh once everything was saved.
            if (this.pendingRows.length === 0) this.reset();

            this.isOpen = true;
            this.fetchOptions();
        },

        close() {
            if (this.saving) return;

            this.isOpen = false;
        },

        reset() {
            if (this.saving) return;
            this.rows = Array.from({ length: INITIAL_ROWS }, emptyRow);
            this.summary = null;
            this.formError = '';
        },

        async fetchOptions() {
            this.optionsLoading = true;
            this.optionsError = '';

            try {
                const [platforms, funnels, sources] = await Promise.all([
                    fetchPlatforms(),
                    fetchMasters('funnel'),
                    fetchMasters('source'),
                ]);

                this.options = { platforms: platforms.data, funnels: funnels.data, sources: sources.data };
            } catch (error) {
                this.optionsError = errorMessage(error, 'Gagal memuat pilihan platform, funnel, dan source.');
            } finally {
                this.optionsLoading = false;
            }
        },

        addRows(count = 1) {
            if (this.saving) return;
            const room = MAX_ROWS - this.rows.length;
            this.rows.push(...Array.from({ length: Math.min(count, room) }, emptyRow));
        },

        removeRow(index) {
            if (this.saving) return;
            this.rows.splice(index, 1);
            if (this.rows.length === 0) this.addRows();
        },

        removeSavedRows() {
            if (this.saving) return;
            this.rows = this.rows.filter((row) => row.status !== 'created');
            if (this.rows.length === 0) this.addRows(INITIAL_ROWS);
            this.summary = null;
        },

        clearError(row, field) {
            if (row.errors[field]) row.errors = { ...row.errors, [field]: null };
            delete row.unmatched[field];
        },

        /**
         * Pasting cells copied from a spreadsheet (Code, Platform, Funnel, Source per line, tab separated)
         * fills the grid from the focused row downwards.
         */
        paste(event, index) {
            if (this.saving) return;
            const text = event.clipboardData?.getData('text') ?? '';
            if (!/[\t\n]/.test(text)) return;

            event.preventDefault();

            const lines = text.replace(/\r/g, '').split('\n').filter((line) => line.trim() !== '');
            const needed = index + lines.length - this.rows.length;
            if (needed > 0) this.addRows(needed);

            const room = this.rows.length - index;
            if (lines.length > room) {
                this.formError = `Hanya ${room} baris pertama yang ditempel karena batas ${MAX_ROWS} baris per penyimpanan.`;
            }

            lines.slice(0, room).forEach((line, offset) => {
                const row = this.rows[index + offset];
                if (row.status === 'created') return;

                const [code = '', platform = '', funnel = '', source = ''] = line.split('\t');
                const errors = {};
                const unmatched = {};
                const cells = [
                    ['platform_id', platform, this.options.platforms, 'Platform'],
                    ['funnel_id', funnel, this.options.funnels, 'Funnel'],
                    ['source_id', source, this.options.sources, 'Source'],
                ];

                row.code = code.trim();
                cells.forEach(([field, value, options, label]) => {
                    const id = matchOption(options, value);
                    row[field] = id ?? `unmatched:${value.trim()}`;
                    if (id === null) {
                        unmatched[field] = value.trim();
                        errors[field] = `${label} "${value.trim()}" tidak ditemukan.`;
                    }
                });

                row.status = null;
                row.errors = errors;
                row.unmatched = unmatched;
            });
        },

        async submit() {
            if (this.saving) return;
            this.formError = '';

            if (this.pendingRows.length === 0) {
                this.formError = 'Isi minimal satu baris kode promo.';
                return;
            }

            // Keep invalid pasted rows local; preserve placeholders for readable server row numbers.
            const invalidRows = this.pendingRows.filter((row) => Object.keys(row.unmatched).length > 0);
            invalidRows.forEach((row) => { row.status = 'failed'; });
            const submittedRows = new Map();
            const submittedByPosition = [...this.rows];
            const payload = this.rows.map((row) => {
                if (row.status === 'created' || isBlank(row) || Object.keys(row.unmatched).length > 0) return {};

                submittedRows.set(String(row.key), row);

                return {
                    client_row_id: String(row.key),
                    code: row.code.trim(),
                    platform_id: toId(row.platform_id),
                    funnel_id: toId(row.funnel_id),
                    source_id: toId(row.source_id),
                };
            });

            if (submittedRows.size === 0) {
                this.summary = { total: invalidRows.length, succeeded: 0, failed: invalidRows.length };
                this.formError = 'Perbaiki Funnel, Source, atau Platform yang tidak ditemukan sebelum menyimpan baris tersebut.';
                return;
            }

            this.saving = true;

            try {
                const { data } = await bulkCreatePromoCodes(payload);

                data.rows.forEach((result) => {
                    const row = result.client_row_id == null
                        ? submittedByPosition[result.row - 1]
                        : submittedRows.get(result.client_row_id);
                    if (!row) return;

                    row.status = result.status;
                    row.errors = validationErrors({ data: { errors: result.errors } });
                    if (result.promo_code) row.code = result.promo_code.code;
                });

                this.summary = {
                    ...data.summary,
                    total: data.summary.total + invalidRows.length,
                    failed: data.summary.failed + invalidRows.length,
                };

                if (data.summary.succeeded > 0) {
                    window.dispatchEvent(new CustomEvent('promo-codes-changed'));
                }

                showToast({
                    type: this.summary.failed > 0 ? 'info' : 'success',
                    title: 'Bulk Entry Processed',
                    text: `${this.summary.succeeded} kode berhasil disimpan, ${this.summary.failed} gagal.`,
                });
            } catch (error) {
                this.formError = errorMessage(error, 'Gagal menyimpan kode promo. Silakan coba lagi.');
            } finally {
                this.saving = false;
            }
        },
    };
}
