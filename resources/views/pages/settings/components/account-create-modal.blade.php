@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $inputClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
    $selectClass = $inputClass.' appearance-none pr-9';
    $chevron = '<svg class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>';
    $errorClass = 'mt-1.5 text-xs text-red-600';
    $helperClass = 'mt-1.5 text-xs text-muted';
    $required = '<span class="text-red-500" aria-hidden="true">*</span>';
@endphp

<div
    x-show="modal === 'create'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="create-user-title"
    @keydown.escape.window="modal === 'create' && closeModal()"
    x-init="$watch('modal', (value) => value === 'create' && $nextTick(() => $refs.createName.focus()))"
>
    <div x-show="modal === 'create'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'create'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-2xl overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="create-user-title" class="text-base font-semibold text-ink">Create Account</h2>
        <p class="mt-1 text-sm text-muted">Daftarkan akun baru untuk anggota tim. Hanya manager yang bisa membuat akun.</p>

        <form class="mt-5 grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submitCreate()">
            <div>
                <label for="create-name" class="{{ $labelClass }}">Full Name {!! $required !!}</label>
                <input id="create-name" x-ref="createName" type="text" x-model="draft.fullname" @input="draftErrors.fullname = null"
                    class="{{ $inputClass }}" :class="draftErrors.fullname && 'border-red-500!'" placeholder="Budi Santoso" autocomplete="off">
                <p x-show="draftErrors.fullname" x-text="draftErrors.fullname" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="create-role" class="{{ $labelClass }}">Role {!! $required !!}</label>
                <div class="relative">
                    <select id="create-role" x-model="draft.role_id" @change="draftErrors.role_id = null"
                        class="{{ $selectClass }}" :class="draftErrors.role_id && 'border-red-500!'">
                        <template x-for="role in roles" :key="role.id">
                            <option :value="role.id" x-text="role.name" :selected="role.id == draft.role_id"></option>
                        </template>
                    </select>
                    {!! $chevron !!}
                </div>
                <p x-show="draftErrors.role_id" x-text="draftErrors.role_id" class="{{ $errorClass }}"></p>
            </div>

            <div class="sm:col-span-2">
                <label for="create-division" class="{{ $labelClass }}">Division</label>
                <div class="relative">
                    <select id="create-division" x-model="draft.division_id" @change="draftErrors.division_id = null"
                        class="{{ $selectClass }}" :class="draftErrors.division_id && 'border-red-500!'">
                        <option value="">— No Division (Cross-Division) —</option>
                        <template x-for="division in divisions" :key="division.id">
                            <option :value="division.id" x-text="division.name" :selected="division.id == draft.division_id"></option>
                        </template>
                    </select>
                    {!! $chevron !!}
                </div>
                <p x-show="!draftErrors.division_id" class="{{ $helperClass }}">Opsional — kosongkan untuk akun yang tidak terikat pada satu divisi.</p>
                <p x-show="draftErrors.division_id" x-text="draftErrors.division_id" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="create-username" class="{{ $labelClass }}">Username {!! $required !!}</label>
                <input id="create-username" type="text" x-model="draft.username" @input="draftErrors.username = null"
                    class="{{ $inputClass }}" :class="draftErrors.username && 'border-red-500!'" placeholder="budi.santoso" autocomplete="off" autocapitalize="none" spellcheck="false">
                <p x-show="!draftErrors.username" class="{{ $helperClass }}">4-25 karakter: huruf, angka, titik, atau underscore.</p>
                <p x-show="draftErrors.username" x-text="draftErrors.username" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="create-password" class="{{ $labelClass }}">Password {!! $required !!}</label>
                <div class="relative">
                    <input id="create-password" :type="showDraftPassword ? 'text' : 'password'" x-model="draft.password" @input="draftErrors.password = null"
                        class="{{ $inputClass }} pr-16" :class="draftErrors.password && 'border-red-500!'" placeholder="Minimal 8 karakter" autocomplete="new-password">
                    <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-semibold text-muted hover:text-ink"
                        @click="showDraftPassword = !showDraftPassword" x-text="showDraftPassword ? 'Hide' : 'Show'" :aria-pressed="showDraftPassword.toString()"></button>
                </div>
                <p x-show="draftErrors.password" x-text="draftErrors.password" class="{{ $errorClass }}"></p>
            </div>

            <p x-show="draftError" x-text="draftError" class="text-xs text-red-600 sm:col-span-2"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:col-span-2 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="creating" @click="closeModal()">Cancel</button>
                <button type="submit" :disabled="creating" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-text="creating ? 'Saving…' : 'Create Account'">Create Account</span>
                </button>
            </div>
        </form>
    </div>
</div>
