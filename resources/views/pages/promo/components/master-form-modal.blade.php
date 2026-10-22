{{-- Create and edit share one form. Variables come from master-table. --}}
<div
    x-show="modal === 'form'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $type }}-form-title"
    @keydown.escape.window="modal === 'form' && closeModal()"
    x-init="$watch('modal', (value) => value === 'form' && $nextTick(() => $refs.nameInput.focus()))"
>
    <div x-show="modal === 'form'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'form'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="{{ $type }}-form-title" class="text-base font-semibold text-ink" x-text="editing ? @js('Edit '.$label) : @js('Create '.$label)">Create {{ $label }}</h2>
        <p x-show="!editing" class="mt-1 text-sm text-muted">Tambahkan {{ $noun }} baru untuk klasifikasi kode promo.</p>
        <p x-show="editing" class="mt-1 text-sm text-muted">Mengganti nama tidak mengubah riwayat penggunaan kode promo yang sudah tercatat.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submit()">
            <div>
                <label for="{{ $type }}-name" class="mb-1.5 block text-xs font-semibold text-label">
                    {{ $label }} Name <span class="text-red-500" aria-hidden="true">*</span>
                </label>
                <input
                    id="{{ $type }}-name"
                    x-ref="nameInput"
                    type="text"
                    x-model="name"
                    @input="errors.name = null"
                    class="h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    :class="errors.name && 'border-red-500!'"
                    :aria-invalid="Boolean(errors.name)"
                    aria-describedby="{{ $type }}-name-hint"
                    placeholder="{{ $placeholder }}"
                    autocomplete="off"
                    maxlength="100"
                >
                <p id="{{ $type }}-name-hint" x-show="!errors.name" class="mt-1.5 text-xs text-muted">Nama unik, maksimal 100 karakter.</p>
                <p x-show="errors.name" x-text="errors.name" class="mt-1.5 text-xs text-red-600" role="alert"></p>
            </div>

            <p x-show="formError" x-text="formError" class="text-xs text-red-600" role="alert"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="saving" @click="closeModal()">Cancel</button>
                <button type="submit" :disabled="saving" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-text="saving ? 'Saving…' : (editing ? 'Save Changes' : @js('Save '.$label))">Save {{ $label }}</span>
                </button>
            </div>
        </form>
    </div>
</div>
