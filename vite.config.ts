import preact from '@preact/preset-vite';
import { defineConfig } from 'vitest/config';

// One IIFE script and one stylesheet: the PHP side inlines both into the page, so nothing has to be published.
export default defineConfig({
    plugins: [preact()],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        cssCodeSplit: false,
        assetsInlineLimit: 1_000_000,
        target: 'es2020',
        rollupOptions: {
            input: 'resources/js/main.tsx',
            output: {
                format: 'iife',
                inlineDynamicImports: true,
                entryFileNames: 'trace.js',
                assetFileNames: 'trace[extname]',
            },
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
});
