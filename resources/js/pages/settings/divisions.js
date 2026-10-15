import Alpine from 'alpinejs';
import { showToast } from '../../components/toast';
import { http, errorMessage, validationErrors } from '../../http';

const ENDPOINT = '/api/divisions';

const emptyForm = () => ({ code_division: '', name: '' });

Alpine.data('divisionManagement', (canManage = false) => ({
    canManage,
    divisions: [],

    draft: emptyForm(),
    draftErrors: {},
    draftError: '',
    creating: false,

    loading: true,
    loadError: '',
    saving: false,

    search: '',

    modal: null,
    form: emptyForm(),
    errors: {},
    formError: '',
    editingId: null,
    deletingDivision: null,

    init() {
        this.fetchDivisions();
    },

    get filteredDivisions() {
        const keyword = this.search.trim().toLowerCase();
        if (!keyword) return this.divisions;

        return this.divisions.filter((division) =>
            [division.code_division, division.name].some((value) => value.toLowerCase().includes(keyword)),
        );
    },

    get totalMembers() {
        return this.divisions.reduce((total, division) => total + (division.accounts_count ?? 0), 0);
    },

    get emptyDivisions() {
        return this.divisions.filter((division) => !division.accounts_count).length;
    },

    async fetchDivisions() {
        this.loading = true;
        this.loadError = '';

        try {
            const { data } = await http(ENDPOINT);
            this.divisions = data;
        } catch (error) {
            this.loadError = errorMessage(error, 'Gagal memuat daftar divisi.');
        } finally {
            this.loading = false;
        }
    },

    resetDraft() {
        this.draft = emptyForm();
        this.draftErrors = {};
        this.draftError = '';
    },

    openCreate() {
        this.resetDraft();
        this.modal = 'create';
    },

    openEdit(division) {
        this.form = { code_division: division.code_division, name: division.name };
        this.editingId = division.id;
        this.errors = {};
        this.formError = '';
        this.modal = 'edit';
    },

    openDelete(division) {
        this.deletingDivision = division;
        this.formError = '';
        this.modal = 'delete';
    },

    closeModal() {
        if (this.saving || this.creating) return;

        this.modal = null;
        this.editingId = null;
        this.deletingDivision = null;
        this.errors = {};
        this.formError = '';
    },

    payload(form) {
        return { code_division: form.code_division.trim(), name: form.name.trim() };
    },

    async save(request) {
        this.saving = true;
        this.errors = {};
        this.formError = '';

        try {
            return await request();
        } catch (error) {
            if (error.status === 422 && error.data?.errors) {
                this.errors = validationErrors(error);
            } else {
                this.formError = errorMessage(error);
            }
            return null;
        } finally {
            this.saving = false;
        }
    },

    async submitCreate() {
        this.creating = true;
        this.draftErrors = {};
        this.draftError = '';

        try {
            const { data } = await http(ENDPOINT, { method: 'POST', body: this.payload(this.draft) });

            this.divisions.push(data);
            this.resetDraft();
            this.modal = null;
            showToast({ title: 'Divisi berhasil dibuat', text: `${data.name} sudah ditambahkan.` });
        } catch (error) {
            if (error.status === 422 && error.data?.errors) {
                this.draftErrors = validationErrors(error);
            } else {
                this.draftError = errorMessage(error);
            }
        } finally {
            this.creating = false;
        }
    },

    async submitEdit() {
        const id = this.editingId;
        const result = await this.save(() => http(`${ENDPOINT}/${id}`, { method: 'PUT', body: this.payload(this.form) }));
        if (!result) return;

        this.divisions = this.divisions.map((division) => (division.id === id ? result.data : division));
        this.closeModal();
        showToast({ title: 'Perubahan disimpan', text: `Divisi ${result.data.name} sudah diperbarui.` });
    },

    async confirmDelete() {
        const division = this.deletingDivision;
        if (!division) return this.closeModal();

        const result = await this.save(() => http(`${ENDPOINT}/${division.id}`, { method: 'DELETE' }));
        if (!result) return;

        this.divisions = this.divisions.filter((item) => item.id !== division.id);
        this.closeModal();
        showToast({ title: 'Divisi dihapus', text: `${division.name} sudah dihapus.` });
    },
}));
