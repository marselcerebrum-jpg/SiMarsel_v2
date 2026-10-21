{{-- Keterangan kecil untuk bagian yang menunggu backend. Variabel: $text (opsional). --}}
<div class="flex items-start gap-2 rounded-lg border border-dashed border-amber-300 bg-amber-50/70 px-3 py-2.5 text-xs text-amber-900" role="status">
    <x-icon name="clipboard" class="mt-px size-3.5 shrink-0 text-amber-700" />
    <span><span class="font-semibold">Menunggu Back End.</span> {{ $text ?? 'Data akan tampil otomatis setelah API backend terhubung.' }}</span>
</div>
