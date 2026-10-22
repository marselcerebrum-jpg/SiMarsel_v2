<div class="mt-6 rounded-lg border border-line bg-soft px-4 py-3 text-xs text-muted">
    <p>
        <span class="font-semibold text-ink">Tanpa Kode</span>, <span class="font-semibold text-ink">Tanpa Funnel</span>, dan
        <span class="font-semibold text-ink">Tanpa Sumber</span> adalah kelompok hasil analisis sistem, sehingga tidak perlu dibuat sebagai master.
    </p>
    <p class="mt-1">Mengganti nama funnel atau source tidak mengubah riwayat penggunaan kode promo yang sudah tercatat.</p>
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-2">
    @include('pages.promo.components.master-table', [
        'type' => 'funnel',
        'label' => 'Funnel',
        'description' => 'Tahap funnel tempat kode promo dibagikan.',
        'placeholder' => 'Webinar',
    ])

    @include('pages.promo.components.master-table', [
        'type' => 'source',
        'label' => 'Source',
        'description' => 'Sumber atau kanal asal kode promo.',
        'placeholder' => 'YouTube',
    ])
</div>

<div class="mt-4">
    @include('pages.promo.components.platform-table')
</div>
