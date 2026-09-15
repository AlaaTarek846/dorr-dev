const STRENGTH_LEVELS = [
    { key: 'very_weak', color: 'danger', percent: 10 },
    { key: 'weak', color: 'danger', percent: 25 },
    { key: 'fair', color: 'warning', percent: 45 },
    { key: 'good', color: 'info', percent: 65 },
    { key: 'strong', color: 'success', percent: 85 },
    { key: 'very_strong', color: 'success', percent: 100 },
];

export function calculatePasswordStrength(password) {
    const value = String(password ?? '');
    let score = 0;

    if (value.length >= 8) {
        score++;
    }

    if (/[a-z]/.test(value)) {
        score++;
    }

    if (/[A-Z]/.test(value)) {
        score++;
    }

    if (/\d/.test(value)) {
        score++;
    }

    if (/[^A-Za-z0-9]/.test(value)) {
        score++;
    }

    if (value.length >= 12 && score >= 4) {
        score = 5;
    }

    return {
        score,
        ...STRENGTH_LEVELS[Math.min(score, STRENGTH_LEVELS.length - 1)],
    };
}

export function generateSecurePassword(length = 12) {
    const lowercase = 'abcdefghijklmnopqrstuvwxyz';
    const uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const numbers = '0123456789';
    const symbols = '!@#$%^&*';
    const all = `${lowercase}${uppercase}${numbers}${symbols}`;

    const required = [
        lowercase[Math.floor(Math.random() * lowercase.length)],
        uppercase[Math.floor(Math.random() * uppercase.length)],
        numbers[Math.floor(Math.random() * numbers.length)],
        symbols[Math.floor(Math.random() * symbols.length)],
    ];

    while (required.length < length) {
        required.push(all[Math.floor(Math.random() * all.length)]);
    }

    return required
        .sort(() => Math.random() - 0.5)
        .join('');
}
