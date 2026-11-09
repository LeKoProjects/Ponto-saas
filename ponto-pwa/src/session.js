// Dados do colaborador guardados no aparelho (id, nome, CPF, empresa, jornada, regra de GPS).
// A tela de ponto abre na hora com eles e o /me só atualiza em segundo plano.
const PROFILE_KEY = 'ponto_profile';
const TODAY_KEY = 'ponto_today';
const DEVICE_KEY = 'ponto_device'; // status do vínculo (liberado, aguardando…)
const DEVICE_ID_KEY = 'ponto_device_id'; // identidade do aparelho — nunca é apagada

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

/** UUID v4 (identidade do aparelho e idempotência de cada toque). */
export const uuid = () =>
    globalThis.crypto?.randomUUID?.() ??
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });

/**
 * Identificador deste aparelho, gerado uma vez e mantido mesmo após sair da conta.
 * É ele que o RH libera. Limpar os dados do navegador gera um novo (e pede liberação de novo).
 */
export const deviceId = () => {
    let id = read(DEVICE_ID_KEY);
    if (!id) {
        id = uuid();
        write(DEVICE_ID_KEY, id);
    }
    return id;
};

export const session = {
    profile: () => read(PROFILE_KEY),
    saveProfile: (profile) => write(PROFILE_KEY, profile),

    device: () => read(DEVICE_KEY),
    saveDevice: (device) => write(DEVICE_KEY, device),

    /** Marcações de hoje + próximo tipo; descartadas se forem de outro dia. */
    today() {
        const t = read(TODAY_KEY);
        return t && t.date === todayStr() ? t : null;
    },
    saveToday: (entries, nextType, nextTypeLabel) => write(TODAY_KEY, { date: todayStr(), entries, nextType, nextTypeLabel }),

    clear() {
        localStorage.removeItem(PROFILE_KEY);
        localStorage.removeItem(TODAY_KEY);
        localStorage.removeItem(DEVICE_KEY);
        prefs.set('last_sync', null);
    },
};

/**
 * Preferências do aparelho (biometria, última sincronização).
 * Ficam mesmo após sair da conta: são do aparelho, não do colaborador.
 */
const PREFS_KEY = 'ponto_prefs';
export const prefs = {
    get: (key) => (read(PREFS_KEY) ?? {})[key] ?? null,
    set: (key, value) => write(PREFS_KEY, { ...(read(PREFS_KEY) ?? {}), [key]: value }),
};
