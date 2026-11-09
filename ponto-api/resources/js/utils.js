export const fmtMinutes = (m) => {
    if (!m) return '';
    const sign = m < 0 ? '-' : '';
    const abs = Math.abs(m);
    return `${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`;
};

export const fmtMinutesZero = (m) => fmtMinutes(m) || '00:00';

/** "08:30" → 510 (para campos de duração em horas). */
export const parseHHMM = (v) => {
    const [h, m] = String(v || '0:0').split(':').map(Number);
    return (h || 0) * 60 + (m || 0);
};

export const maskCpf = (v) =>
    String(v || '')
        .replace(/\D/g, '')
        .slice(0, 11)
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d{1,2})$/, '$1-$2');

export const maskCnpj = (v) =>
    String(v || '')
        .replace(/\D/g, '')
        .slice(0, 14)
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
