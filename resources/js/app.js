import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import './icons';
import AppLayout from './Layouts/AppLayout.vue';
import AppDialog from './Components/AppDialog.vue';

for (const key of Object.keys(localStorage)) {
    if (key.startsWith('friigo.')) {
        localStorage.setItem(`nubicta.${key.slice(7)}`, localStorage.getItem(key));
        localStorage.removeItem(key);
    }
}

createInertiaApp({
    title: (title) => {
        const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
        return title ? `${title} - ${appName}` : appName;
    },
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (!page) {
            throw new Error(`Página no encontrada: ${name}`);
        }
        if (page.default.layout === undefined && !name.startsWith('Auth/')) {
            page.default.layout = AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => [h(App, props), h(AppDialog)] })
            .use(plugin)
            .component('Icon', FontAwesomeIcon)
            .mount(el);
    },
    progress: { color: '#0275b3' },
});
