import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../css/app.css';
import { Modal } from 'bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import AppLayout from './Layouts/AppLayout.vue';

createInertiaApp({
    title: (title) => (title ? `${title} · Ponto SaaS` : 'Ponto SaaS'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (page.default.layout === undefined) {
            page.default.layout = name.startsWith('Auth/') ? null : AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: false,
});

// Modal de loading em todo fetch (padrão do projeto). Só aparece se demorar > 150 ms.
const modalEl = document.getElementById('loadingModal');
const loading = modalEl ? new Modal(modalEl) : null;
let timer = null;

router.on('start', () => {
    clearTimeout(timer);
    timer = setTimeout(() => loading?.show(), 150);
});
router.on('finish', () => {
    clearTimeout(timer);
    loading?.hide();
});
