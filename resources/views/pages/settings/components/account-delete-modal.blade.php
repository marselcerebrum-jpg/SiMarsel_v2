<div
    x-show="modal === 'delete'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="delete-user-title"
    aria-describedby="delete-user-desc"
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

        <h2 id="delete-user-title" class="mt-4 text-lg font-bold tracking-tight">Hapus Akun?</h2>
        <p id="delete-user-desc" class="mt-1.5 text-sm text-muted">
            Apakah Anda yakin ingin menghapus akun
            <strong class="font-semibold text-ink" x-text="deletingUser?.fullname"></strong>?
            Tindakan ini tidak dapat dibatalkan.
        </p>

        <p x-show="formError" x-text="formError" class="mt-4 text-xs text-red-600"></p>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-center">
            <button type="button" x-ref="deleteCancel" class="h-11 rounded-xl border border-line bg-soft px-5 text-sm font-medium text-ink hover:border-brand-indigo" @click="closeModal()">Batal</button>
            <button type="button" class="h-11 rounded-xl bg-red-600 px-5 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(220,38,38,0.25)] hover:bg-red-700 disabled:opacity-60" :disabled="saving" @click="confirmDelete()" x-text="saving ? 'Menghapus…' : 'Ya, Hapus'"></button>
        </div>
    </div>
</div>
