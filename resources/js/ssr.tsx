import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ReactDOMServer from 'react-dom/server';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) => {
            // Resolve path to avoid case sensitivity issues on Linux servers (VPS) vs Mac
            const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
            
            // Let's try direct exact match first
            let path = `./Pages/${name}.tsx`;
            if (pages[path]) {
                return pages[path];
            }

            // Try with different casing variations if strict match fails (helpful for linux vs mac)
            const pathLower = path.toLowerCase();
            for (const p in pages) {
                if (p.toLowerCase() === pathLower) {
                    return pages[p];
                }
            }
            
            // Fallback to standard helper
            return resolvePageComponent(
                `./Pages/${name}.tsx`,
                import.meta.glob('./Pages/**/*.tsx'),
            );
        },
        setup: ({ App, props }) => {
            return <App {...props} />;
        },
    }),
);
