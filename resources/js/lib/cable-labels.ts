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
    errors: string[];
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
): { text: string; errors: string[] } {
    const errors: string[] = [];
    let populated = false;
    let tokens = 0;
    const text = format.replace(/\{\{(.*?)\}\}/gs, (_, macro: string) => {
        tokens++;
        if (
            (!endpointOnly && !Object.hasOwn(labelMacros, macro)) ||
            (endpointOnly && !Object.hasOwn(endpointFields, macro))
        ) {
            errors.push(
                `Segnaposto non valido: ${macro}. Sostituiscilo con una macro disponibile.`,
            );
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
            errors.push(
                `Estremità ${target.toUpperCase()}: manca ${endpointFields[field].toLocaleLowerCase()}. Completa il dato nel rack/dispositivo oppure rimuovi la macro dal template.`,
            );
        } else populated = true;
        return value ?? '';
    });
    if (/\{\{|\}\}/.test(format.replace(/\{\{(.*?)\}\}/gs, '')))
        errors.push(
            'Segnaposto incompleto: chiudi le doppie parentesi graffe.',
        );
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
    const errors: string[] = [];
    if (
        !Number.isFinite(settings.diameter) ||
        settings.diameter < 0.5 ||
        settings.diameter > 30 ||
        !Number.isFinite(settings.wrap) ||
        settings.wrap < 2 ||
        settings.wrap > 160 ||
        !Number.isFinite(settings.max_length) ||
        settings.max_length < 8 ||
        settings.max_length > 150 ||
        !Number.isFinite(settings.face_length) ||
        settings.face_length < 8 ||
        settings.face_length > settings.max_length
    ) {
        return {
            width,
            length: 60,
            faceLength: 20,
            wrap: 20,
            rotation: settings.back_rotation,
            faces: [[], []],
            errors: [
                'Correggi le dimensioni: diametro 0,5–30 mm, avvolgimento 2–160 mm, lunghezza faccia 8–150 mm entro il limite massimo.',
            ],
        };
    }
    const faces = [
        settings.front,
        settings.back_mode === 'custom' ? settings.back : settings.front,
    ].map((rows, face) =>
        rows
            .map((row) => ({
                separator: row.separator ?? '',
                blocks: row.blocks.flatMap((block) => {
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
                    errors.push(...resolved.errors);
                    return resolved.text.trim()
                        ? [{ ...block, text: resolved.text }]
                        : [];
                }),
            }))
            .filter((row) => row.blocks.length),
    );
    const minimum = minimumWrap(settings.diameter, settings.profile);
    if (settings.wrap < minimum)
        errors.push(
            `Avvolgimento insufficiente: imposta almeno ${(Math.ceil(minimum * 10) / 10).toFixed(1)} mm per il profilo e il diametro selezionati.`,
        );
    if (faces.some((face) => !face.length))
        errors.push(
            'Una faccia è vuota. Inserisci il nome del cavo o scegli un template con collegamenti.',
        );

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
            fits: fits && height <= width - 2,
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
            percent >= 0 && placed.some((face) => !face.fits);
            percent--
        ) {
            placed = faces.map((rows) =>
                fitFace(rows, faceLength, percent / 100),
            );
        }
    }
    if (placed.some((face) => !face.fits))
        errors.push(
            'Il contenuto non entra: aumenta la lunghezza della faccia o il limite massimo, scegli un nastro più alto oppure modifica l’adattamento del testo (minimo 2 mm).',
        );
    return {
        width,
        length: faceLength * 2 + settings.wrap,
        faceLength,
        wrap: settings.wrap,
        rotation: settings.back_rotation,
        faces: [placed[0].texts, placed[1].texts],
        errors: [...new Set(errors)],
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
