import '../css/app.css';

import { SonnerToaster } from '@/components/ui/sonner';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from '@/hooks/use-appearance';
import { configureEcho } from '@laravel/echo-react';

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Mibeko';

createInertiaApp({
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
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props} />
                <SonnerToaster />
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

