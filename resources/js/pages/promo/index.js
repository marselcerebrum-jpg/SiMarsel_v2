import Alpine from 'alpinejs';
import { promoMasterTable, promoPlatformTable } from './table';

const TABS = ['promo-codes', 'master-data'];

function tabFromUrl() {
    const tab = new URLSearchParams(window.location.search).get('tab');

    return TABS.includes(tab) ? tab : TABS[0];
}

Alpine.data('promoManagement', () => ({
    tab: tabFromUrl(),

    select(tab) {
        if (!TABS.includes(tab)) return;

        this.tab = tab;

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState(null, '', url);
    },

    /**
     * Arrow-key navigation between tabs (WAI-ARIA tabs pattern).
     */
    move(step) {
        const index = (TABS.indexOf(this.tab) + step + TABS.length) % TABS.length;

        this.select(TABS[index]);
        this.$nextTick(() => document.getElementById(`tab-${this.tab}`)?.focus());
    },
}));

Alpine.data('promoMasterTable', promoMasterTable);
Alpine.data('promoPlatformTable', promoPlatformTable);
