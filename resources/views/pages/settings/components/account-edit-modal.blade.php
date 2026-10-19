@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $inputClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-muted';
    $selectClass = $inputClass.' appearance-none pr-9';
    $chevron = '<svg class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>';
    $errorClass = 'mt-1.5 text-xs text-red-600';
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
    <div x-show="modal === 'edit'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'edit'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="edit-user-title" class="text-base font-semibold text-ink">Edit Account</h2>
        <p class="mt-1 text-sm text-muted">Perbarui data akun. Kosongkan password jika tidak ingin mengubahnya.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submitEdit()">
            <div>
                <label for="edit-name" class="{{ $labelClass }}">Full Name</label>
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
                    <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-semibold text-muted hover:text-ink"
                        @click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'" :aria-pressed="showPassword.toString()"></button>
                </div>
                <p x-show="errors.password" x-text="errors.password" class="{{ $errorClass }}"></p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="edit-role" class="{{ $labelClass }}">Role</label>
                    <div class="relative">
                        <select id="edit-role" x-model="form.role_id" @change="errors.role_id = null" :disabled="editingId === currentUserId"
                            class="{{ $selectClass }}" :class="errors.role_id && 'border-red-500!'">
                            <template x-for="role in roles" :key="role.id">
                                <option :value="role.id" x-text="role.name" :selected="role.id == form.role_id"></option>
                            </template>
                        </select>
                        {!! $chevron !!}
                    </div>
                    <p x-show="editingId === currentUserId" class="mt-1.5 text-xs text-muted">Role akun sendiri tidak dapat diubah.</p>
                    <p x-show="errors.role_id" x-text="errors.role_id" class="{{ $errorClass }}"></p>
                </div>

                <div>
                    <label for="edit-division" class="{{ $labelClass }}">Division</label>
                    <div class="relative">
                        <select id="edit-division" x-model="form.division_id" @change="errors.division_id = null"
                            class="{{ $selectClass }}" :class="errors.division_id && 'border-red-500!'">
                            <option value="">No Division</option>
                            <template x-for="division in divisions" :key="division.id">
                                <option :value="division.id" x-text="division.name" :selected="division.id == form.division_id"></option>
                            </template>
                        </select>
                        {!! $chevron !!}
                    </div>
                    <p x-show="errors.division_id" x-text="errors.division_id" class="{{ $errorClass }}"></p>
                </div>
            </div>

            <p x-show="formError" x-text="formError" class="text-xs text-red-600"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" @click="closeModal()">Cancel</button>
                <button type="submit" :disabled="saving" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50" x-text="saving ? 'Saving…' : 'Save Changes'"></button>
            </div>
        </form>
    </div>
</div>
