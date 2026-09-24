// Shared rules of the academic section, mirroring the server
// (App\Support\CertificateThresholds, AcademicYear::scaleFor).

export const EDUCATION_LEVELS = ['primary', 'middle', 'secondary', 'vocational', 'licence', 'master', 'doctorate'];
export const PERIODS = ['T1', 'T2', 'T3'];
// Highest first — the suggestion takes the first one the grade reaches.
export const CERTIFICATES = ['excellence', 'congratulations', 'encouragement', 'honor_roll'];

// Primary school grades out of 10, every other level out of 20.
export const scaleForLevel = (level) => (level === 'primary' ? 10 : 20);

// 2025 → "2025/2026"
export const yearLabel = (year) => `${year}/${Number(year) + 1}`;

// The highest certificate whose threshold the grade reaches on its scale, or ''.
export function suggestCertificate(grade, scale, thresholds) {
    const limits = thresholds?.[String(scale)];
    const value = Number(grade);
    if (!limits || grade === '' || grade === null || Number.isNaN(value)) return '';

    return CERTIFICATES.find((c) => limits[c] !== undefined && value >= Number(limits[c])) ?? '';
}
