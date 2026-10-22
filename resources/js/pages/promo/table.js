import { showToast } from '../../components/toast';
import { errorMessage, validationErrors } from '../../http';
import { createMaster, deleteMaster, fetchMasters, fetchPlatforms, updateMaster } from './api';

/**
 * CRUD table for one promo master (Funnel or Source).
 */
export function promoMasterTable(type, label) {
    return {
        label,
        items: [],
        loading: true,
        loadError: '',
        search: '',

        modal: null,
        editing: null,
        deleting: null,
        name: '',
        errors: {},
        formError: '',
        saving: false,

        init() {
            this.fetchItems();
        },

        get filteredItems() {
            const keyword = this.search.trim().toLowerCase();
            if (!keyword) return this.items;

            return this.items.filter((item) => item.name.toLowerCase().includes(keyword));
        },

        async fetchItems({ silent = false } = {}) {
            if (!silent) this.loading = true;
            this.loadError = '';

            try {
                const { data } = await fetchMasters(type);
                this.items = data;
            } catch (error) {
                if (!silent) this.loadError = errorMessage(error, `Gagal memuat daftar ${label.toLowerCase()}.`);
            } finally {
                this.loading = false;
            }
        },

        resetErrors() {
            this.errors = {};
            this.formError = '';
        },

        openCreate() {
            this.editing = null;
            this.name = '';
            this.resetErrors();
            this.modal = 'form';
        },

        openEdit(item) {
            this.editing = item;
            this.name = item.name;
            this.resetErrors();
            this.modal = 'form';
        },

        openDelete(item) {
            this.deleting = item;
            this.resetErrors();
            this.modal = 'delete';
        },

        closeModal() {
            if (this.saving) return;

            this.modal = null;
            this.editing = null;
            this.deleting = null;
            this.resetErrors();
        },

        /**
         * Runs the request and maps failures onto the open modal; returns null when it failed.
         */
        async send(request) {
            this.saving = true;
            this.resetErrors();

            try {
                return await request();
            } catch (error) {
                if (error.status === 422 && error.data?.errors) {
                    this.errors = validationErrors(error);
                } else {
                    this.formError = errorMessage(error);
                }

                // A 409 means promo codes still use the record; refresh its usage count.
                if (error.status === 409) this.fetchItems({ silent: true });

                return null;
            } finally {
                this.saving = false;
            }
        },

        async submit() {
            const editing = this.editing;
            const name = this.name.trim();

            const result = await this.send(() =>
                editing ? updateMaster(type, editing.id, name) : createMaster(type, name),
            );
            if (!result) return;

            if (editing) {
                this.items = this.items.map((item) => (item.id === editing.id ? result.data : item));
                showToast({ title: 'Changes Saved', text: `${label} ${result.data.name} sudah diperbarui.` });
            } else {
                this.items.push(result.data);
                showToast({ title: `${label} Created`, text: `${result.data.name} sudah ditambahkan.` });
            }

            this.closeModal();
        },

        async confirmDelete() {
            const deleting = this.deleting;
            if (!deleting) return this.closeModal();

            const result = await this.send(() => deleteMaster(type, deleting.id));
            if (!result) return;

            this.items = this.items.filter((item) => item.id !== deleting.id);
            this.closeModal();
            showToast({ title: `${label} Deleted`, text: `${deleting.name} sudah dihapus.` });
        },
    };
}

/**
 * Platforms come from the global master and are read-only in the promo module.
 */
export function promoPlatformTable() {
    return {
        platforms: [],
        loading: true,
        loadError: '',

        init() {
            this.fetchPlatforms();
        },

        async fetchPlatforms() {
            this.loading = true;
            this.loadError = '';

            try {
                const { data } = await fetchPlatforms();
                this.platforms = data;
            } catch (error) {
                this.loadError = errorMessage(error, 'Gagal memuat daftar platform.');
            } finally {
                this.loading = false;
            }
        },
    };
}
