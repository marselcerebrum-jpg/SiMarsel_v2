@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $inputClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
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
    aria-labelledby="create-division-title"
    @keydown.escape.window="modal === 'create' && closeModal()"
    x-init="$watch('modal', (value) => value === 'create' && $nextTick(() => $refs.createCode.focus()))"
>
    <div x-show="modal === 'create'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'create'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="create-division-title" class="text-base font-semibold text-ink">Create Division</h2>
        <p class="mt-1 text-sm text-muted">Tambahkan divisi baru. Divisi bisa dipilih saat membuat atau mengubah akun.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submitCreate()">
            <div>
                <label for="create-code" class="{{ $labelClass }}">Division Code {!! $required !!}</label>
                <input id="create-code" x-ref="createCode" type="text" x-model="draft.code_division" @input="draftErrors.code_division = null"
                    class="{{ $inputClass }}" :class="draftErrors.code_division && 'border-red-500!'" placeholder="MKT" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="50">
                <p x-show="!draftErrors.code_division" class="{{ $helperClass }}">Kode singkat yang unik, maksimal 50 karakter.</p>
                <p x-show="draftErrors.code_division" x-text="draftErrors.code_division" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="create-division-name" class="{{ $labelClass }}">Division Name {!! $required !!}</label>
                <input id="create-division-name" type="text" x-model="draft.name" @input="draftErrors.name = null"
                    class="{{ $inputClass }}" :class="draftErrors.name && 'border-red-500!'" placeholder="Marketing" autocomplete="off" maxlength="25">
                <p x-show="!draftErrors.name" class="{{ $helperClass }}">Nama yang unik, maksimal 25 karakter.</p>
                <p x-show="draftErrors.name" x-text="draftErrors.name" class="{{ $errorClass }}"></p>
            </div>

            <p x-show="draftError" x-text="draftError" class="text-xs text-red-600"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="creating" @click="closeModal()">Cancel</button>
                <button type="submit" :disabled="creating" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-text="creating ? 'Saving…' : 'Save Division'">Save Division</span>
                </button>
            </div>
        </form>
    </div>
</div>
