import Alpine from 'alpinejs';
import session from './stores/session';
import sidebar from './components/sidebar';

window.Alpine = Alpine;

Alpine.store('session', session);
Alpine.data('sidebar', sidebar);

// Start after DOMContentLoaded so page modules can register Alpine.data() first.
document.addEventListener('DOMContentLoaded', () => Alpine.start());
