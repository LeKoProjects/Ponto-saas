// Biometria do celular (WebAuthn): digital, rosto ou o bloqueio de tela do aparelho.
// A biometria nunca sai do celular: ele assina um desafio do servidor com uma chave
// que fica guardada no próprio aparelho. Só é oferecida quando o app identifica um celular.
import { post } from './api';

/** É um celular/tablet? (Android, iPhone, iPad — inclusive iPad que se identifica como Mac). */
export function isMobile() {
    if (navigator.userAgentData && typeof navigator.userAgentData.mobile === 'boolean' && navigator.userAgentData.mobile) return true;
    const ua = navigator.userAgent || '';
    if (/Android|iPhone|iPad|iPod|Mobile/i.test(ua)) return true;
    return /Macintosh/.test(ua) && navigator.maxTouchPoints > 1;
}

/**
 * Situação da biometria neste aparelho:
 *   'ok'          — celular com biometria/bloqueio de tela disponível
 *   'desktop'     — não é celular
 *   'insecure'    — precisa de HTTPS (ou localhost)
 *   'unsupported' — navegador sem WebAuthn ou celular sem biometria/bloqueio configurado
 */
export async function availability() {
    if (!isMobile()) return 'desktop';
    if (!window.isSecureContext) return 'insecure';
    if (!window.PublicKeyCredential?.isUserVerifyingPlatformAuthenticatorAvailable) return 'unsupported';
    try {
        return (await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()) ? 'ok' : 'unsupported';
    } catch {
        return 'unsupported';
    }
}

export const AVAILABILITY_TEXT = {
    desktop: 'Disponível no celular: abra o app no seu celular para usar a biometria.',
    insecure: 'A biometria exige conexão segura (HTTPS).',
    unsupported: 'Este celular ou navegador não oferece biometria. Configure a digital, o rosto ou o bloqueio de tela do celular.',
};

// ---- base64url <-> bytes ----
const toBytes = (b64url) => {
    const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(b64url.length / 4) * 4, '=');
    return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
};
const toB64url = (buffer) =>
    btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');

const friendly = (e) => {
    if (e?.name === 'NotAllowedError') return new Error('Biometria cancelada ou não reconhecida. Tente de novo.');
    if (e?.name === 'InvalidStateError') return new Error('A biometria já está cadastrada neste celular.');
    if (e?.name === 'SecurityError') return new Error('A biometria não pode ser usada neste endereço (precisa de HTTPS).');
    return e instanceof Error ? e : new Error('Não foi possível usar a biometria.');
};

/** Liga a biometria: o celular pede a digital/rosto e cria a credencial. Devolve o aparelho atualizado. */
export async function register() {
    const { publicKey } = await post('/biometria/opcoes');
    publicKey.challenge = toBytes(publicKey.challenge);
    publicKey.user.id = toBytes(publicKey.user.id);
    publicKey.excludeCredentials = (publicKey.excludeCredentials || []).map((c) => ({ ...c, id: toBytes(c.id) }));

    let cred;
    try {
        cred = await navigator.credentials.create({ publicKey });
    } catch (e) {
        throw friendly(e);
    }
    const { device } = await post('/biometria/cadastrar', {
        clientDataJSON: toB64url(cred.response.clientDataJSON),
        attestationObject: toB64url(cred.response.attestationObject),
    });
    return device;
}

/** Na batida: pede a biometria e devolve a assinatura para enviar junto do /ponto. */
export async function confirm() {
    const { publicKey } = await post('/biometria/desafio');
    publicKey.challenge = toBytes(publicKey.challenge);
    publicKey.allowCredentials = (publicKey.allowCredentials || []).map((c) => ({ ...c, id: toBytes(c.id) }));

    let cred;
    try {
        cred = await navigator.credentials.get({ publicKey });
    } catch (e) {
        throw friendly(e);
    }
    return {
        id: toB64url(cred.rawId),
        clientDataJSON: toB64url(cred.response.clientDataJSON),
        authenticatorData: toB64url(cred.response.authenticatorData),
        signature: toB64url(cred.response.signature),
    };
}

export async function disable() {
    const { device } = await post('/biometria/desativar');
    return device;
}
