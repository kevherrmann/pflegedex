import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const hmrHost = env.VITE_HMR_HOST || 'localhost';
    const hmrPort = Number(env.VITE_PORT || 5173);

    return {
        server: {
            host: '0.0.0.0',
            port: hmrPort,
            strictPort: true,
            cors: {
                origin: [
                    'http://localhost:8080',
                    'http://127.0.0.1:8080',
                    env.APP_URL,
                ].filter(Boolean),
            },
            hmr: {
                host: hmrHost,
                port: hmrPort,
                protocol: 'ws',
            },
            origin: `http://${hmrHost}:${hmrPort}`,
        },
        plugins: [
            laravel({
                input: 'resources/js/app.tsx',
                refresh: true,
            }),
            react(),
        ],
    };
});
