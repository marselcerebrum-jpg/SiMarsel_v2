{{-- Result of the last Bulk Entry submit; failure reasons are shown in each row's cells. --}}
<div x-show="summary" x-cloak class="mt-4 grid gap-3 sm:grid-cols-[repeat(3,minmax(0,1fr))_auto] sm:items-center" aria-live="polite">
    <div class="rounded-lg border border-line px-3 py-2">
        <p class="text-xs font-semibold text-label">Processed</p>
        <p class="text-lg font-bold text-ink" x-text="summary?.total ?? 0"></p>
    </div>
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
        <p class="text-xs font-semibold text-emerald-700">Succeeded</p>
        <p class="text-lg font-bold text-emerald-700" x-text="summary?.succeeded ?? 0"></p>
    </div>
    <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2">
        <p class="text-xs font-semibold text-red-700">Failed</p>
        <p class="text-lg font-bold text-red-700" x-text="summary?.failed ?? 0"></p>
    </div>
    <button
        type="button"
        x-show="summary?.succeeded"
        :disabled="saving"
        class="text-xs font-semibold text-primary hover:text-primary-hover"
        @click="removeSavedRows()"
    >Remove Saved Rows</button>

    <p x-show="summary?.failed" class="text-xs text-muted sm:col-span-4">
        Perbaiki baris yang gagal sesuai alasan di setiap sel, lalu simpan lagi. Baris yang sudah tersimpan tidak dikirim ulang.
    </p>
</div>
