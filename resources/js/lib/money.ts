/**
 * Money arrives from the server as exact decimal strings ("0.3818875").
 * It is never converted to a JavaScript number: formatting works on the
 * digits directly (BigInt for rounding), so nothing can drift.
 */
const DECIMAL = /^(-)?(\d+)(?:\.(\d+))?$/;

/**
 * Format a decimal string as US dollars.
 *
 * Without maxDecimals the exact value is shown (trailing zeros trimmed, at
 * least 2 decimals): "0.3818875" -> "$0.3818875". With maxDecimals it is
 * rounded half-up for display only: formatUsd("0.07226", 4) -> "$0.0723".
 */
export function formatUsd(amount: string, maxDecimals?: number): string {
    const match = DECIMAL.exec(amount);

    if (!match) {
        return amount;
    }

    const [, sign = '', integerDigits, fractionDigits = ''] = match;
    let integer = integerDigits;
    let fraction = fractionDigits;

    if (maxDecimals !== undefined && fraction.length > maxDecimals) {
        const roundUp = Number(fraction[maxDecimals]) >= 5;
        const scaled =
            BigInt(integer + fraction.slice(0, maxDecimals)) +
            (roundUp ? 1n : 0n);
        const digits = scaled.toString().padStart(maxDecimals + 1, '0');

        integer = digits.slice(0, digits.length - maxDecimals);
        fraction = digits.slice(digits.length - maxDecimals);
    }

    fraction = fraction.replace(/0+$/, '').padEnd(2, '0');
    integer = integer
        .replace(/^0+(?=\d)/, '')
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    const isZero = /^[0,]*$/.test(integer) && /^0*$/.test(fraction);

    return `${sign && !isZero ? '-' : ''}$${integer}.${fraction}`;
}

/** Estimates are shown rounded, and marked as approximate. */
export function formatEstimate(amount: string): string {
    return `~${formatUsd(amount, 4)}`;
}

/** Exact comparison of two decimal strings: -1, 0 or 1 (no floats). */
export function compareUsd(a: string, b: string): number {
    const x = toUnits(a);
    const y = toUnits(b);

    return x === y ? 0 : x > y ? 1 : -1;
}

/** The amount in units of 10^-12 USD (the stored precision). */
function toUnits(amount: string): bigint {
    const match = DECIMAL.exec(amount);

    if (!match) {
        return 0n;
    }

    const [, sign = '', integer, fraction = ''] = match;
    const units = BigInt(integer + fraction.padEnd(12, '0').slice(0, 12));

    return sign ? -units : units;
}
