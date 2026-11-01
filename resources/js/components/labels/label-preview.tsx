import { useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    layoutLabel,
    type LabelData,
    type LabelLayout,
    type LabelSettings,
    type LabelText,
    type LabelWidth,
    type MeasureText,
} from '@/lib/cable-labels';

export function createLabelMeasure(): MeasureText {
    const context = document.createElement('canvas').getContext('2d');
    if (!context)
        throw new Error('Il browser non supporta l’anteprima delle etichette.');
    context.font = '100px Arial';
    const widths = new Map<string, number>();
    return (text, size) => {
        if (!widths.has(text))
            widths.set(text, context.measureText(text).width / 100);
        return widths.get(text)! * size;
    };
}

function FaceText({ texts }: { texts: LabelText[] }) {
    return (
        <g fill="black" fontFamily="Arial, sans-serif">
            {texts.map((text, index) => (
                <text
                    key={index}
                    x={text.x}
                    y={text.y}
                    fontSize={text.size}
                    textAnchor={
                        text.align === 'left'
                            ? 'start'
                            : text.align === 'right'
                              ? 'end'
                              : 'middle'
                    }
                    xmlSpace="preserve"
                >
                    {text.text}
                </text>
            ))}
        </g>
    );
}

export function LabelPreview({
    layout,
    settings,
    color = '#475569',
}: {
    layout: LabelLayout;
    settings: LabelSettings;
    color?: string;
}) {
    const [back, setBack] = useState(false);
    const { faceLength: face, width, wrap, length } = layout;
    const diameter = settings.diameter;
    const cableWidth = diameter * (settings.profile === 'duplex' ? 2 : 1);
    const slack =
        Math.max(
            0,
            wrap -
                diameter * (Math.PI + (settings.profile === 'duplex' ? 2 : 0)),
        ) / 2;
    const appliedLength = cableWidth + slack + face;
    return (
        <div className="flex min-w-0 flex-col gap-6">
            <section className="flex min-w-0 flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-medium">Nastro disteso</h3>
                    <Badge variant="outline">
                        {length.toFixed(1)} × {width} mm
                    </Badge>
                </div>
                <div className="overflow-x-auto rounded-lg border bg-muted/20 p-4">
                    <svg
                        role="img"
                        aria-label={`Bandiera distesa: due facce da ${face} mm, avvolgimento ${wrap} mm, larghezza ${width} mm`}
                        viewBox={`-1 -7 ${length + 2} ${width + 17}`}
                        className="w-full min-w-96"
                        style={{ overflow: 'visible' }}
                    >
                        <rect
                            width={length}
                            height={width}
                            fill="white"
                            stroke="currentColor"
                            strokeWidth="0.15"
                        />
                        <rect
                            x={face}
                            width={wrap}
                            height={width}
                            fill="#e2e8f0"
                        />
                        <path
                            d={`M ${face} 0 V ${width} M ${face + wrap} 0 V ${width}`}
                            stroke="#64748b"
                            strokeWidth="0.2"
                            strokeDasharray="1 1"
                        />
                        <FaceText texts={layout.faces[0]} />
                        <g transform={`translate(${face + wrap} 0)`}>
                            <g
                                transform={
                                    layout.rotation === 180
                                        ? `rotate(180 ${face / 2} ${width / 2})`
                                        : undefined
                                }
                            >
                                <FaceText texts={layout.faces[1]} />
                            </g>
                        </g>
                        <g
                            fill="currentColor"
                            fontSize="2.5"
                            textAnchor="middle"
                        >
                            <text x={face / 2} y="-2">
                                Faccia 1
                            </text>
                            <text x={face + wrap / 2} y="-2">
                                Avvolgimento
                            </text>
                            <text x={face + wrap + face / 2} y="-2">
                                Faccia 2
                            </text>
                            <text x={face / 2} y={width + 5}>
                                {face.toFixed(1)} mm
                            </text>
                            <text x={face + wrap / 2} y={width + 5}>
                                {wrap.toFixed(1)} mm
                            </text>
                            <text x={face + wrap + face / 2} y={width + 5}>
                                {face.toFixed(1)} mm
                            </text>
                        </g>
                    </svg>
                </div>
            </section>
            <section className="flex min-w-0 flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-medium">Applicata al cavo</h3>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={back ? 'back' : 'front'}
                        onValueChange={(value) => {
                            if (value) setBack(value === 'back');
                        }}
                        aria-label="Faccia della bandiera"
                    >
                        <ToggleGroupItem value="front">
                            Faccia 1
                        </ToggleGroupItem>
                        <ToggleGroupItem value="back">Faccia 2</ToggleGroupItem>
                    </ToggleGroup>
                </div>
                <div className="rounded-lg border bg-muted/20 p-6">
                    <svg
                        role="img"
                        aria-label={`Simulazione ${settings.profile === 'duplex' ? 'fibra duplex' : 'cavo tondo'}, diametro ${diameter} mm, faccia ${back ? 2 : 1}`}
                        viewBox={`-3 -10 ${appliedLength + 6} ${width + 20}`}
                        className="mx-auto max-h-56 w-full"
                    >
                        {Array.from(
                            { length: settings.profile === 'duplex' ? 2 : 1 },
                            (_, index) => (
                                <g key={index}>
                                    <rect
                                        x={index * diameter}
                                        y="-10"
                                        width={diameter}
                                        height={width + 20}
                                        rx={diameter / 2}
                                        fill={color}
                                    />
                                    <path
                                        d={`M ${index * diameter + diameter * 0.3} -8 V ${width + 8}`}
                                        stroke="white"
                                        strokeOpacity="0.3"
                                        strokeWidth={diameter * 0.15}
                                    />
                                </g>
                            ),
                        )}
                        <rect
                            x="0"
                            y="0"
                            width={cableWidth + slack}
                            height={width}
                            rx="1"
                            fill="white"
                            fillOpacity="0.8"
                            stroke="#94a3b8"
                            strokeWidth="0.15"
                        />
                        <g transform={`translate(${cableWidth + slack} 0)`}>
                            <rect
                                width={face}
                                height={width}
                                fill="white"
                                stroke="#94a3b8"
                                strokeWidth="0.2"
                            />
                            <g
                                transform={
                                    back && layout.rotation === 180
                                        ? `rotate(180 ${face / 2} ${width / 2})`
                                        : undefined
                                }
                            >
                                <FaceText texts={layout.faces[back ? 1 : 0]} />
                            </g>
                        </g>
                    </svg>
                </div>
                <p className="text-xs text-muted-foreground">
                    Ø {diameter.toFixed(1)} mm
                    {settings.profile === 'duplex'
                        ? ' per guaina · fibra duplex'
                        : ' · cavo tondo'}
                </p>
            </section>
            {layout.errors.length > 0 && (
                <Alert variant="destructive">
                    <AlertDescription>
                        <ul className="list-disc pl-4">
                            {layout.errors.map((error) => (
                                <li key={error}>{error}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

export function SampleLabelPreview({
    settings,
    width,
    data,
}: {
    settings: LabelSettings;
    width: LabelWidth;
    data: LabelData;
}) {
    const [side, setSide] = useState<'a' | 'b'>('a');
    const [measure] = useState(createLabelMeasure);
    const layout = layoutLabel(settings, width, data, side, measure);
    return (
        <div className="flex flex-col gap-4">
            <ToggleGroup
                type="single"
                variant="outline"
                value={side}
                onValueChange={(value) => {
                    if (value) setSide(value as 'a' | 'b');
                }}
                aria-label="Estremità dell’etichetta"
            >
                <ToggleGroupItem value="a">Etichetta A</ToggleGroupItem>
                <ToggleGroupItem value="b">Etichetta B</ToggleGroupItem>
            </ToggleGroup>
            <LabelPreview layout={layout} settings={settings} />
        </div>
    );
}
