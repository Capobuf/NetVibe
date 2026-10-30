export const borderColors = [
    { name: 'Grigio', color: '#8998aa' },
    { name: 'Bianco', color: '#ffffff' },
    { name: 'Nero', color: '#171717' },
    { name: 'Blu', color: '#3b82f6' },
    { name: 'Azzurro', color: '#65b9d0' },
    { name: 'Verde', color: '#22c55e' },
    { name: 'Giallo', color: '#facc15' },
    { name: 'Arancione', color: '#f97316' },
    { name: 'Rosso', color: '#ef4444' },
    { name: 'Viola', color: '#a855f7' },
    { name: 'Rosa', color: '#ec4899' },
];

export function parseCustomColors(raw: string | null): string[] {
    if (raw === null) return [];
    const colors: unknown = JSON.parse(raw);
    if (
        !Array.isArray(colors) ||
        colors.some(
            (color) =>
                typeof color !== 'string' || !/^#[0-9a-f]{6}$/i.test(color),
        )
    )
        throw new Error('Palette non valida.');
    return [
        ...new Set((colors as string[]).map((color) => color.toLowerCase())),
    ].filter((color) => !borderColors.some((preset) => preset.color === color));
}
