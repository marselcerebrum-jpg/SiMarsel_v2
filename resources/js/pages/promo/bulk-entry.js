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
            const room = MAX_ROWS - this.rows.length;
            this.rows.push(...Array.from({ length: Math.min(count, room) }, emptyRow));
        },

        removeRow(index) {
            this.rows.splice(index, 1);
            if (this.rows.length === 0) this.addRows();
        },

        removeSavedRows() {
            this.rows = this.rows.filter((row) => row.status !== 'created');
            if (this.rows.length === 0) this.addRows(INITIAL_ROWS);
            this.summary = null;
        },

        clearError(row, field) {
            if (row.errors[field]) row.errors = { ...row.errors, [field]: null };
        },

        /**
         * Pasting cells copied from a spreadsheet (Code, Platform, Funnel, Source per line, tab separated)
         * fills the grid from the focused row downwards.
         */
        paste(event, index) {
            const text = event.clipboardData?.getData('text') ?? '';
            if (!/[\t\n]/.test(text.trim())) return;

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
                const cells = [
                    ['platform_id', platform, this.options.platforms, 'Platform'],
                    ['funnel_id', funnel, this.options.funnels, 'Funnel'],
                    ['source_id', source, this.options.sources, 'Source'],
                ];

                row.code = code.trim();
                cells.forEach(([field, value, options, label]) => {
                    const id = matchOption(options, value);
                    row[field] = id ?? '';
                    if (id === null) errors[field] = `${label} "${value.trim()}" tidak ditemukan.`;
                });

                row.status = null;
                row.errors = errors;
            });
        },

        async submit() {
            this.formError = '';

            if (this.pendingRows.length === 0) {
                this.formError = 'Isi minimal satu baris kode promo.';
                return;
            }

            // Saved rows are sent empty so the backend ignores them and row numbers keep matching the grid.
            const payload = this.rows.map((row) =>
                row.status === 'created'
                    ? {}
                    : {
                          code: row.code.trim(),
                          platform_id: toId(row.platform_id),
                          funnel_id: toId(row.funnel_id),
                          source_id: toId(row.source_id),
                      },
            );

            this.saving = true;

            try {
                const { data } = await bulkCreatePromoCodes(payload);

                data.rows.forEach((result) => {
                    const row = this.rows[result.row - 1];
                    if (!row) return;

                    row.status = result.status;
                    row.errors = validationErrors({ data: { errors: result.errors } });
                    if (result.promo_code) row.code = result.promo_code.code;
                });

                this.summary = data.summary;

                if (data.summary.succeeded > 0) {
                    window.dispatchEvent(new CustomEvent('promo-codes-changed'));
                }

                showToast({
                    type: data.summary.failed > 0 ? 'info' : 'success',
                    title: 'Bulk Entry Processed',
                    text: `${data.summary.succeeded} kode berhasil disimpan, ${data.summary.failed} gagal.`,
                });
            } catch (error) {
                this.formError = errorMessage(error, 'Gagal menyimpan kode promo. Silakan coba lagi.');
            } finally {
                this.saving = false;
            }
        },
    };
}
