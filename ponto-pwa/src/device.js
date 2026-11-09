// Nome amigável do aparelho para a tela de Configurações.
// Usa User-Agent Client Hints quando disponível (Chrome/Android traz o modelo)
// e cai para a leitura do User-Agent nos demais navegadores.

const browserFrom = (ua) => {
    if (/SamsungBrowser\/(\d+)/.test(ua)) return `Samsung Internet ${RegExp.$1}`;
    if (/EdgA?\/(\d+)/.test(ua)) return `Edge ${RegExp.$1}`;
    if (/OPR\/(\d+)/.test(ua)) return `Opera ${RegExp.$1}`;
    if (/Firefox\/(\d+)/.test(ua)) return `Firefox ${RegExp.$1}`;
    if (/CriOS\/(\d+)/.test(ua)) return `Chrome ${RegExp.$1}`;
    if (/Chrome\/(\d+)/.test(ua)) return `Chrome ${RegExp.$1}`;
    if (/Version\/(\d+).*Safari/.test(ua)) return `Safari ${RegExp.$1}`;
    return 'Navegador';
};

const systemFrom = (ua) => {
    if (/Android (\d+(\.\d+)?)/.test(ua)) return `Android ${RegExp.$1}`;
    if (/(iPhone|iPad).*OS (\d+)[_.](\d+)/.test(ua)) return `iOS ${RegExp.$2}.${RegExp.$3}`;
    if (/Windows NT 10/.test(ua)) return 'Windows';
    if (/Mac OS X/.test(ua)) return 'macOS';
    if (/Linux/.test(ua)) return 'Linux';
    return 'Sistema desconhecido';
};

const modelFromUa = (ua) => {
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua)) return 'iPad';
    // Ex.: "Linux; Android 14; SM-A546E Build/..." → "SM-A546E"
    const m = ua.match(/Android [\d.]+; ([^;)]+?)(?: Build|\))/);
    return m && m[1] !== 'K' ? m[1].trim() : null;
};

/** @returns {Promise<{ name: string, model: string|null, system: string, browser: string, standalone: boolean }>} */
export async function describeDevice() {
    const ua = navigator.userAgent;
    let model = modelFromUa(ua);
    let system = systemFrom(ua);

    // Android moderno "congela" o UA (modelo vira "K"); Client Hints devolve o real.
    if (navigator.userAgentData?.getHighEntropyValues) {
        try {
            const hints = await navigator.userAgentData.getHighEntropyValues(['model', 'platform', 'platformVersion']);
            if (hints.model) model = hints.model;
            if (hints.platform === 'Android' && hints.platformVersion) {
                system = `Android ${hints.platformVersion.split('.')[0]}`;
            }
        } catch {
            /* sem permissão para hints — segue com o UA */
        }
    }

    const browser = browserFrom(ua);
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;

    return {
        name: model ? `${model} · ${system}` : system,
        model,
        system,
        browser,
        standalone,
    };
}
