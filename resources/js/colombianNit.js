const WEIGHTS = [71, 67, 59, 53, 47, 43, 41, 37, 29, 23, 19, 17, 13, 7, 3];

/** NIT y cédula de ciudadanía: la DIAN calcula el dígito con el mismo módulo 11. */
export const DV_TYPES = ['13', '31'];

export function verificationDigit(value) {
    const n = String(value ?? '').replace(/\D/g, '');
    if (!n || n.length > WEIGHTS.length) return '';
    const off = WEIGHTS.length - n.length;
    let sum = 0;
    for (let i = 0; i < n.length; i++) sum += Number(n[i]) * WEIGHTS[off + i];
    const r = sum % 11;
    return String(r > 1 ? 11 - r : r);
}
