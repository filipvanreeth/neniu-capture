import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { readFileSync } from 'node:fs';

const backendOrigin = process.env.VITE_BACKEND_ORIGIN;
const devOrigin = process.env.VITE_DEV_ORIGIN;
const devUrl = devOrigin ? new URL(devOrigin) : null;

export default defineConfig({
    plugins: [tailwindcss()],
    base: './',
    publicDir: false,
    server: {
        host: process.env.VITE_HOST || '127.0.0.1',
        origin: devOrigin,
        allowedHosts: devUrl ? [devUrl.hostname] : [],
        https: process.env.VITE_TLS_KEY ? {
            key: readFileSync(process.env.VITE_TLS_KEY),
            cert: readFileSync(process.env.VITE_TLS_CERT),
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
});
