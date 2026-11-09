import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../css/app.css';
import { Modal } from 'bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import AppLayout from './Layouts/AppLayout.vue';
import './theme';

createInertiaApp({
    title: (title) => (title ? `${title} · Ponto SaaS` : 'Ponto SaaS'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (page.default.layout === undefined) {
            page.default.layout = name.startsWith('Auth/') || name.startsWith('Public/') ? null : AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: false,
});

// Modal de loading em todo fetch (padrão do projeto). Só aparece se demorar > 150 ms.
// Com a animação "fade", o Bootstrap ignora hide() enquanto o modal ainda está abrindo;
// por isso guardamos o pedido e escondemos assim que a abertura termina.
const modalEl = document.getElementById('loadingModal');
const loading = modalEl ? new Modal(modalEl) : null;
let timer = null;
let opening = false;
let hideWhenShown = false;

modalEl?.addEventListener('show.bs.modal', () => (opening = true));
modalEl?.addEventListener('shown.bs.modal', () => {
    opening = false;
    if (hideWhenShown) {
        hideWhenShown = false;
        loading.hide();
    }
});

router.on('start', () => {
    clearTimeout(timer);
    hideWhenShown = false;
    timer = setTimeout(() => loading?.show(), 150);
});
router.on('finish', () => {
    clearTimeout(timer);
    if (opening) {
        hideWhenShown = true;
    } else {
        loading?.hide();
    }
});
