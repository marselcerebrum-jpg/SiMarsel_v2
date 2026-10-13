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
    <div x-show="modal === 'delete'" x-transition.opacity class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm" @click="closeModal()"></div>

    <div
        x-show="modal === 'delete'"
        x-transition
        class="relative w-full max-w-sm rounded-3xl border border-white/60 bg-surface p-6 text-center shadow-2xl sm:p-7 dark:bg-surface-dim"
    >
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-red-100 text-red-600" aria-hidden="true">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12M9 7V4h6v3" />
            </svg>
        </span>

        <h2 id="delete-division-title" class="mt-4 text-lg font-bold tracking-tight">Hapus Divisi?</h2>
        <p id="delete-division-desc" class="mt-1.5 text-sm text-muted">
            Apakah Anda yakin ingin menghapus divisi
            <strong class="font-semibold text-ink" x-text="deletingDivision?.name"></strong>?
            Tindakan ini tidak dapat dibatalkan.
        </p>

        <p x-show="deletingDivision?.accounts_count" class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <span class="font-semibold" x-text="deletingDivision?.accounts_count + ' akun'"></span>
            di divisi ini akan menjadi tanpa divisi.
        </p>

        <p x-show="formError" x-text="formError" class="mt-4 text-xs text-red-600"></p>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-center">
            <button type="button" x-ref="deleteCancel" class="h-11 rounded-xl border border-line bg-soft px-5 text-sm font-medium text-ink hover:border-brand-indigo" @click="closeModal()">Batal</button>
            <button type="button" class="h-11 rounded-xl bg-red-600 px-5 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(220,38,38,0.25)] hover:bg-red-700 disabled:opacity-60" :disabled="saving" @click="confirmDelete()" x-text="saving ? 'Menghapus…' : 'Ya, Hapus'"></button>
        </div>
    </div>
</div>
