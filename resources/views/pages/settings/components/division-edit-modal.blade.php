@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $inputClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
    $errorClass = 'mt-1.5 text-xs text-red-600';
@endphp

<div
    x-show="modal === 'edit'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="edit-division-title"
    @keydown.escape.window="modal === 'edit' && closeModal()"
    x-init="$watch('modal', (value) => value === 'edit' && $nextTick(() => $refs.editCode.focus()))"
>
    <div x-show="modal === 'edit'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'edit'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="edit-division-title" class="text-base font-semibold text-ink">Edit Division</h2>
        <p class="mt-1 text-sm text-muted">Perbarui kode atau nama divisi. Anggota divisi tidak berubah.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submitEdit()">
            <div>
                <label for="edit-code" class="{{ $labelClass }}">Division Code</label>
                <input id="edit-code" x-ref="editCode" type="text" x-model="form.code_division" @input="errors.code_division = null"
                    class="{{ $inputClass }}" :class="errors.code_division && 'border-red-500!'" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="50">
                <p x-show="errors.code_division" x-text="errors.code_division" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="edit-division-name" class="{{ $labelClass }}">Division Name</label>
                <input id="edit-division-name" type="text" x-model="form.name" @input="errors.name = null"
                    class="{{ $inputClass }}" :class="errors.name && 'border-red-500!'" autocomplete="off" maxlength="25">
                <p x-show="errors.name" x-text="errors.name" class="{{ $errorClass }}"></p>
            </div>

            <p x-show="formError" x-text="formError" class="text-xs text-red-600"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" @click="closeModal()">Cancel</button>
                <button type="submit" :disabled="saving" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50" x-text="saving ? 'Saving…' : 'Save Changes'"></button>
            </div>
        </form>
    </div>
</div>
