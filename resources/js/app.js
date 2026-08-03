import '../css/app.css'
import './bootstrap'

import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

import '@fortawesome/fontawesome-free/css/all.min.css'

// Taken from the server (a <meta> tag) rather than a VITE_ var: VITE_ vars are
// baked in at build time, so whoever ran the build would decide the name and an
// environment that disagrees would ship a wrong title silently.
const appName =
    document.querySelector('meta[name="app-name"]')?.getAttribute('content') ||
    'KPN'

createInertiaApp({
    // Without this, Inertia clears the server-rendered `<title>` on hydration
    // for any page that sets no title of its own, leaving the tab blank.
    title: (title) => (title ? `${title} - ${appName}` : appName),

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },

    progress: {
        color: '#ab2f2b',
    },
})
