import '../../css/components/toast.css';

const ICONS = {
    success: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>',
    info: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>',
};

function getStack() {
    let stack = document.querySelector('.toast-stack');

    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
    }

    return stack;
}

export function showToast({ title, text = '', duration = 3500, type = 'success' }) {
    const toast = document.createElement('div');
    toast.className = type === 'info' ? 'toast toast-info' : 'toast';

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.innerHTML = ICONS[type] ?? ICONS.success;

    const body = document.createElement('div');
    const titleEl = document.createElement('p');
    titleEl.className = 'toast-title';
    titleEl.textContent = title;
    body.appendChild(titleEl);

    if (text) {
        const textEl = document.createElement('p');
        textEl.className = 'toast-text';
        textEl.textContent = text;
        body.appendChild(textEl);
    }

    toast.append(icon, body);
    getStack().appendChild(toast);

    if (duration > 0) {
        setTimeout(() => toast.remove(), duration);
    }

    return toast;
}
