import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/apps/admin/app.js',
                'resources/js/apps/user/user-app.js',
                'resources/js/apps/provider/provider-app.js',
            ],
            // Avoid full browser reload on every backend/locale file save during dev.
            refresh: false,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        watch: {
            ignored: [
                '**/storage/framework/views/**',
                '**/public/dashboard/**',
                '**/*.zip',
            ],
        },
    },
});
