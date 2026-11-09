// Tema da interface: Claro, Escuro ou Sistema (segue o aparelho).
// A escolha fica salva neste aparelho/navegador. O <head> já aplica o tema
// antes da página desenhar (sem "piscar" branco), este módulo cuida das trocas.
import { ref } from 'vue';

const KEY = 'ponto_theme';
const media = window.matchMedia('(prefers-color-scheme: dark)');

export const THEMES = [
    { key: 'light', label: 'Claro', icon: 'bi-sun' },
    { key: 'dark', label: 'Escuro', icon: 'bi-moon-stars' },
    { key: 'system', label: 'Sistema', icon: 'bi-circle-half' },
];

const read = () => {
    try {
        const v = localStorage.getItem(KEY);
        return THEMES.some((t) => t.key === v) ? v : 'system';
    } catch {
        return 'system';
    }
};

/** Preferência escolhida (light | dark | system). */
export const themePref = ref(read());
/** Tema efetivamente aplicado (light | dark). */
export const themeApplied = ref('light');

export function applyTheme() {
    const dark = themePref.value === 'dark' || (themePref.value === 'system' && media.matches);
    themeApplied.value = dark ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', themeApplied.value);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#121417' : '#0d6efd');
}

export function setTheme(key) {
    themePref.value = key;
    try {
        localStorage.setItem(KEY, key);
    } catch {
        /* navegação privada: vale só nesta sessão */
    }
    // Transição suave só durante a troca (não deixa o resto da interface "lento").
    const root = document.documentElement;
    root.classList.add('theme-switching');
    applyTheme();
    setTimeout(() => root.classList.remove('theme-switching'), 350);
}

// "Sistema": acompanha quando o aparelho muda de claro para escuro (ex.: modo noturno automático).
media.addEventListener('change', () => themePref.value === 'system' && applyTheme());
applyTheme();
