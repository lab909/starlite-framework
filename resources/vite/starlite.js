// Starlite's Vite integration. Lives in the framework so fixes reach every clone; the app's
// vite.config.js only lists its plugins and entry points:
//
//   import starlite from './vendor/starlite/framework/resources/vite/starlite.js';
//   export default defineConfig({ plugins: [tailwindcss(), starlite()] });
//
// It provides:
// - entry points: resources/js/app.js plus every resources/js/pages/*.js (one bundle per page or feature)
// - import aliases: 'datastar' (the Datastar client shipped with the framework, matching its PHP SDK)
//   and 'starlite' (browser helpers: ready, persist, publicConfig; see resources/js/starlite.js)
// - build: output to public/build with a manifest (read by src/Vite.php) and source maps
// - dev server: DDEV-aware origin/CORS on port 5173, and var/vite.hot so PHP points pages at it
// - full page reloads when Twig templates, Markdown content or translations change, and a restart
//   when SVGs in resources/icons/ change (custom icons for the Iconify Tailwind plugin)
// - envPrefix VITE_PUBLIC_: only variables named like that are ever inlined into the bundle
//
// Everything set here is a default: the same keys in vite.config.js win.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HOT_FILE = 'var/vite.hot';
const PAGES_DIR = 'resources/js/pages';
const ICONS_DIR = 'resources/icons';
const FRAMEWORK_JS = fileURLToPath(new URL('../js/', import.meta.url));

/** resources/js/app.js plus resources/js/pages/*.js, so a new page bundle needs no config change. */
function defaultInput() {
    const pages = fs.existsSync(PAGES_DIR)
        ? fs.readdirSync(PAGES_DIR).filter((file) => /\.[jt]s$/.test(file)).sort().map((file) => `${PAGES_DIR}/${file}`)
        : [];

    return ['resources/js/app.js', ...pages];
}

/**
 * @param {object}   options
 * @param {string[]} [options.input]  entry points; default: resources/js/app.js and resources/js/pages/*.js
 * @param {RegExp[]} [options.reload] extra file patterns that should reload the page in development
 */
export default function starlite({ input = defaultInput(), reload = [] } = {}) {
    // In DDEV the dev server is exposed on https://<project>.ddev.site:5173 (see .ddev/config.yaml).
    const origin = process.env.DDEV_PRIMARY_URL ? `${process.env.DDEV_PRIMARY_URL}:5173` : 'http://localhost:5173';
    const reloadPatterns = [/\.(twig|md)$/, /\/translations\/[^/]+\.php$/, ...reload];

    return [
        {
            name: 'starlite:config',
            config(user, { command }) {
                const server = user.server ?? {};
                const build = user.build ?? {};
                const outDir = build.outDir ?? 'public/build';

                return {
                    // Built files are served from /build/ (the outDir inside public/): URLs inside the
                    // bundles, such as fonts and images referenced from CSS, must include it. The dev
                    // server serves from its own root.
                    base: user.base ?? (command === 'build' ? '/' + path.relative('public', outDir).split(path.sep).join('/') + '/' : '/'),
                    // Both resolve to one file each, so every bundle shares a single Datastar instance
                    // (and its signals). The app's own resolve.alias entries are merged in by Vite.
                    resolve: {
                        alias: {
                            datastar: FRAMEWORK_JS + 'datastar.js',
                            starlite: FRAMEWORK_JS + 'starlite.js',
                        },
                    },
                    publicDir: user.publicDir ?? false,
                    envPrefix: user.envPrefix ?? 'VITE_PUBLIC_',
                    build: {
                        outDir,
                        emptyOutDir: build.emptyOutDir ?? true,
                        manifest: build.manifest ?? true,
                        sourcemap: build.sourcemap ?? true,
                        rolldownOptions: { input: build.rolldownOptions?.input ?? input },
                    },
                    server: {
                        host: server.host ?? '0.0.0.0',
                        port: server.port ?? 5173,
                        strictPort: server.strictPort ?? true,
                        origin: server.origin ?? origin,
                        cors: server.cors ?? { origin: process.env.DDEV_PRIMARY_URL ?? /^https?:\/\/localhost(:\d+)?$/ },
                        // Anchored to the project root: a bare '**/var/**' would also match the root itself
                        // (/var/www/html) and silently stop all file watching. Merged with the app's own list.
                        // docs/ is the maintainers' clone of the documentation: a separate VitePress site.
                        watch: { ignored: ['vendor', 'var', '.ddev', 'node_modules', 'docs'].map((dir) => path.resolve(dir) + '/**') },
                    },
                };
            },
        },
        {
            name: 'starlite:serve',
            apply: 'serve',
            configureServer(server) {
                // Tells PHP (src/Vite.php, debug mode only) where the dev server is.
                server.httpServer?.once('listening', () => fs.writeFileSync(HOT_FILE, server.config.server.origin ?? origin));
                const removeHotFile = () => fs.rmSync(HOT_FILE, { force: true });
                server.httpServer?.once('close', removeHotFile);
                process.on('exit', removeHotFile);
                ['SIGINT', 'SIGTERM', 'SIGHUP'].forEach((signal) => process.on(signal, () => process.exit()));

                // Templates, content and translations are not JS modules, so Vite has no HMR for them:
                // reload the page (CSS and JS are hot-updated by Vite itself). add/unlink too, so new and
                // deleted files also refresh the page.
                const onFile = (file) => {
                    if (reloadPatterns.some((pattern) => pattern.test(file))) {
                        server.ws.send({ type: 'full-reload' });
                    }
                };
                // The Iconify plugin reads resources/icons/ once, at startup: restart Vite to pick up
                // added, changed or removed SVGs (the page then reloads by itself).
                const iconsDir = path.resolve(ICONS_DIR) + path.sep;
                ['change', 'add', 'unlink'].forEach((event) => server.watcher.on(event, (file) => {
                    if (file.startsWith(iconsDir) && file.endsWith('.svg')) {
                        server.restart();
                    }
                }));
                ['change', 'add', 'unlink'].forEach((event) => server.watcher.on(event, onFile));
            },
        },
    ];
}
