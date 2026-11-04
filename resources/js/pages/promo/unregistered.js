import { errorMessage } from '../../http';
import { fetchUnregisteredCodes } from './api';
import { defaultFilter, toQueryString } from './filter';

const usedOn = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

function formatUsedOn(date) {
    return date ? usedOn.format(new Date(`${date}T00:00:00`)) : '—';
}

/**
 * Alert of promo codes found in Markaz transactions without a master for their platform.
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

        init() {
            this.fetchItems();
            this.$watch('filter.search', () => this.reload());
        },

        get total() {
            return this.meta?.total ?? 0;
        },

        get hasFilter() {
            return this.filter.search.trim() !== '';
        },

        /**
         * "All Registered" only when no search is applied and nothing is unregistered.
         */
        get status() {
            if (this.searched) {
                return { text: `${this.total} ${this.total === 1 ? 'Result' : 'Results'}`, tone: 'bg-soft text-label' };
            }

            return this.total > 0
                ? { text: `${this.total} Unregistered`, tone: 'bg-amber-100 text-amber-800' }
                : { text: 'All Registered', tone: 'bg-emerald-100 text-emerald-700' };
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

        register(item) {
            this.$dispatch('promo-code-register', item);
        },
    };
}
