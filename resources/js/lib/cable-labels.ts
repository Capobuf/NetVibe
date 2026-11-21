export type LabelWidth = 12 | 18 | 24;
export type LabelPrintMode = 'single' | 'both' | 'a' | 'b';
export type CableType = 'copper' | 'fiber' | 'usb' | 'power';
export type LabelEnd = Record<
    'piano' | 'rack' | 'dispositivo' | 'porta',
    string | null
>;
export type LabelData = { name: string | null; a: LabelEnd; b: LabelEnd };
export type LabelBlock = {
    text: string;
    size: number;
    align: 'left' | 'center' | 'right';
};
export type LabelRow = { blocks: LabelBlock[]; separator: string | null };
export type LabelSettings = {
    endpoint_format: string;
    diameter: number;
    wrap: number;
    profile: 'round' | 'duplex';
    max_length: number;
    length_mode: 'auto' | 'fixed';
    face_length: number;
    fit: 'extend' | 'shrink' | 'wrap';
    back_mode: 'repeat' | 'opposite' | 'custom';
    back_rotation: 0 | 180;
    front: LabelRow[];
    back: LabelRow[];
};
export type LabelTemplate = {
    id: number | null;
    name: string;
    width: LabelWidth;
    cable_type: CableType;
    revision: number;
    settings: LabelSettings;
};
export type LabelText = {
    text: string;
    x: number;
    y: number;
    size: number;
    align: LabelBlock['align'];
};
export type LabelLayout = {
    width: number;
    length: number;
    faceLength: number;
    wrap: number;
    rotation: 0 | 180;
    faces: [LabelText[], LabelText[]];
    // Faces whose content exceeds the printable area.
    overflow: [boolean, boolean];
    errors: LabelError[];
};
// `at` names the setting group that causes the problem, so the UI can show it there.
export type LabelError = {
    message: string;
    at: 'text' | 'length' | 'cable';
    rows?: 'front' | 'back';
    row?: number;
    block?: number;
    end?: 'a' | 'b';
};
export type MeasureText = (text: string, size: number) => number;

export const endpointFields = {
    piano: 'Piano',
    rack: 'Rack',
    dispositivo: 'Dispositivo',
    porta: 'Porta',
};
export const labelMacros: Record<string, string> = {
    ...Object.fromEntries(
        Object.entries(endpointFields).map(([key, label]) => [
            key,
            `${label} · estremità corrente`,
        ]),
    ),
    estremita: 'Estremità corrente · formato completo',
    estremita_a: 'Estremità A · formato completo',
    estremita_b: 'Estremità B · formato completo',
    nome_cavo: 'Nome del cavo',
    ...Object.fromEntries(
        ['a', 'b'].flatMap((side) =>
            Object.entries(endpointFields).map(([key, label]) => [
                `${side}.${key}`,
                `${label} · estremità ${side.toUpperCase()}`,
            ]),
        ),
    ),
};
export const defaultEndpointFormat =
    '[{{piano}}] {{rack}}\\{{dispositivo}}@{{porta}}';
export const sampleLabelData: LabelData = {
    name: 'Uplink sala server',
    a: { piano: 'PT', rack: 'RACK-01', dispositivo: 'SW-CORE', porta: '24' },
    b: { piano: 'P-1', rack: 'RACK-02', dispositivo: 'PH0', porta: '12' },
};
export function labelPreset(
    width: LabelWidth,
    preset = 'endpoint',
): LabelRow[] {
    const size =
        preset === 'links'
            ? width === 12
                ? 2.4
                : width === 18
                  ? 3.8
                  : 5
            : width === 12
              ? 3
              : width === 18
                ? 4
                : 5;
    const block = (text: string): LabelBlock => ({
        text,
        size,
        align: 'center',
    });
    const row = (...texts: string[]): LabelRow => ({
        blocks: texts.map(block),
        separator: ' | ',
    });
    if (preset === 'concise') return [row('{{dispositivo}}@{{porta}}')];
    if (preset === 'name') return [row('{{nome_cavo}}')];
    if (preset === 'links')
        return [
            row('{{nome_cavo}}'),
            row('{{estremita_a}}'),
            row('{{estremita_b}}'),
        ];
    if (preset === 'inline')
        return [row('{{estremita_a}}', '{{nome_cavo}}', '{{estremita_b}}')];
    return [row('{{estremita}}')];
}
export function defaultLabelSettings(
    width: LabelWidth,
    type: CableType,
): LabelSettings {
    const diameter = { copper: 6, fiber: 2, usb: 4, power: 8 }[type];
    return {
        endpoint_format: defaultEndpointFormat,
        diameter,
        wrap: Math.ceil(Math.PI * diameter + 2),
        profile: 'round',
        max_length: 150,
        length_mode: 'auto',
        face_length: 45,
        fit: 'extend',
        back_mode: 'repeat',
        back_rotation: 0,
        front: labelPreset(width),
        back: labelPreset(width),
    };
}
export function minimumWrap(
    diameter: number,
    profile: LabelSettings['profile'],
) {
    // The duplex preview uses two touching round jackets; the tape follows their outer envelope.
    return diameter * (Math.PI + (profile === 'duplex' ? 2 : 0));
}

export function resolveLabelText(
    format: string,
    data: LabelData,
    side: 'a' | 'b',
    endpointFormat: string,
    endpointOnly = false,
): { text: string; errors: Pick<LabelError, 'message' | 'end'>[] } {
    const errors: Pick<LabelError, 'message' | 'end'>[] = [];
    let populated = false;
    let tokens = 0;
    const text = format.replace(/\{\{(.*?)\}\}/gs, (_, macro: string) => {
        tokens++;
        if (
            (!endpointOnly && !Object.hasOwn(labelMacros, macro)) ||
            (endpointOnly && !Object.hasOwn(endpointFields, macro))
        ) {
            errors.push({ message: `Segnaposto non valido: ${macro}.` });
            return '';
        }
        if (macro === 'nome_cavo') {
            populated ||= !!data.name?.trim();
            return data.name ?? '';
        }
        if (['estremita', 'estremita_a', 'estremita_b'].includes(macro)) {
            const result = resolveLabelText(
                endpointFormat,
                data,
                macro === 'estremita'
                    ? side
                    : macro === 'estremita_a'
                      ? 'a'
                      : 'b',
                endpointFormat,
                true,
            );
            errors.push(...result.errors);
            populated ||= !!result.text.trim();
            return result.text;
        }
        const explicit = macro.includes('.');
        const target = explicit ? (macro[0] as 'a' | 'b') : side;
        const field = (explicit ? macro.slice(2) : macro) as keyof LabelEnd;
        const value = data[target][field];
        if (value === null || !value.trim()) {
            errors.push({
                message: `Manca ${endpointFields[field].toLocaleLowerCase()}.`,
                end: target,
            });
        } else populated = true;
        return value ?? '';
    });
    if (/\{\{|\}\}/.test(format.replace(/\{\{(.*?)\}\}/gs, '')))
        errors.push({
            message:
                'Segnaposto incompleto: chiudi le doppie parentesi graffe.',
        });
    // Omit an optional name block together with its literal prefix/suffix.
    return { text: tokens && !populated ? '' : text, errors };
}

function wrapText(
    text: string,
    size: number,
    width: number,
    measure: MeasureText,
): string[] {
    return text.split('\n').flatMap((paragraph) => {
        const lines: string[] = [];
        let line = '';
        for (const word of paragraph.split(/(\s+)/)) {
            if (line && measure(line + word, size) > width) {
                lines.push(line.trimEnd());
                line = '';
            }
            for (const character of word) {
                if (line && measure(line + character, size) > width) {
                    lines.push(line.trimEnd());
                    line = '';
                }
                line += character;
            }
        }
        lines.push(line.trimEnd());
        return lines;
    });
}

export function layoutLabel(
    settings: LabelSettings,
    width: LabelWidth,
    data: LabelData,
    side: 'a' | 'b',
    measure: MeasureText,
): LabelLayout {
    const errors: LabelError[] = [];
    const within = (value: number, min: number, max: number) =>
        Number.isFinite(value) && value >= min && value <= max;
    if (!within(settings.diameter, 0.5, 30) || !within(settings.wrap, 2, 160))
        errors.push({
            message: 'Diametro 0,5–30 mm, avvolgimento 2–160 mm.',
            at: 'cable',
        });
    if (
        !within(settings.max_length, 8, 150) ||
        !within(settings.face_length, 8, settings.max_length)
    )
        errors.push({
            message:
                'Lunghezza della faccia 8–150 mm, entro il limite massimo.',
            at: 'length',
        });
    if (errors.length) {
        return {
            width,
            length: 60,
            faceLength: 20,
            wrap: 20,
            rotation: settings.back_rotation,
            faces: [[], []],
            overflow: [false, false],
            errors,
        };
    }
    const sources = [
        'front',
        settings.back_mode === 'custom' ? 'back' : 'front',
    ] as const;
    const faces = sources.map((source, face) =>
        settings[source]
            .map((row, rowIndex) => ({
                separator: row.separator ?? '',
                blocks: row.blocks.flatMap((block, blockIndex) => {
                    const current =
                        face === 1 && settings.back_mode === 'opposite'
                            ? side === 'a'
                                ? 'b'
                                : 'a'
                            : side;
                    const resolved = resolveLabelText(
                        block.text,
                        data,
                        current,
                        settings.endpoint_format,
                    );
                    errors.push(
                        ...resolved.errors.map((error) => ({
                            ...error,
                            at: 'text' as const,
                            rows: source,
                            row: rowIndex,
                            block: blockIndex,
                        })),
                    );
                    return resolved.text.trim()
                        ? [{ ...block, text: resolved.text }]
                        : [];
                }),
            }))
            .filter((row) => row.blocks.length),
    );
    const minimum = minimumWrap(settings.diameter, settings.profile);
    if (settings.wrap < minimum)
        errors.push({
            message: `Avvolgimento minimo: ${(Math.ceil(minimum * 10) / 10).toFixed(1)} mm.`,
            at: 'cable',
        });
    faces.forEach((face, index) => {
        if (!face.length)
            errors.push({
                message: `La faccia ${index + 1} è vuota.`,
                at: 'text',
                rows: sources[index],
            });
    });

    function fitFace(rows: (typeof faces)[number], length: number, scale = 1) {
        const texts: LabelText[] = [];
        let y = 0;
        let fits = true;
        for (const original of rows) {
            const row = {
                ...original,
                blocks: original.blocks.map((block) => ({
                    ...block,
                    size: Math.max(2, block.size * scale),
                })),
            };
            const size = Math.max(...row.blocks.map((block) => block.size));
            const separatorWidth =
                measure(row.separator, size) + (row.blocks.length > 1 ? 1 : 0);
            const available =
                length - 2 - separatorWidth * (row.blocks.length - 1);
            const natural = row.blocks.map((block) =>
                Math.max(
                    ...block.text
                        .split('\n')
                        .map((line) => measure(line, block.size)),
                    block.size,
                ),
            );
            const total = natural.reduce((sum, value) => sum + value, 0);
            const columns = row.blocks.map((block, index) => {
                const columnWidth = Math.max(
                    0.1,
                    (available * natural[index]) / total,
                );
                const lines =
                    settings.length_mode === 'fixed' && settings.fit === 'wrap'
                        ? wrapText(block.text, block.size, columnWidth, measure)
                        : block.text.split('\n');
                if (
                    lines.some(
                        (line) =>
                            measure(line, block.size) > columnWidth + 0.01,
                    )
                )
                    fits = false;
                return { block, columnWidth, lines };
            });
            if (available <= 0) fits = false;
            const height = Math.max(
                ...columns.map(
                    ({ block, lines }) => lines.length * block.size * 1.2,
                ),
            );
            let x = 1;
            for (const [
                index,
                { block, columnWidth, lines },
            ] of columns.entries()) {
                const anchor =
                    x +
                    (block.align === 'center'
                        ? columnWidth / 2
                        : block.align === 'right'
                          ? columnWidth
                          : 0);
                lines.forEach((line, lineIndex) =>
                    texts.push({
                        text: line,
                        x: anchor,
                        y:
                            y +
                            (height - lines.length * block.size * 1.2) / 2 +
                            block.size +
                            lineIndex * block.size * 1.2,
                        size: block.size,
                        align: block.align,
                    }),
                );
                x += columnWidth;
                if (index < columns.length - 1) {
                    texts.push({
                        text: row.separator,
                        x: x + separatorWidth / 2,
                        y: y + height / 2 + size * 0.35,
                        size,
                        align: 'center',
                    });
                    x += separatorWidth;
                }
            }
            y += height + 0.5;
        }
        const height = Math.max(0, y - 0.5);
        const offset = (width - height) / 2;
        return {
            fits,
            tall: height > width - 2,
            texts: texts.map((text) => ({ ...text, y: text.y + offset })),
        };
    }
    // Measure unwrapped rows first; tape width constrains height, not the reading line.
    const naturalLength = Math.max(
        8,
        ...faces.flatMap((rows) =>
            rows.map((row) => {
                const size = Math.max(...row.blocks.map((block) => block.size));
                return (
                    2 +
                    row.blocks.reduce(
                        (total, block) =>
                            total +
                            Math.max(
                                block.size,
                                ...block.text
                                    .split('\n')
                                    .map((line) => measure(line, block.size)),
                            ),
                        0,
                    ) +
                    (measure(row.separator, size) + 1) * (row.blocks.length - 1)
                );
            }),
        ),
    );
    const automatic = settings.length_mode === 'auto';
    const extend = !automatic && settings.fit === 'extend';
    const desired = automatic
        ? naturalLength
        : extend
          ? Math.max(settings.face_length, naturalLength)
          : settings.face_length;
    const faceLength = Math.min(
        settings.max_length,
        Math.ceil(desired * 10) / 10,
    );
    let placed = faces.map((rows) => fitFace(rows, faceLength));
    if (!automatic && settings.fit === 'shrink') {
        // Keep every block at or above the same readable minimum used by the editor.
        for (
            let percent = 99;
            percent >= 0 && placed.some((face) => !face.fits || face.tall);
            percent--
        ) {
            placed = faces.map((rows) =>
                fitFace(rows, faceLength, percent / 100),
            );
        }
    }
    if (placed.some((face) => !face.fits))
        errors.push({
            message: 'Il testo non entra nella lunghezza della faccia.',
            at: 'length',
        });
    placed.forEach((face, index) => {
        if (face.tall)
            errors.push({
                message: `Faccia ${index + 1}: il testo supera l’altezza del nastro.`,
                at: 'text',
                rows: sources[index],
            });
    });
    return {
        width,
        length: faceLength * 2 + settings.wrap,
        faceLength,
        wrap: settings.wrap,
        rotation: settings.back_rotation,
        faces: [placed[0].texts, placed[1].texts],
        overflow: [
            !placed[0].fits || placed[0].tall,
            !placed[1].fits || placed[1].tall,
        ],
        errors: uniqueErrors(errors),
    };
}

function uniqueErrors(errors: LabelError[]): LabelError[] {
    return [
        ...new Map(
            errors.map((error) => [JSON.stringify(error), error]),
        ).values(),
    ];
}

// One list for all printed labels: each problem appears once and names the end it concerns.
export function labelProblems(
    layouts: LabelLayout[],
    sides: ('a' | 'b')[],
): LabelError[] {
    const found = new Map<string, { error: LabelError; sides: string[] }>();
    layouts.forEach((layout, index) =>
        layout.errors.forEach((error) => {
            const key = JSON.stringify(error);
            const entry = found.get(key) ?? { error, sides: [] };
            entry.sides.push(sides[index]);
            found.set(key, entry);
        }),
    );
    return [...found.values()].map(({ error, sides: affected }) => {
        const end =
            error.end ??
            (affected.length < layouts.length ? affected[0] : null);
        return end
            ? {
                  ...error,
                  message: `Estremità ${end.toUpperCase()}: ${error.message[0].toLocaleLowerCase()}${error.message.slice(1)}`,
              }
            : error;
    });
}

// Profiles are designed on their reference tape; text follows the tape chosen when printing.
export function scaleLabelSettings(
    settings: LabelSettings,
    from: LabelWidth,
    to: LabelWidth,
): LabelSettings {
    if (from === to) return settings;
    const scale = (rows: LabelRow[]) =>
        rows.map((row) => ({
            ...row,
            blocks: row.blocks.map((block) => ({
                ...block,
                size: Math.max(
                    2,
                    Math.round((block.size * to * 10) / from) / 10,
                ),
            })),
        }));
    return {
        ...settings,
        front: scale(settings.front),
        back: scale(settings.back),
    };
}

export const printModes = {
    single: 'Una etichetta per cavo',
    both: 'Due etichette, una per estremità',
    a: 'Solo estremità A',
    b: 'Solo estremità B',
};

export function labelPrintSides(mode: LabelPrintMode): ('a' | 'b')[] {
    return mode === 'both' ? ['a', 'b'] : [mode === 'b' ? 'b' : 'a'];
}

export const labelCompositions = {
    name: { name: 'Solo nome', example: 'Uplink sala server' },
    concise: { name: 'Sintetica', example: 'SW-CORE@24' },
    endpoint: { name: 'Completa', example: '[PT] RACK-01\\SW-CORE@24' },
    links: {
        name: 'Nome + collegamenti',
        example: 'Nome\nEstremità A\nEstremità B',
    },
    inline: {
        name: 'A | Nome | B',
        example: 'Estremità A | Nome | Estremità B',
    },
};
