<div class="mt-6 grid gap-4">
    <section
        x-data="promoAnalysis"
        @promo-analysis-errors="showServerErrors($event.detail)"
        class="{{ $card }} min-w-0 p-5"
        aria-labelledby="promo-analysis-title"
    >
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 id="promo-analysis-title" class="text-base font-semibold text-ink">Analisis Kode Promo</h2>
                <p class="mt-1 text-sm text-muted">Bandingkan transaksi dan revenue dengan promo dan tanpa promo berdasarkan tanggal bayar Markaz.</p>
            </div>
            <button type="button" class="inline-flex h-9 shrink-0 items-center rounded-lg px-3 text-sm font-semibold text-muted hover:bg-soft hover:text-ink" @click="resetFilter()">
                Atur Ulang
            </button>
        </div>

        @include('pages.promo.components.promo-analysis-filter')
        @include('pages.promo.components.promo-analysis-summary')
        @include('pages.promo.components.promo-analysis-trend')
        @include('pages.promo.components.promo-analysis-top-codes')
        @include('pages.promo.components.promo-analysis-funnel')
        @include('pages.promo.components.promo-analysis-source')
        @include('pages.promo.components.promo-analysis-code')
    </section>

    @include('pages.promo.components.promo-unregistered-alert')

    <section x-data="promoCodeManagement" @promo-codes-changed.window="reload()" @promo-code-register.window="openRegister($event.detail)" class="{{ $card }} min-w-0 p-5" aria-labelledby="promo-list-title">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="promo-list-title" class="text-base font-semibold text-ink">Daftar Kode Promo</h2>
                <p class="mt-1 text-sm text-muted">Petakan setiap kode promo ke platform, funnel, dan source yang tepat.</p>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2 self-start">
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-4 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    @click="$dispatch('promo-bulk-open')"
                >
                    <x-icon name="table" />
                    Input Massal
                </button>
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    @click="openCreate()"
                >Tambah Kode Promo</button>
            </div>
        </div>

        @include('pages.promo.components.promo-code-filter')
        @include('pages.promo.components.promo-code-table')

        @include('pages.promo.components.promo-code-form-modal')
        @include('pages.promo.components.promo-code-confirm-modal')
        @include('pages.promo.components.promo-code-delete-modal')
    </section>

    @include('pages.promo.components.promo-code-bulk-modal')
</div>
