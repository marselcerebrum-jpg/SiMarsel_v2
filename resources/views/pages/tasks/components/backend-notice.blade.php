{{-- Keterangan halaman saat API Tasklist belum tersedia (VITE_TASK_API_READY bukan "true"). --}}
<div x-show="status === 'unavailable'" x-cloak class="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900" role="status">
    <x-icon name="clipboard" class="mt-px size-4 shrink-0 text-amber-700" />
    <p>
        <span class="font-semibold">Menunggu pengerjaan Back End.</span>
        Tampilan sudah siap; data tugas, ringkasan, jadwal, dan aksi tugas akan tampil otomatis setelah API Tasklist tersedia.
        Pilihan divisi dan platform sudah memakai data yang ada.
    </p>
</div>
