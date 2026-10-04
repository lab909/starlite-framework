// Browser helpers for page modules that work alongside Datastar. Import them through the alias the
// Vite plugin sets up:
//
//   import { persist, publicConfig, ready } from 'starlite';
//   import { effect, getPath, mergePatch } from 'datastar';
//
// The pattern: Datastar drives the UI through signals; a plain module does the heavy work (audio,
// canvas, maps…), reading signals inside effect() and writing results back with mergePatch().
import { effect, filtered, mergePatch } from 'datastar';

/**
 * Resolves once Datastar has applied the page's data-* attributes (its `datastar-ready` event).
 * Modules run before that, so `data-signals` defaults aren't set yet when a module starts: wait for
 * this before reading signals, or before writing ones the HTML also declares.
 *
 * This module is imported statically right after Datastar, so the listener is always in place before
 * the event. Imported later (a dynamic import after the page loaded), it resolves straight away.
 */
export const ready = new Promise((resolve) => {
    document.addEventListener('datastar-ready', () => resolve(), { once: true });
    if (document.readyState === 'complete') {
        setTimeout(resolve);
    }
});

/**
 * Keeps signals in localStorage: restores them over the page's defaults, then saves every change.
 * Only the listed signals are restored and saved, whatever the storage holds.
 *
 *   persist(['_mixer.volume', 'theme'])                    // a signal and all its children
 *   persist(/^_mixer\./, { key: 'mixer' })                 // or a pattern on the signal path
 *
 * Give each set its own `key` when several modules persist signals. Storage can be unavailable
 * (private mode, quota, blocked cookies): the page then simply starts from its defaults.
 *
 * @param {string[]|RegExp} signals signal paths, or a RegExp tested against dotted paths
 * @param {{key?: string, storage?: Storage}} [options]
 * @returns {Promise<() => void>} resolves to a function that stops saving
 */
export async function persist(signals, { key = 'starlite', storage = globalThis.localStorage } = {}) {
    const include = signals instanceof RegExp ? signals : pathPattern(signals);
    await ready;

    const saved = read(storage, key);
    if (saved !== null) {
        const patch = pick(saved, include);
        if (Object.keys(patch).length > 0) {
            mergePatch(patch);
        }
    }

    return effect(() => {
        const values = filtered({ include });
        try {
            storage.setItem(key, JSON.stringify(values));
        } catch {
            // Storage full or unavailable: nothing to save to.
        }
    });
}

/**
 * The values allowlisted under `public` in config/app.php, printed by `{{ public_config() }}`.
 * Never put secrets there: everything in it is readable by anyone who loads the page.
 *
 * @returns {Record<string, unknown>}
 */
export function publicConfig() {
    const element = document.getElementById('starlite-config');
    if (element === null) {
        return {};
    }
    try {
        return JSON.parse(element.textContent ?? '{}');
    } catch {
        return {};
    }
}

/** ['_mixer.volume', 'theme'] → /^(_mixer\.volume|theme)(\.|$)/: each path plus everything under it. */
function pathPattern(paths) {
    const escaped = paths.map((path) => path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

    return new RegExp(`^(${escaped.join('|')})(\\.|$)`);
}

function read(storage, key) {
    try {
        const value = JSON.parse(storage?.getItem(key) ?? 'null');

        return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null;
    } catch {
        return null;
    }
}

/** Keeps only the leaves of `object` whose dotted path matches `include`. */
function pick(object, include, prefix = '') {
    const result = {};
    for (const [name, value] of Object.entries(object)) {
        const path = prefix + name;
        if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
            const children = pick(value, include, path + '.');
            if (Object.keys(children).length > 0) {
                result[name] = children;
            }
        } else if (include.test(path)) {
            result[name] = value;
        }
    }

    return result;
}
