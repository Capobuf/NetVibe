import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowLeft, Download, Printer } from 'lucide-react';
import { LabelPhysicalSettings } from '@/components/labels/label-settings';
import {
    createLabelMeasure,
    LabelPreview,
} from '@/components/labels/label-preview';
import { Choice } from '@/components/hardware-editor/editor-fields';
import { IconButton } from '@/components/icon-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    defaultLabelSettings,
    layoutLabel,
    type CableType,
    type LabelData,
    type LabelTemplate,
    type LabelWidth,
} from '@/lib/cable-labels';
import { cableTypes } from '@/lib/rack-cabling';
import { show } from '@/routes/companies/sites/racks';

type Props = {
    company: { id: number; name: string };
    site: { id: number; name: string | null };
    rack: { id: number; name: string };
    cable: { name: string | null; type: CableType; color: string };
    ends: Pick<LabelData, 'a' | 'b'>;
    templates: LabelTemplate[];
};
export default function CableLabels({
    company,
    site,
    rack,
    cable,
    ends,
    templates,
}: Props) {
    const [width, setWidth] = useState<LabelWidth>(12);
    const [selection, setSelection] = useState('both');
    const [side, setSide] = useState<'a' | 'b'>('a');
    const [measure] = useState(createLabelMeasure);
    function settingsFor(width: LabelWidth) {
        return (
            templates.find((template) => template.width === width)?.settings ??
            defaultLabelSettings(width, cable.type)
        );
    }
    const [settings, setSettings] = useState(() => settingsFor(12));
    const [documentUrl, setDocumentUrl] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    useEffect(
        () => () => {
            if (documentUrl) URL.revokeObjectURL(documentUrl);
        },
        [documentUrl],
    );
    const data = { ...ends, name: cable.name };
    const a = layoutLabel(settings, width, data, 'a', measure);
    const b = layoutLabel(settings, width, data, 'b', measure);
    const layouts =
        selection === 'both' ? [a, b] : selection === 'a' ? [a] : [b];
    const problems = [...new Set(layouts.flatMap((layout) => layout.errors))];
    function invalidate() {
        setDocumentUrl(null);
        setError('');
    }
    const route = { company: company.id, site: site.id, rack: rack.id };
    return (
        <>
            <Head title="Etichette cavo" />
            <div className="flex min-w-0 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center gap-3">
                    <IconButton label="Torna al rack" asChild>
                        <Link href={show(route)}>
                            <ArrowLeft />
                        </Link>
                    </IconButton>
                    <div>
                        <h1 className="text-2xl font-medium tracking-tight">
                            Etichette cavo
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {cable.name ||
                                `${ends.a.dispositivo ?? 'A'} ↔ ${ends.b.dispositivo ?? 'B'}`}
                        </p>
                    </div>
                    <Badge variant="outline">{cableTypes[cable.type]}</Badge>
                </header>
                <div className="grid min-w-0 items-start gap-8 lg:grid-cols-[20rem_minmax(0,1fr)]">
                    <form
                        className="flex flex-col gap-6"
                        onSubmit={async (event) => {
                            event.preventDefault();
                            if (problems.length || busy) return;
                            setBusy(true);
                            setError('');
                            try {
                                const { cableLabelPdf } =
                                    await import('@/lib/cable-label-pdf');
                                const blob = await cableLabelPdf(layouts);
                                setDocumentUrl(URL.createObjectURL(blob));
                            } catch (error) {
                                setError(
                                    error instanceof Error
                                        ? error.message
                                        : 'Generazione PDF non riuscita. Riprova.',
                                );
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <fieldset
                            disabled={busy}
                            className="flex min-w-0 flex-col gap-5"
                        >
                            <Field>
                                <FieldLabel id="print-width">
                                    Larghezza TZe
                                </FieldLabel>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    value={String(width)}
                                    aria-labelledby="print-width"
                                    onValueChange={(value) => {
                                        if (!value) return;
                                        const next = Number(
                                            value,
                                        ) as LabelWidth;
                                        setWidth(next);
                                        setSettings(settingsFor(next));
                                        invalidate();
                                    }}
                                >
                                    {[12, 18, 24].map((width) => (
                                        <ToggleGroupItem
                                            key={width}
                                            value={String(width)}
                                        >
                                            {width} mm
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </Field>
                            <p className="text-xs text-muted-foreground">
                                {templates.some(
                                    (template) => template.width === width,
                                )
                                    ? 'Template salvato'
                                    : 'Preset iniziale'}{' '}
                                · {cableTypes[cable.type]} · {width} mm
                            </p>
                            <Choice
                                label="Etichette da stampare"
                                value={selection}
                                choices={{
                                    both: 'Entrambe le estremità',
                                    a: 'Solo estremità A',
                                    b: 'Solo estremità B',
                                }}
                                onChange={(value) => {
                                    setSelection(value);
                                    if (value !== 'both')
                                        setSide(value as 'a' | 'b');
                                    invalidate();
                                }}
                            />
                            <LabelPhysicalSettings
                                settings={settings}
                                onChange={(next) => {
                                    setSettings(next);
                                    invalidate();
                                }}
                            />
                            <Button
                                type="submit"
                                disabled={busy || problems.length > 0}
                            >
                                <Printer />
                                {busy ? 'Preparazione…' : 'Genera PDF'}
                            </Button>
                        </fieldset>
                        {error && (
                            <Alert variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        {documentUrl && (
                            <div className="flex flex-col gap-3">
                                <Button asChild>
                                    <a
                                        href={documentUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Printer />
                                        Apri PDF e stampa
                                    </a>
                                </Button>
                                <Button asChild variant="outline">
                                    <a
                                        href={documentUrl}
                                        download="etichette-cavo.pdf"
                                    >
                                        <Download />
                                        Scarica PDF
                                    </a>
                                </Button>
                                <p className="text-sm text-muted-foreground">
                                    Stampa al 100%, senza adattamento alla
                                    pagina. Formato nastro {width} mm; verifica
                                    margini e taglio sulla stampante.
                                </p>
                            </div>
                        )}
                    </form>
                    <div className="flex min-w-0 flex-col gap-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-base font-medium">Anteprima</h2>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={side}
                                aria-label="Anteprima estremità"
                                onValueChange={(value) => {
                                    if (value) setSide(value as 'a' | 'b');
                                }}
                            >
                                <ToggleGroupItem value="a">
                                    Etichetta A
                                </ToggleGroupItem>
                                <ToggleGroupItem value="b">
                                    Etichetta B
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                        <LabelPreview
                            layout={side === 'a' ? a : b}
                            settings={settings}
                            color={cable.color}
                        />
                        {problems.length > 0 &&
                            (side === 'a' ? b : a).errors.some(
                                (error) =>
                                    problems.includes(error) &&
                                    !(side === 'a' ? a : b).errors.includes(
                                        error,
                                    ),
                            ) && (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        Controlla anche gli avvisi dell’altra
                                        estremità prima di generare entrambe le
                                        etichette.
                                    </AlertDescription>
                                </Alert>
                            )}
                    </div>
                </div>
            </div>
        </>
    );
}
CableLabels.layout = ({ company, site, rack }: Props) => ({
    breadcrumbs: [
        {
            title: rack.name,
            href: show({ company: company.id, site: site.id, rack: rack.id }),
        },
        { title: 'Etichette', href: '#' },
    ],
});
