import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const root = new URL('../../../resources/js/pages/performance/', import.meta.url);

/**
 * Loads a module from resources/js/pages/performance with its relative imports.
 * Only relative imports are allowed, so the module stays free of Alpine, Chart.js and fetch().
 * `globals` adds browser stand-ins (window, document) for modules that read them.
 */
export async function loadPerformanceModule(path, globals = {}) {
    const context = vm.createContext({ Intl, Date, Number, Math, String, Object, RegExp, URLSearchParams, ...globals });
    const cache = new Map();

    async function load(url) {
        if (cache.has(url.href)) return cache.get(url.href);

        const module = new vm.SourceTextModule(await readFile(url, 'utf8'), { context, identifier: url.href });
        cache.set(url.href, module);

        return module;
    }

    const module = await load(new URL(path, root));
    await module.link((specifier, referencing) => {
        if (!specifier.startsWith('.')) throw new Error(`Unexpected import: ${specifier}`);

        return load(new URL(`${specifier}.js`, referencing.identifier));
    });
    await module.evaluate();

    return module.namespace;
}

// Values built inside the vm context have another prototype; compare them as plain data.
export const plain = (value) => JSON.parse(JSON.stringify(value));
