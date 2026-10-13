import { showToast } from './toast';
import { initLogout } from './logout';

export default () => ({
    open: false,

    init() {
        initLogout();

        // Tutup drawer mobile saat layar dilebarkan ke ukuran desktop.
        window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
            if (event.matches) this.open = false;
        });
    },

    soon(label) {
        showToast({ type: 'info', title: 'Fitur belum tersedia', text: `Menu "${label}" belum dibuat.` });
    },

    locked(label) {
        showToast({ type: 'info', title: 'Akses terbatas', text: `Anda tidak memiliki akses ke menu "${label}".` });
    },
});
