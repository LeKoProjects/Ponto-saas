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
// randomUUID só existe em HTTPS/localhost; aberto pela rede (http://IP) usa getRandomValues, que sempre existe.
export const uuid = () => {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    const b = globalThis.crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40; // versão 4
    b[8] = (b[8] & 0x3f) | 0x80; // variante RFC 4122
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
};

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
