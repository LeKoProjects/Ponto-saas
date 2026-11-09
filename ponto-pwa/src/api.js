// Cliente da API (Sanctum token). Convenção do projeto: todas as chamadas via POST.
const BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const TOKEN_KEY = 'ponto_token';

export const auth = {
    get token() {
        return localStorage.getItem(TOKEN_KEY);
    },
    set(token) {
        localStorage.setItem(TOKEN_KEY, token);
    },
    clear() {
        localStorage.removeItem(TOKEN_KEY);
    },
};

export class ApiError extends Error {
    constructor(message, status, errors = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

export async function post(path, body = {}) {
    let res;
    try {
        res = await fetch(`${BASE}${path}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(auth.token ? { Authorization: `Bearer ${auth.token}` } : {}),
            },
            body: JSON.stringify(body),
        });
    } catch {
        throw new ApiError('Sem conexão com o servidor. Verifique sua internet e tente de novo.', 0);
    }

    const data = await res.json().catch(() => ({}));

    if (res.status === 401) {
        auth.clear();
        window.dispatchEvent(new Event('ponto:logout'));
    }
    if (res.status === 429) {
        throw new ApiError('Muitas tentativas. Aguarde um minuto.', 429);
    }
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new ApiError(first || data.message || 'Erro inesperado.', res.status, data.errors);
    }
    return data;
}
