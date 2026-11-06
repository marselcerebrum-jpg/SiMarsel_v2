const token = document.querySelector('meta[name="csrf-token"]')?.content;

export async function http(url, { method = 'GET', body } = {}) {
    const response = await fetch(url, {
        method,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw { status: response.status, data };
    }

    return data;
}

/**
 * File name from a Content-Disposition header, e.g. `attachment; filename=report.xlsx`.
 */
export function dispositionFileName(disposition) {
    const encoded = /filename\*=UTF-8''([^;]+)/i.exec(disposition ?? '');
    if (encoded) return decodeURIComponent(encoded[1]);

    return /filename="?([^";]+)"?/i.exec(disposition ?? '')?.[1]?.trim() || null;
}

/**
 * GET a file download. Success resolves { blob, fileName }; failures throw { status, data } like http(),
 * since the backend answers errors as JSON.
 */
export async function httpBlob(url) {
    const response = await fetch(url, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
        },
    });

    if (!response.ok) {
        throw { status: response.status, data: await response.json().catch(() => ({})) };
    }

    return {
        blob: await response.blob(),
        fileName: dispositionFileName(response.headers.get('Content-Disposition')),
    };
}

export function errorMessage(error, fallback = 'Terjadi kesalahan. Silakan coba lagi.') {
    if (error?.status === 419) return 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    if (error?.status === 429) return 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.';
    // Server errors may carry technical details (SQL, stack) when APP_DEBUG is on; never show them.
    if (error?.status >= 500) return fallback;

    return error?.data?.message || fallback;
}

/**
 * Flatten Laravel's 422 payload ({ field: [messages] }) into { field: firstMessage }.
 */
export function validationErrors(error) {
    return Object.fromEntries(
        Object.entries(error?.data?.errors ?? {}).map(([field, messages]) => [field, messages[0]]),
    );
}
