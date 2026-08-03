import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import inertia from '@inertiajs/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    // Let <img src> and friends resolve through Vite so the
                    // build hashes/bundles the referenced assets.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        inertia({ ssr: false }),
    ],
    resolve: {
        alias: {
            // `@` → resources/js, matching the kpn-coi import style
            // (e.g. `@/Components/Layout/Sidebar.vue`).
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
