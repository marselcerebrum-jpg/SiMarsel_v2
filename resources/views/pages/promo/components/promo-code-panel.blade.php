<div class="mt-6 grid gap-4">
    <section class="{{ $card }} p-5" aria-labelledby="promo-analysis-title">
        <h2 id="promo-analysis-title" class="text-base font-semibold text-ink">Promo Code Analysis</h2>
        <p class="mt-1 text-sm text-muted">Analisis penggunaan kode promo berdasarkan funnel, source, dan platform.</p>

        <div class="mt-4 rounded-lg border border-dashed border-line px-4 py-10 text-center">
            <p class="text-sm font-medium text-ink">Analisis kode promo belum tersedia.</p>
            <p class="mt-1 text-xs text-muted">
                Transaksi tanpa kode promo, funnel, atau source akan dikelompokkan otomatis oleh sistem sebagai
                Tanpa Kode, Tanpa Funnel, dan Tanpa Sumber.
            </p>
        </div>
    </section>

    <section x-data="promoCodeManagement" @promo-codes-changed.window="reload()" class="{{ $card }} min-w-0 p-5" aria-labelledby="promo-list-title">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="promo-list-title" class="text-base font-semibold text-ink">Promo Code List</h2>
                <p class="mt-1 text-sm text-muted">Petakan setiap kode promo ke platform, funnel, dan source yang tepat.</p>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2 self-start">
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-4 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    @click="$dispatch('promo-bulk-open')"
                >
                    <x-icon name="table" />
                    Bulk Entry
                </button>
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    @click="openCreate()"
                >Add Promo Code</button>
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
