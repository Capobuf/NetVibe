import { useId, useState, type ReactNode } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    layoutLabel,
    minimumWrap,
    labelPrintSides,
    type CableType,
    type LabelData,
    type LabelLayout,
    type LabelPrintMode,
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
    endpointSelector,
}: {
    layout: LabelLayout;
    settings: LabelSettings;
    color?: string;
    endpointSelector: ReactNode;
}) {
    const [back, setBack] = useState(false);
    const jacket = useId().replaceAll(':', '');
    const { faceLength: face, width, wrap, length } = layout;
    const diameter = settings.diameter;
    const cableWidth = diameter * (settings.profile === 'duplex' ? 2 : 1);
    const circumference = minimumWrap(diameter, settings.profile);
    const slack = Math.max(0, wrap - circumference) / 2;
    const cableLength = width + 24;
    const flagX = (cableLength - width) / 2;
    const flagY = cableWidth + slack;
    return (
        <div className="grid min-w-0 items-start gap-6 @min-[52rem]:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section className="flex min-w-0 flex-col gap-3">
                <div className="flex min-h-8 flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-sm font-medium">Nastro</h3>
                        <Badge variant="outline" className="tabular-nums">
                            {length.toFixed(1)} × {width} mm
                        </Badge>
                    </div>
                    {endpointSelector}
                </div>
                <div className="h-64 overflow-x-auto rounded-lg border bg-muted/20 p-5 @min-[52rem]:h-80">
                    <svg
                        role="img"
                        aria-label={`Bandiera distesa: due facce da ${face} mm, avvolgimento ${wrap} mm, larghezza ${width} mm`}
                        viewBox={`-1 -5 ${length + 2} ${width + 12}`}
                        className="mx-auto h-full w-full max-w-none tabular-nums"
                        style={{ minWidth: (length + 2) * 4 }}
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
                                Avv.
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
                <div className="flex min-h-8 flex-wrap items-center justify-between gap-2">
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
                <div className="h-64 overflow-x-auto rounded-lg border bg-muted/20 p-5 @min-[52rem]:h-80">
                    <svg
                        role="img"
                        aria-label={`Bandiera su cavo verticale ${settings.profile === 'duplex' ? 'in fibra duplex con due guaine parallele' : 'tondo'}, diametro ${diameter} mm, faccia ${back ? 2 : 1}`}
                        viewBox={`-2 -2 ${flagY + face + 4} ${cableLength + 4}`}
                        className="mx-auto h-full w-full max-w-none"
                        style={{ minWidth: (flagY + face + 4) * 4 }}
                    >
                        <defs>
                            <linearGradient
                                id={jacket}
                                x1="0"
                                x2="0"
                                y1="0"
                                y2="1"
                            >
                                <stop
                                    offset="0"
                                    stopColor="black"
                                    stopOpacity="0.32"
                                />
                                <stop
                                    offset="0.3"
                                    stopColor="white"
                                    stopOpacity="0.3"
                                />
                                <stop
                                    offset="0.65"
                                    stopColor="white"
                                    stopOpacity="0"
                                />
                                <stop
                                    offset="1"
                                    stopColor="black"
                                    stopOpacity="0.4"
                                />
                            </linearGradient>
                        </defs>
                        <g
                            transform={`translate(0 ${cableLength}) rotate(-90)`}
                        >
                            {Array.from(
                                {
                                    length:
                                        settings.profile === 'duplex' ? 2 : 1,
                                },
                                (_, index) => (
                                    <g key={index}>
                                        <rect
                                            x="-2"
                                            y={index * diameter}
                                            width={cableLength + 4}
                                            height={diameter}
                                            fill={color}
                                        />
                                        <rect
                                            x="-2"
                                            y={index * diameter}
                                            width={cableLength + 4}
                                            height={diameter}
                                            fill={`url(#${jacket})`}
                                        />
                                        <path
                                            d={`M 3 ${index * diameter + diameter * 0.32} H ${flagX - 3}`}
                                            stroke="white"
                                            strokeOpacity="0.25"
                                            strokeWidth="0.15"
                                        />
                                    </g>
                                ),
                            )}
                            <path
                                d={`M ${flagX} 0 Q ${flagX - 0.8} ${cableWidth / 2} ${flagX} ${cableWidth} L ${flagX} ${flagY + 1} H ${flagX + width} V ${cableWidth} Q ${flagX + width + 0.8} ${cableWidth / 2} ${flagX + width} 0 Z`}
                                fill="white"
                                fillOpacity="0.87"
                                stroke="#94a3b8"
                                strokeWidth="0.2"
                            />
                            <path
                                d={`M ${flagX} ${cableWidth} H ${flagX + width}`}
                                stroke="#94a3b8"
                                strokeWidth="0.2"
                            />
                            <g
                                transform={`translate(${flagX + width} ${flagY}) rotate(90)`}
                            >
                                <rect
                                    x="0.4"
                                    y="-0.4"
                                    width={face}
                                    height={width}
                                    fill="#cbd5e1"
                                />
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
                                    <FaceText
                                        texts={layout.faces[back ? 1 : 0]}
                                    />
                                </g>
                            </g>
                        </g>
                    </svg>
                </div>
                <p className="text-xs text-muted-foreground tabular-nums">
                    Circonferenza totale: {circumference.toFixed(1)} mm
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

export function LabelPreviews({
    layouts,
    printMode,
    settings,
    color,
}: {
    layouts: LabelLayout[];
    printMode: LabelPrintMode;
    settings: LabelSettings;
    color?: string;
}) {
    const [selected, setSelected] = useState('0');
    const index = layouts.length === 1 ? 0 : Number(selected);
    const sides = labelPrintSides(printMode);
    const otherErrors = [
        ...new Set(
            layouts.flatMap((layout, i) => (i !== index ? layout.errors : [])),
        ),
    ];
    return (
        <div className="@container flex min-w-0 flex-col gap-4">
            <LabelPreview
                layout={layouts[index]}
                settings={settings}
                color={color}
                endpointSelector={
                    layouts.length === 2 ? (
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={selected}
                            aria-label="Etichetta fisica in anteprima"
                            onValueChange={(value) => {
                                if (value) setSelected(value);
                            }}
                        >
                            <ToggleGroupItem value="0">
                                Estremità A
                            </ToggleGroupItem>
                            <ToggleGroupItem value="1">
                                Estremità B
                            </ToggleGroupItem>
                        </ToggleGroup>
                    ) : (
                        <span className="text-sm text-muted-foreground">
                            {printMode === 'single'
                                ? 'Cavo · riferimento A'
                                : `Estremità ${sides[0].toUpperCase()}`}
                        </span>
                    )
                }
            />
            {otherErrors.length > 0 && (
                <Alert variant="destructive">
                    <AlertDescription>
                        <p>Altra etichetta:</p>
                        <ul className="list-disc pl-4">
                            {otherErrors.map((error) => (
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
    cableType,
}: {
    settings: LabelSettings;
    width: LabelWidth;
    data: LabelData;
    cableType: CableType;
}) {
    const [measure] = useState(createLabelMeasure);
    const layouts = labelPrintSides('both').map((side) =>
        layoutLabel(settings, width, data, side, measure),
    );
    return (
        <LabelPreviews
            layouts={layouts}
            printMode="both"
            settings={settings}
            color={cableType === 'fiber' ? '#facc15' : undefined}
        />
    );
}
