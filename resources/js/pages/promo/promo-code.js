import { showToast } from '../../components/toast';
import { errorMessage, validationErrors } from '../../http';
import { createPromoCode, deletePromoCode, fetchMasters, fetchPlatforms, fetchPromoCodes, updatePromoCode } from './api';
import { defaultFilter, takePrefillFromUrl, toQueryString } from './filter';

const emptyForm = () => ({ code: '', platform_id: '', funnel_id: '', source_id: '' });

// Select values are strings; '' means "not set".
const toId = (value) => (value === '' || value === null || value === undefined ? null : Number(value));
const toValue = (id) => (id === null || id === undefined ? '' : String(id));

export default function promoCodeManagement() {
    return {
        items: [],
        meta: null,
        filter: defaultFilter(),
        loading: true,
        loadError: '',
        requestId: 0,

        options: { platforms: [], funnels: [], sources: [] },
        optionsLoading: false,
        optionsError: '',

        modal: null,
        registering: null,
        confirming: false,
        editing: null,
        deleting: null,
        form: emptyForm(),
        errors: {},
        formError: '',
        saving: false,

        init() {
            this.fetchItems();
            this.fetchOptions();
            this.$watch('filter.search', () => this.reload());

            const prefill = takePrefillFromUrl();
            if (prefill) this.openCreate(prefill);
        },

        get hasFilter() {
            return this.filter.search.trim() !== '' || this.filter.platform_id !== '';
        },

        get isLocked() {
            return Boolean(this.editing?.usages_count);
        },

        get funnelChanged() {
            return this.editing !== null && toId(this.form.funnel_id) !== (this.editing.funnel?.id ?? null);
        },

        get sourceChanged() {
            return this.editing !== null && toId(this.form.source_id) !== (this.editing.source?.id ?? null);
        },

        async fetchItems() {
            const requestId = ++this.requestId;
            this.loading = true;
            this.loadError = '';

            try {
                const { data, meta } = await fetchPromoCodes(toQueryString(this.filter));
                if (requestId !== this.requestId) return;

                // The last row of a page was deleted: step back to the previous page.
                if (data.length === 0 && this.filter.page > 1) {
                    this.filter.page = meta?.last_page || 1;
                    return this.fetchItems();
                }

                this.items = data;
                this.meta = meta ?? null;
            } catch (error) {
                if (requestId !== this.requestId) return;
                this.loadError = errorMessage(error, 'Gagal memuat daftar kode promo.');
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },

        reload() {
            this.filter.page = 1;
            this.fetchItems();
        },

        resetFilter() {
            this.filter = defaultFilter();
            this.fetchItems();
        },

        goToPage(page) {
            if (!this.meta || page < 1 || page > this.meta.last_page || page === this.filter.page) return;

            this.filter.page = page;
            this.fetchItems();
        },

        /**
         * Platforms, funnels, and sources for the form; refreshed on every open so Master Data edits show up.
         */
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

        resetErrors() {
            this.errors = {};
            this.formError = '';
        },

        openCreate(prefill = {}) {
            this.editing = null;
            this.registering = null;
            this.form = { ...emptyForm(), ...prefill };
            this.resetErrors();
            this.confirming = false;
            this.modal = 'form';
            this.fetchOptions();
        },

        /**
         * Single Entry prefilled from an unregistered code in the alert (code and platform from `register`).
         */
        openRegister(item) {
            this.openCreate({ code: item.register.code, platform_id: String(item.register.platform_id) });
            this.registering = item;
        },

        openEdit(item) {
            this.editing = item;
            this.form = {
                code: item.code,
                platform_id: toValue(item.platform.id),
                funnel_id: toValue(item.funnel?.id),
                source_id: toValue(item.source?.id),
            };
            this.resetErrors();
            this.confirming = false;
            this.modal = 'form';
            this.fetchOptions();
        },

        openDelete(item) {
            this.deleting = item;
            this.resetErrors();
            this.modal = 'delete';
        },

        closeModal() {
            if (this.saving) return;

            this.modal = null;
            this.confirming = false;
            this.editing = null;
            this.registering = null;
            this.deleting = null;
            this.resetErrors();
        },

        /**
         * Only the fields that differ from the saved code are sent on edit.
         */
        changes() {
            const code = this.form.code.trim();
            const values = {
                code,
                platform_id: toId(this.form.platform_id),
                funnel_id: toId(this.form.funnel_id),
                source_id: toId(this.form.source_id),
            };

            if (!this.editing) return values;

            const saved = {
                code: this.editing.code,
                platform_id: this.editing.platform.id,
                funnel_id: this.editing.funnel?.id ?? null,
                source_id: this.editing.source?.id ?? null,
            };

            return Object.fromEntries(
                Object.entries(values).filter(([field, value]) =>
                    field === 'code' ? value.toUpperCase() !== saved.code : value !== saved[field],
                ),
            );
        },

        submit() {
            if (this.editing && Object.keys(this.changes()).length === 0) {
                this.formError = 'Tidak ada perubahan untuk disimpan.';
                return;
            }

            // A funnel/source change must be confirmed before anything is sent.
            if (this.funnelChanged || this.sourceChanged) {
                this.formError = '';
                this.confirming = true;
                return;
            }

            this.save();
        },

        cancelConfirm() {
            this.confirming = false;
        },

        async save() {
            const editing = this.editing;
            const registering = this.registering;
            const body = this.changes();

            this.confirming = false;
            this.saving = true;
            this.resetErrors();

            try {
                const result = editing ? await updatePromoCode(editing.id, body) : await createPromoCode(body);

                this.saving = false;
                this.closeModal();

                // Reloads the list and the unregistered code alert; an edited code/platform can link alert usages too.
                window.dispatchEvent(new CustomEvent('promo-codes-changed'));

                if (editing) {
                    this.items = this.items.map((item) => (item.id === editing.id ? result.data : item));
                    showToast({ title: 'Perubahan Tersimpan', text: `Kode promo ${result.data.code} sudah diperbarui.` });
                } else {
                    showToast(
                        registering
                            ? {
                                  title: 'Kode Promo Didaftarkan',
                                  text: `${result.data.code} terdaftar dan ${result.data.usages_count ?? 0} penggunaan transaksi sudah terhubung.`,
                              }
                            : { title: 'Kode Promo Ditambahkan', text: `${result.data.code} sudah ditambahkan.` },
                    );
                }
            } catch (error) {
                if (error.status === 422 && error.data?.errors) {
                    this.errors = validationErrors(error);

                    // The code may have been registered since the alert was loaded; show the latest alert.
                    if (registering) window.dispatchEvent(new CustomEvent('promo-codes-changed'));
                } else {
                    this.formError = errorMessage(error);
                }
            } finally {
                this.saving = false;
            }
        },

        async confirmDelete() {
            const deleting = this.deleting;
            if (!deleting) return this.closeModal();

            this.saving = true;
            this.resetErrors();

            try {
                await deletePromoCode(deleting.id);

                this.saving = false;
                this.closeModal();
                this.fetchItems();
                showToast({ title: 'Kode Promo Dihapus', text: `${deleting.code} sudah dihapus.` });
            } catch (error) {
                this.formError = errorMessage(error);

                // A 409 means transactions already use the code; refresh its usage count.
                if (error.status === 409) this.fetchItems();
            } finally {
                this.saving = false;
            }
        },
    };
}
