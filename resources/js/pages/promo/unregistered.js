import { errorMessage } from '../../http';
import { fetchUnregisteredCodes } from './api';
import { defaultFilter, toQueryString } from './filter';

const usedOn = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });

function formatUsedOn(date) {
    return date ? usedOn.format(new Date(`${date}T00:00:00`)) : '—';
}

/**
 * Alert of promo codes found in Markaz transactions without a master for their platform. The alert only
 * shows while such codes exist; "Lihat kode" opens their review cards.
 */
export default function promoUnregisteredCodes() {
    return {
        items: [],
        meta: null,
        filter: defaultFilter(),
        loading: true,
        loadError: '',
        requestId: 0,
        // Whether the loaded result is a search result, so the badge does not change before the response arrives.
        searched: false,
        // Codes still to review without a search; null until the first unfiltered response.
        pendingTotal: null,
        reviewOpen: false,

        init() {
            this.fetchItems();
            this.$watch('filter.search', () => this.reload());
        },

        get total() {
            return this.meta?.total ?? 0;
        },

        /**
         * A search stays visible while it is in use, even when it hides every code.
         */
        get visible() {
            return this.pendingTotal > 0 || (this.reviewOpen && this.hasFilter);
        },

        get hasFilter() {
            return this.filter.search.trim() !== '';
        },

        /**
         * "Semua Terdaftar" only when no search is applied and nothing is unregistered.
         */
        get status() {
            if (this.searched) {
                return { text: `${this.total} Hasil`, tone: 'bg-soft text-label' };
            }

            return this.total > 0
                ? { text: `${this.total} Belum Terdaftar`, tone: 'bg-amber-100 text-amber-800' }
                : { text: 'Semua Terdaftar', tone: 'bg-emerald-100 text-emerald-700' };
        },

        formatUsedOn,

        async fetchItems() {
            const requestId = ++this.requestId;
            const searched = this.hasFilter;
            this.loading = true;
            this.loadError = '';

            try {
                const { data, meta } = await fetchUnregisteredCodes(toQueryString(this.filter));
                if (requestId !== this.requestId) return;

                // The last code of a page was registered: step back to the previous page.
                if (data.length === 0 && this.filter.page > 1) {
                    this.filter.page = meta?.last_page || 1;
                    return this.fetchItems();
                }

                this.items = data;
                this.meta = meta ?? null;
                this.searched = searched;
                if (!searched) this.pendingTotal = meta?.total ?? data.length;
            } catch (error) {
                if (requestId !== this.requestId) return;
                this.loadError = errorMessage(error, 'Gagal memuat kode promo yang belum terdaftar.');
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },

        reload() {
            this.filter.page = 1;
            this.fetchItems();
        },

        refresh() {
            this.fetchItems();
        },

        goToPage(page) {
            if (!this.meta || page < 1 || page > this.meta.last_page || page === this.filter.page) return;

            this.filter.page = page;
            this.fetchItems();
        },

        toggleReview() {
            this.reviewOpen = !this.reviewOpen;
            if (this.reviewOpen) this.$nextTick(() => this.$refs.review?.focus());
        },

        register(item) {
            this.$dispatch('promo-code-register', item);
        },
    };
}
