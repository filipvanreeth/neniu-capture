import { defineConfig, loadEnv } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { readFileSync } from 'node:fs';

export default defineConfig(({ mode }) => {
    const env = { ...loadEnv(mode, process.cwd(), 'VITE_'), ...process.env };
    const backendOrigin = env.VITE_BACKEND_ORIGIN;
    const devOrigin = env.VITE_DEV_ORIGIN;
    const devUrl = devOrigin ? new URL(devOrigin) : null;

    return {
        // No .env values are automatically exposed to browser JavaScript.
        envPrefix: [],
        plugins: [tailwindcss()],
        base: './',
        publicDir: false,
        server: {
            host: env.VITE_HOST || '127.0.0.1',
            origin: devOrigin,
            allowedHosts: devUrl ? [devUrl.hostname] : [],
            https: env.VITE_TLS_KEY ? {
                key: readFileSync(env.VITE_TLS_KEY),
                cert: readFileSync(env.VITE_TLS_CERT),
            } : undefined,
            hmr: devUrl ? {
                protocol: devUrl.protocol === 'https:' ? 'wss' : 'ws',
                host: devUrl.hostname,
                clientPort: Number(devUrl.port || (devUrl.protocol === 'https:' ? 443 : 80)),
            } : undefined,
            port: 5173,
            strictPort: true,
            cors: backendOrigin ? { origin: backendOrigin } : false,
        },
        build: {
            outDir: 'public/build',
            manifest: true,
            rollupOptions: {
                input: ['assets/css/app.css', 'assets/js/capture.js'],
            },
        },
    };
});
