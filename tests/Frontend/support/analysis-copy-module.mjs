import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = (path) => readFile(new URL(`../../../resources/js/${path}`, import.meta.url), 'utf8');

/**
 * The real analysis-copy.js (with export.js and http.js) in a test's vm context, for components that answer Copy.
 */
export async function analysisCopyModule(context) {
    // http.js reads the CSRF meta tag when it loads.
    if (!('document' in context)) context.document = { querySelector: () => null };
    if (!('URLSearchParams' in context)) context.URLSearchParams = URLSearchParams;

    const exporter = new vm.SourceTextModule(await source('export.js'), { context });
    const http = new vm.SourceTextModule(await source('http.js'), { context });
    const copy = new vm.SourceTextModule(await source('pages/promo/analysis-copy.js'), { context });

    await copy.link((specifier) => {
        if (specifier === '../../export') return exporter;
        if (specifier === '../../http') return http;
        throw new Error(`Unexpected import ${specifier}`);
    });

    return copy;
}
