import '../../../css/pages/auth.css';
import { showToast } from '../../components/toast';
import { http, errorMessage, validationErrors } from '../../http';

// Sama dengan Account::USERNAME_FORMAT di backend.
const USERNAME_PATTERN = /^[a-zA-Z0-9._]{4,25}$/;

function validateUsername(value) {
    if (value === '') return 'Username wajib diisi.';
    if (value.length < 4) return 'Username minimal 4 karakter.';
    if (value.length > 25) return 'Username maksimal 25 karakter.';
    if (!USERNAME_PATTERN.test(value)) return 'Username hanya boleh berisi huruf, angka, titik (.) dan underscore (_).';
    return '';
}

function initLoginForm() {
    const form = document.getElementById('login-form');
    if (!form) return;

    const username = form.querySelector('#username');
    const password = form.querySelector('#password');
    const usernameError = form.querySelector('#username-error');
    const toggle = form.querySelector('#toggle-password');
    const button = form.querySelector('#login-button');
    const alertBox = document.getElementById('login-alert');

    let usernameTouched = false;
    let submitting = false;

    const showAlert = (message) => {
        alertBox.textContent = message;
        alertBox.hidden = false;
    };

    const hideAlert = () => {
        alertBox.hidden = true;
        alertBox.textContent = '';
    };

    const renderUsernameState = () => {
        const error = validateUsername(username.value.trim());
        const showError = usernameTouched && error !== '';

        usernameError.textContent = showError ? error : '';
        usernameError.hidden = !showError;
        username.classList.toggle('is-invalid', showError);
        username.classList.toggle('is-valid', error === '');
        username.setAttribute('aria-invalid', showError ? 'true' : 'false');

        return error === '';
    };

    const updateButtonState = () => {
        const usernameValid = validateUsername(username.value.trim()) === '';
        button.disabled = submitting || !usernameValid || password.value === '';
    };

    const setLoading = (loading) => {
        submitting = loading;
        button.classList.toggle('is-loading', loading);
        button.querySelector('.auth-button-text').textContent = loading ? 'Memproses...' : 'Masuk';
        username.readOnly = loading;
        password.readOnly = loading;
        updateButtonState();
    };

    username.addEventListener('input', () => {
        if (username.value !== '') usernameTouched = true;
        hideAlert();
        renderUsernameState();
        updateButtonState();
    });

    username.addEventListener('blur', () => {
        usernameTouched = true;
        renderUsernameState();
    });

    password.addEventListener('input', () => {
        hideAlert();
        updateButtonState();
    });

    toggle.addEventListener('click', () => {
        const show = password.type === 'password';
        password.type = show ? 'text' : 'password';
        toggle.classList.toggle('is-visible', show);
        toggle.setAttribute('aria-pressed', String(show));
        toggle.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        usernameTouched = true;
        if (!renderUsernameState() || password.value === '' || submitting) return;

        hideAlert();
        setLoading(true);

        try {
            await http(form.action, {
                method: 'POST',
                body: { username: username.value.trim(), password: password.value },
            });

            showToast({ title: 'Login berhasil', text: 'Mengalihkan ke dashboard…' });
            setTimeout(() => {
                window.location.href = form.dataset.redirect;
            }, 800);
            return;
        } catch (error) {
            const errors = validationErrors(error);
            showAlert(errors.username || errors.password || errorMessage(error));
        }

        setLoading(false);
        password.value = '';
        password.focus();
        updateButtonState();
    });

    updateButtonState();
}

document.addEventListener('DOMContentLoaded', initLoginForm);
