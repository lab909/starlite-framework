// Starlite's Vite integration. Lives in the framework so fixes reach every clone; the app's
// vite.config.js only lists its plugins and entry points:
//
//   import starlite from './vendor/starlite/framework/resources/vite/starlite.js';
//   export default defineConfig({ plugins: [tailwindcss(), starlite({ input: ['resources/js/app.js'] })] });
//
// It provides:
// - build: output to public/build with a manifest (read by lib/src/Vite.php) and source maps
// - dev server: DDEV-aware origin/CORS on port 5173, and var/vite.hot so PHP points pages at it
// - full page reloads when Twig templates, Markdown content or translations change
// - envPrefix VITE_PUBLIC_: only variables named like that are ever inlined into the bundle
//
// Everything set here is a default: the same keys in vite.config.js win.
import fs from 'node:fs';
import path from 'node:path';

const HOT_FILE = 'var/vite.hot';

/**
 * @param {object}   options
 * @param {string[]} [options.input]  entry points, e.g. ['resources/js/app.js', 'resources/js/pages/mixer.js']
 * @param {RegExp[]} [options.reload] extra file patterns that should reload the page in development
 */
export default function starlite({ input = ['resources/js/app.js'], reload = [] } = {}) {
    // In DDEV the dev server is exposed on https://<project>.ddev.site:5173 (see .ddev/config.yaml).
    const origin = process.env.DDEV_PRIMARY_URL ? `${process.env.DDEV_PRIMARY_URL}:5173` : 'http://localhost:5173';
    const reloadPatterns = [/\.(twig|md)$/, /\/translations\/[^/]+\.php$/, ...reload];

    return [
        {
            name: 'starlite:config',
            config(user) {
                const server = user.server ?? {};
                const build = user.build ?? {};

                return {
                    publicDir: user.publicDir ?? false,
                    envPrefix: user.envPrefix ?? 'VITE_PUBLIC_',
                    build: {
                        outDir: build.outDir ?? 'public/build',
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
                        watch: { ignored: ['vendor', 'var', '.ddev', 'node_modules'].map((dir) => path.resolve(dir) + '/**') },
                    },
                };
            },
        },
        {
            name: 'starlite:serve',
            apply: 'serve',
            configureServer(server) {
                // Tells PHP (lib/src/Vite.php, debug mode only) where the dev server is.
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
                ['change', 'add', 'unlink'].forEach((event) => server.watcher.on(event, onFile));
            },
        },
    ];
}
