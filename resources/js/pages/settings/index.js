import Alpine from 'alpinejs';
import './accounts';
import './divisions';

const TABS = ['accounts', 'divisions'];

Alpine.data('organizationManagement', (initialTab = 'accounts') => ({
    tab: TABS.includes(initialTab) ? initialTab : TABS[0],

    select(tab) {
        if (!TABS.includes(tab)) return;

        this.tab = tab;

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState(null, '', url);
        window.dispatchEvent(new CustomEvent('organization-tab-changed', { detail: tab }));
    },

    move(step) {
        const index = (TABS.indexOf(this.tab) + step + TABS.length) % TABS.length;

        this.select(TABS[index]);
        this.$nextTick(() => document.getElementById(`tab-${this.tab}`)?.focus());
    },
}));
