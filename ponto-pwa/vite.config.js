import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        vue(),
        VitePWA({
            registerType: 'autoUpdate',
            includeAssets: ['favicon.svg'],
            manifest: {
                name: 'Ponto — Registro de Ponto',
                short_name: 'Ponto',
                description: 'Registre seu ponto pelo celular',
                lang: 'pt-BR',
                theme_color: '#0d6efd',
                background_color: '#ffffff',
                display: 'standalone',
                orientation: 'portrait',
                start_url: '/',
                icons: [
                    { src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any maskable' },
                ],
            },
            workbox: {
                // A marcação NUNCA é cacheada: a hora vem do servidor.
                navigateFallbackDenylist: [/^\/api/],
                runtimeCaching: [
                    { urlPattern: /\/api\//, handler: 'NetworkOnly' },
                    // Logo da empresa: o nome do arquivo muda a cada envio, então pode ficar em cache.
                    { urlPattern: /\/storage\/logos\//, handler: 'CacheFirst', options: { cacheName: 'logos', expiration: { maxEntries: 10 } } },
                ],
            },
        }),
    ],
    server: { port: 5173 },
});
