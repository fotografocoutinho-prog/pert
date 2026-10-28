import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig(({ command }) => ({
    // Build com URLs relativos: os ficheiros funcionam na raiz do domínio ou numa
    // subpasta (ex.: www.coda.pt/obras/obraconnect) sem recompilar.
    base: command === 'build' ? './' : undefined,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        // Sem source maps em produção: o ZIP vai para alojamento partilhado.
        sourcemap: false,
        chunkSizeWarningLimit: 600,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
}));
