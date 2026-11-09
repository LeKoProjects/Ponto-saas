// Dados do colaborador guardados no aparelho (id, nome, CPF, empresa, jornada, regra de GPS).
// A tela de ponto abre na hora com eles e o /me só atualiza em segundo plano.
const PROFILE_KEY = 'ponto_profile';
const TODAY_KEY = 'ponto_today';

const read = (key) => {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
};
const write = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        /* armazenamento indisponível (modo privado) — segue sem cache */
    }
};

const todayStr = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Bahia' });

export const session = {
    profile: () => read(PROFILE_KEY),
    saveProfile: (profile) => write(PROFILE_KEY, profile),

    /** Marcações de hoje + próximo tipo; descartadas se forem de outro dia. */
    today() {
        const t = read(TODAY_KEY);
        return t && t.date === todayStr() ? t : null;
    },
    saveToday: (entries, nextType, nextTypeLabel) => write(TODAY_KEY, { date: todayStr(), entries, nextType, nextTypeLabel }),

    clear() {
        localStorage.removeItem(PROFILE_KEY);
        localStorage.removeItem(TODAY_KEY);
    },
};

/** UUID v4 do toque (idempotência no servidor). */
export const uuid = () =>
    globalThis.crypto?.randomUUID?.() ??
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
