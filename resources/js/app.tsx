import './bootstrap';
import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DEFAULT_APP_NAME, formatPageTitle } from '@/Utilities/title';

const appName = import.meta.env.VITE_APP_NAME || DEFAULT_APP_NAME;

void createInertiaApp({
    title: (title) => formatPageTitle(title, appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>([
                './Pages/**/*.tsx',
                '!./Pages/**/*.{test,spec}.tsx',
            ]),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(
            <StrictMode>
                <App {...props} />
            </StrictMode>,
        );
    },
    progress: {
        color: '#0d9488',
    },
});
