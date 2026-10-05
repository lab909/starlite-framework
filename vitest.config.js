import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Tests for resources/js (the 'starlite' helpers) against the real Datastar client, in a simulated DOM.
// Each test file runs in its own environment: Datastar keeps its signals in module-level state.
export default defineConfig({
    resolve: {
        alias: { datastar: fileURLToPath(new URL('./resources/js/datastar.js', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
    },
});
