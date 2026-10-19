<div
    x-show="modal === 'delete'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="delete-division-title"
    aria-describedby="delete-division-desc"
    @keydown.escape.window="modal === 'delete' && closeModal()"
    x-init="$watch('modal', (value) => value === 'delete' && $nextTick(() => $refs.deleteCancel.focus()))"
>
    <div x-show="modal === 'delete'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'delete'"
        x-transition
        class="relative w-full max-w-sm rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="delete-division-title" class="text-base font-semibold text-ink">Delete Division?</h2>
        <p id="delete-division-desc" class="mt-1 text-sm text-muted">
            Apakah Anda yakin ingin menghapus divisi
            <strong class="font-semibold text-ink" x-text="deletingDivision?.name"></strong>?
            Tindakan ini tidak dapat dibatalkan.
        </p>

        <p x-show="deletingDivision?.accounts_count" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <span class="font-semibold" x-text="deletingDivision?.accounts_count + ' akun'"></span>
            di divisi ini akan menjadi tanpa divisi.
        </p>

        <p x-show="formError" x-text="formError" class="mt-4 text-xs text-red-600"></p>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" x-ref="deleteCancel" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" @click="closeModal()">Cancel</button>
            <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:cursor-not-allowed disabled:opacity-50" :disabled="saving" @click="confirmDelete()" x-text="saving ? 'Deleting…' : 'Yes, Delete'"></button>
        </div>
    </div>
</div>
