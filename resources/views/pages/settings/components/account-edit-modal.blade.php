@php
    $labelClass = 'mb-1.5 block pl-1 text-[11px] font-bold tracking-widest text-label uppercase';
    $inputClass = 'h-11 w-full rounded-xl border border-line bg-soft px-4 text-sm font-medium text-ink placeholder:font-normal placeholder:text-label focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60';
    $errorClass = 'mt-1 pl-1 text-xs text-red-600';
@endphp

<div
    x-show="modal === 'edit'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="edit-user-title"
    @keydown.escape.window="modal === 'edit' && closeModal()"
    x-init="$watch('modal', (value) => value === 'edit' && $nextTick(() => $refs.editName.focus()))"
>
    <div x-show="modal === 'edit'" x-transition.opacity class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm" @click="closeModal()"></div>

    <div
        x-show="modal === 'edit'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-3xl border border-white/60 bg-surface p-6 shadow-2xl sm:p-7 dark:bg-surface-dim"
    >
        <h2 id="edit-user-title" class="text-xl font-bold tracking-tight">Edit Akun<span class="text-brand-indigo">.</span></h2>
        <p class="mt-1 text-sm text-muted">Perbarui data akun. Kosongkan password jika tidak ingin mengubahnya.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submitEdit()">
            <div>
                <label for="edit-name" class="{{ $labelClass }}">Nama Lengkap</label>
                <input id="edit-name" x-ref="editName" type="text" x-model="form.fullname" @input="errors.fullname = null"
                    class="{{ $inputClass }}" :class="errors.fullname && 'border-red-500!'" placeholder="Masukkan nama lengkap" autocomplete="off">
                <p x-show="errors.fullname" x-text="errors.fullname" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="edit-username" class="{{ $labelClass }}">Username</label>
                <input id="edit-username" type="text" x-model="form.username" @input="errors.username = null"
                    class="{{ $inputClass }}" :class="errors.username && 'border-red-500!'" placeholder="4-25 karakter: huruf, angka, titik, underscore" autocomplete="off" autocapitalize="none" spellcheck="false">
                <p x-show="errors.username" x-text="errors.username" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="edit-password" class="{{ $labelClass }}">Password</label>
                <div class="relative">
                    <input id="edit-password" :type="showPassword ? 'text' : 'password'" x-model="form.password" @input="errors.password = null"
                        class="{{ $inputClass }} pr-16" :class="errors.password && 'border-red-500!'" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password">
                    <button type="button" class="absolute top-1/2 right-2 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-medium text-label hover:text-ink"
                        @click="showPassword = !showPassword" x-text="showPassword ? 'Sembunyi' : 'Lihat'" :aria-pressed="showPassword.toString()"></button>
                </div>
                <p x-show="errors.password" x-text="errors.password" class="{{ $errorClass }}"></p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="edit-role" class="{{ $labelClass }}">Role</label>
                    <select id="edit-role" x-model="form.role_id" @change="errors.role_id = null" :disabled="editingId === currentUserId"
                        class="{{ $inputClass }}" :class="errors.role_id && 'border-red-500!'">
                        <template x-for="role in roles" :key="role.id">
                            <option :value="role.id" x-text="role.name" :selected="role.id == form.role_id"></option>
                        </template>
                    </select>
                    <p x-show="editingId === currentUserId" class="mt-1 pl-1 text-xs text-muted">Role akun sendiri tidak dapat diubah.</p>
                    <p x-show="errors.role_id" x-text="errors.role_id" class="{{ $errorClass }}"></p>
                </div>

                <div>
                    <label for="edit-division" class="{{ $labelClass }}">Divisi</label>
                    <select id="edit-division" x-model="form.division_id" @change="errors.division_id = null"
                        class="{{ $inputClass }}" :class="errors.division_id && 'border-red-500!'">
                        <option value="">Tanpa divisi</option>
                        <template x-for="division in divisions" :key="division.id">
                            <option :value="division.id" x-text="division.name" :selected="division.id == form.division_id"></option>
                        </template>
                    </select>
                    <p x-show="errors.division_id" x-text="errors.division_id" class="{{ $errorClass }}"></p>
                </div>
            </div>

            <p x-show="formError" x-text="formError" class="text-xs text-red-600"></p>

            <div class="mt-2 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="h-11 rounded-xl border border-line bg-soft px-5 text-sm font-medium text-ink hover:border-brand-indigo" @click="closeModal()">Batal</button>
                <button type="submit" :disabled="saving" class="h-11 disabled:opacity-60 rounded-xl bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal px-5 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(93,96,162,0.3)] [text-shadow:0_1px_2px_rgba(20,30,50,0.35)] hover:brightness-95" x-text="saving ? 'Menyimpan…' : 'Simpan Perubahan'"></button>
            </div>
        </form>
    </div>
</div>
