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

    <section class="{{ $card }} p-5" aria-labelledby="promo-list-title">
        <h2 id="promo-list-title" class="text-base font-semibold text-ink">Promo Code List</h2>
        <p class="mt-1 text-sm text-muted">Kelola kode promo beserta funnel, source, dan platform-nya.</p>

        <div class="mt-4 rounded-lg border border-dashed border-line px-4 py-10 text-center">
            <p class="text-sm font-medium text-ink">Pengelolaan kode promo belum tersedia.</p>
            <p class="mt-1 text-xs text-muted">Siapkan funnel dan source terlebih dahulu di tab Master Data.</p>
            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="select('master-data')">Open Master Data</button>
        </div>
    </section>
</div>
