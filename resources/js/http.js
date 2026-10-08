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

export function errorMessage(error, fallback = 'Terjadi kesalahan. Silakan coba lagi.') {
    if (error?.status === 419) return 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    if (error?.status === 429) return 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.';

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
