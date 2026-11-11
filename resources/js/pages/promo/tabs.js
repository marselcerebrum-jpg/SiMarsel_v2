/**
 * WAI-ARIA tabs for the promo page. `id` prefixes the tab and panel ids (`tab-{id}-{key}`, `panel-{id}-{key}`).
 * With `urlParam` the active tab is read from and kept in the query string, so a reload or link opens it again.
 * Panels use x-show, so switching tabs never clears inputs or filters.
 */
export default function promoTabs(id, keys, urlParam = null) {
    const fromUrl = urlParam ? new URLSearchParams(window.location.search).get(urlParam) : null;

    return {
        tab: keys.includes(fromUrl) ? fromUrl : keys[0],

        select(key) {
            if (!keys.includes(key)) return;

            this.tab = key;

            if (!urlParam) return;

            const url = new URL(window.location.href);
            url.searchParams.set(urlParam, key);
            window.history.replaceState(null, '', url);
        },

        /**
         * Arrow-key navigation between tabs.
         */
        move(step) {
            const index = (keys.indexOf(this.tab) + step + keys.length) % keys.length;

            this.select(keys[index]);
            this.$nextTick(() => document.getElementById(`tab-${id}-${this.tab}`)?.focus());
        },
    };
}
