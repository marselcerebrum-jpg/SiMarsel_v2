import { http } from '../http';

/**
 * Endpoint backend sudah tersedia. Ubah ke false hanya untuk menampilkan kondisi
 * "Data belum tersedia" tanpa memanggil API. Rumus lengkap ada di docs/api/products.md.
 *
 * Kontrak API. Semua endpoint data menerima query
 * platform={slug}&year={yyyy}&month={1-12}; platform dan month opsional (kosong = semua).
 *
 * GET /api/products/filters
 *   → { data: { platforms: [{ slug, name }], years: [2026, 2025] } }
 *
 * GET /api/products/top-packages/revenue
 *   → { data: [{ name, active_period, revenue }] }        urut revenue tertinggi
 *
 * GET /api/products/top-packages/volume
 *   → { data: [{ name, active_period, transactions }] }   urut transactions tertinggi
 *
 * GET /api/products/performance
 *   → { data: [{ name, revenue, trx, low_price, low_trx, avg_price, avg_trx, high_price, high_trx }] }
 */
export const PRODUCT_API_READY = true;

function query(params) {
    const entries = Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined);

    return new URLSearchParams(entries).toString();
}

function get(path, filters) {
    if (!PRODUCT_API_READY) return Promise.reject({ unavailable: true });

    return http(filters ? `${path}?${query(filters)}` : path);
}

export const fetchProductFilters = () => get('/api/products/filters');

export const fetchTopPackagesByRevenue = (filters) => get('/api/products/top-packages/revenue', filters);

export const fetchTopPackagesByVolume = (filters) => get('/api/products/top-packages/volume', filters);

export const fetchPackagePerformance = (filters) => get('/api/products/performance', filters);
