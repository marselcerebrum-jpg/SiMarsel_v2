import { showToast } from './toast';
import { http, errorMessage } from '../http';

export function initLogout(selector = '[data-logout]') {
    document.querySelectorAll(selector).forEach((link) => {
        let leaving = false;

        link.addEventListener('click', async (event) => {
            event.preventDefault();
            if (leaving) return;

            leaving = true;
            link.setAttribute('aria-disabled', 'true');

            try {
                await http('/api/logout', { method: 'POST' });
            } catch (error) {
                // 401 berarti sesi sudah berakhir; tetap arahkan ke halaman login.
                if (error.status !== 401) {
                    leaving = false;
                    link.removeAttribute('aria-disabled');
                    showToast({ title: 'Logout Failed', text: errorMessage(error) });
                    return;
                }
            }

            showToast({ title: 'Logged Out', text: 'Mengalihkan ke halaman login…', duration: 0 });

            setTimeout(() => {
                window.location.href = link.href;
            }, 800);
        });
    });
}
