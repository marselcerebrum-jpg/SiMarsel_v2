{{-- Data Master tab: one subtab per list, so a long list never stretches another. --}}
@php
    $masterTabs = ['funnel' => 'Funnel', 'source' => 'Source', 'platform' => 'Platform'];
@endphp

<div x-data="promoTabs('promo-master', @js(array_keys($masterTabs)))" class="mt-6 grid min-w-0 gap-4">
    <div class="rounded-lg border border-line bg-soft px-4 py-3 text-xs text-muted">
        <p>
            <span class="font-semibold text-ink">Tanpa Kode</span>, <span class="font-semibold text-ink">Tanpa Funnel</span>, dan
            <span class="font-semibold text-ink">Tanpa Sumber</span> adalah kelompok hasil analisis sistem, sehingga tidak perlu dibuat sebagai master.
        </p>
        <p class="mt-1">Mengganti nama funnel atau source tidak mengubah riwayat penggunaan kode promo yang sudah tercatat.</p>
    </div>

    <div>
        @include('pages.promo.components.promo-subtabs', ['id' => 'promo-master', 'label' => 'Data Master', 'tabs' => $masterTabs])
    </div>

    <div id="panel-promo-master-funnel" role="tabpanel" aria-labelledby="tab-promo-master-funnel" tabindex="0" x-show="tab === 'funnel'" class="min-w-0 focus-visible:outline-none">
        @include('pages.promo.components.master-table', [
            'type' => 'funnel',
            'label' => 'Funnel',
            'description' => 'Tahap funnel tempat kode promo dibagikan.',
            'placeholder' => 'Webinar',
        ])
    </div>

    <div id="panel-promo-master-source" role="tabpanel" aria-labelledby="tab-promo-master-source" tabindex="0" x-show="tab === 'source'" x-cloak class="min-w-0 focus-visible:outline-none">
        @include('pages.promo.components.master-table', [
            'type' => 'source',
            'label' => 'Source',
            'description' => 'Sumber atau kanal asal kode promo.',
            'placeholder' => 'YouTube',
        ])
    </div>

    <div id="panel-promo-master-platform" role="tabpanel" aria-labelledby="tab-promo-master-platform" tabindex="0" x-show="tab === 'platform'" x-cloak class="min-w-0 focus-visible:outline-none">
        @include('pages.promo.components.platform-table')
    </div>
</div>
