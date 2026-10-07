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
                // Bug fix (2026-09-29, real observed crash): `npm run dev`
                // was watching the ENTIRE repo root by default, which
                // includes androidApp/ - Gradle/Android Studio's build
                // output there (native .so libs, .class files, etc.) gets
                // locked by the build tools on Windows while a build is
                // running, and chokidar's watcher throws an uncaught
                // EBUSY error on those files that kills the whole Vite
                // dev server process outright (not just a warning). None
                // of androidApp/ is ever consumed by Vite, so it never
                // needed to be watched in the first place.
                '**/androidApp/**',
            ],
        },
    },
});
