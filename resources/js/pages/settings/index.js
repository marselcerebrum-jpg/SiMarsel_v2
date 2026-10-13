import Alpine from 'alpinejs';
import { showToast } from '../../components/toast';
import { http, errorMessage, validationErrors } from '../../http';

const ENDPOINT = '/api/accounts';

const emptyForm = () => ({ fullname: '', username: '', password: '', role_id: '', division_id: '' });

Alpine.data('accountSettings', (roles = [], divisions = []) => ({
    roles,
    divisions,
    users: [],

    tab: 'create',

    draft: emptyForm(),
    draftErrors: {},
    draftError: '',
    creating: false,
    showDraftPassword: false,

    loading: true,
    loadError: '',
    saving: false,

    filterDivision: '',
    search: '',

    modal: null,
    form: emptyForm(),
    errors: {},
    formError: '',
    editingId: null,
    deletingUser: null,
    showPassword: false,

    init() {
        this.resetDraft();
        this.fetchUsers();
    },

    get currentUserId() {
        return this.$store.session.user?.id;
    },

    get filteredUsers() {
        const keyword = this.search.trim().toLowerCase();

        return this.users.filter((user) => {
            if (this.filterDivision && user.division?.code_division !== this.filterDivision) return false;
            if (!keyword) return true;

            return [user.fullname, user.username, user.division?.name ?? ''].some((value) => value.toLowerCase().includes(keyword));
        });
    },

    async fetchUsers() {
        this.loading = true;
        this.loadError = '';

        try {
            const { data } = await http(ENDPOINT);
            this.users = data;
        } catch (error) {
            this.loadError = errorMessage(error, 'Gagal memuat daftar akun.');
        } finally {
            this.loading = false;
        }
    },

    countByRole(code) {
        return this.users.filter((user) => user.role.code_role === code).length;
    },

    countWithoutDivision() {
        return this.users.filter((user) => !user.division).length;
    },

    roleIdByCode(code) {
        return this.roles.find((role) => role.code_role === code)?.id ?? '';
    },

    divisionIdByCode(code) {
        return this.divisions.find((division) => division.code_division === code)?.id ?? '';
    },

    isSelf(user) {
        return user.id === this.currentUserId;
    },

    resetFilters() {
        this.filterDivision = '';
        this.search = '';
    },

    resetFormState() {
        this.errors = {};
        this.formError = '';
        this.showPassword = false;
    },

    resetDraft() {
        this.draft = { ...emptyForm(), role_id: this.roleIdByCode('EMP') };
        this.draftErrors = {};
        this.draftError = '';
        this.showDraftPassword = false;
    },

    openEdit(user) {
        this.form = {
            fullname: user.fullname,
            username: user.username,
            password: '',
            role_id: this.roleIdByCode(user.role.code_role),
            division_id: this.divisionIdByCode(user.division?.code_division),
        };
        this.editingId = user.id;
        this.resetFormState();
        this.modal = 'edit';
    },

    openDelete(user) {
        if (this.isSelf(user)) return;

        this.deletingUser = user;
        this.formError = '';
        this.modal = 'delete';
    },

    closeModal() {
        if (this.saving) return;

        this.modal = null;
        this.editingId = null;
        this.deletingUser = null;
        this.resetFormState();
    },

    payload(form, { isEdit }) {
        const payload = {
            fullname: form.fullname.trim(),
            username: form.username.trim(),
            division_id: form.division_id === '' ? null : Number(form.division_id),
        };

        if (!isEdit || form.password !== '') payload.password = form.password;
        if (!(isEdit && this.editingId === this.currentUserId)) payload.role_id = Number(form.role_id) || null;

        return payload;
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
            const { data } = await http(ENDPOINT, { method: 'POST', body: this.payload(this.draft, { isEdit: false }) });

            this.users.push(data);
            this.resetDraft();
            showToast({ title: 'Akun berhasil dibuat', text: `${data.fullname} sudah ditambahkan.` });
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
        const result = await this.save(() => http(`${ENDPOINT}/${id}`, { method: 'PUT', body: this.payload(this.form, { isEdit: true }) }));
        if (!result) return;

        this.users = this.users.map((user) => (user.id === id ? result.data : user));
        this.closeModal();
        showToast({ title: 'Perubahan disimpan', text: `Data ${result.data.fullname} sudah diperbarui.` });

        if (id === this.currentUserId) {
            setTimeout(() => window.location.reload(), 800);
        }
    },

    async confirmDelete() {
        const user = this.deletingUser;
        if (!user) return this.closeModal();

        const result = await this.save(() => http(`${ENDPOINT}/${user.id}`, { method: 'DELETE' }));
        if (!result) return;

        this.users = this.users.filter((item) => item.id !== user.id);
        this.closeModal();
        showToast({ title: 'Akun dihapus', text: `${user.fullname} sudah dihapus.` });
    },
}));

