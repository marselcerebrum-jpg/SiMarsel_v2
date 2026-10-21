{{-- Keterangan halaman saat API Tasklist belum tersedia (VITE_TASK_API_READY bukan "true"). --}}
<div x-show="status === 'unavailable'" x-cloak class="mt-6 flex gap-3 rounded-panel border border-amber-200 bg-amber-50 p-4" role="status">
    <x-icon name="clipboard" class="mt-0.5 size-5 text-amber-700" />
    <div class="text-sm">
        <p class="font-semibold text-amber-900">Menunggu pengerjaan Back End</p>
        <p class="mt-1 text-amber-800">
            Tampilan Tasklist sudah siap, tetapi API tugas belum tersedia. Board, Table, ringkasan, Timeline, Calendar,
            detail tugas, penyimpanan tugas, PIC, evidence, review, dan histori akan menampilkan data otomatis setelah
            API backend terhubung. Pilihan divisi dan platform sudah memakai data yang tersedia.
        </p>
    </div>
</div>
