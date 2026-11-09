// GPS "pré-aquecido": quando a empresa exige localização, o app começa a
// acompanhar a posição assim que a tela de ponto abre. No toque, se houver
// uma posição recente, a batida sai na hora — sem esperar o GPS.
const FRESH_MS = 60_000;

const ERRORS = {
    1: 'Permissão de localização negada. Libere o acesso à localização para este app nas configurações do navegador e tente de novo.',
    2: 'Não foi possível obter sua localização. Ative o GPS do celular e tente de novo.',
    3: 'A localização demorou para responder. Verifique o GPS e tente de novo.',
};

let watchId = null;
let last = null; // { latitude, longitude, at }

const toCoords = (p) => ({ latitude: p.coords.latitude, longitude: p.coords.longitude, at: Date.now() });

export const geo = {
    supported: () => 'geolocation' in navigator,

    start() {
        if (watchId !== null || !this.supported()) return;
        watchId = navigator.geolocation.watchPosition(
            (p) => (last = toCoords(p)),
            () => {},
            { enableHighAccuracy: true, maximumAge: 30_000, timeout: 20_000 },
        );
    },

    stop() {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        watchId = null;
    },

    /** Posição recente do cache ou uma leitura nova. Rejeita com mensagem amigável. */
    current() {
        if (last && Date.now() - last.at < FRESH_MS) {
            return Promise.resolve({ latitude: last.latitude, longitude: last.longitude });
        }
        if (!this.supported()) {
            return Promise.reject(new Error('Este aparelho não oferece localização, que é obrigatória na sua empresa.'));
        }
        return new Promise((resolve, reject) =>
            navigator.geolocation.getCurrentPosition(
                (p) => {
                    last = toCoords(p);
                    resolve({ latitude: last.latitude, longitude: last.longitude });
                },
                (err) => reject(new Error(ERRORS[err.code] ?? ERRORS[2])),
                { enableHighAccuracy: true, timeout: 15_000, maximumAge: FRESH_MS },
            ),
        );
    },
};
