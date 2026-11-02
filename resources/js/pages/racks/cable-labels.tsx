import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowLeft, Download, Printer } from 'lucide-react';
import {
    LabelLengthSettings,
    LabelPhysicalSettings,
} from '@/components/labels/label-settings';
import {
    createLabelMeasure,
    LabelPreviews,
} from '@/components/labels/label-preview';
import { Choice } from '@/components/hardware-editor/editor-fields';
import { IconButton } from '@/components/icon-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    defaultLabelSettings,
    layoutLabel,
    labelPrintSides,
    printModes,
    type CableType,
    type LabelData,
    type LabelPrintMode,
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
    const [profileId, setProfileId] = useState('');
    const [printMode, setPrintMode] = useState<LabelPrintMode>('both');
    const [measure] = useState(createLabelMeasure);
    const [settings, setSettings] = useState(() =>
        defaultLabelSettings(12, cable.type),
    );
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
    const layouts = labelPrintSides(printMode).map((side) =>
        layoutLabel(settings, width, data, side, measure),
    );
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
                            if (!profileId || problems.length || busy) return;
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
                                        setProfileId('');
                                        setSettings(
                                            defaultLabelSettings(
                                                next,
                                                cable.type,
                                            ),
                                        );
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
                            <Field>
                                <FieldLabel htmlFor="print-profile">
                                    Profilo
                                </FieldLabel>
                                <Select
                                    value={profileId}
                                    onValueChange={(value) => {
                                        const template = templates.find(
                                            (template) =>
                                                String(template.id) === value,
                                        );
                                        setProfileId(value);
                                        setSettings(
                                            template?.settings ??
                                                defaultLabelSettings(
                                                    width,
                                                    cable.type,
                                                ),
                                        );
                                        invalidate();
                                    }}
                                >
                                    <SelectTrigger
                                        id="print-profile"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Scegli un profilo…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {templates
                                                .filter(
                                                    (template) =>
                                                        template.width ===
                                                        width,
                                                )
                                                .map((template) => (
                                                    <SelectItem
                                                        key={template.id}
                                                        value={String(
                                                            template.id,
                                                        )}
                                                    >
                                                        {template.name}
                                                    </SelectItem>
                                                ))}
                                            <SelectItem value="preset">
                                                Preset iniziale · Completa (
                                                {width} mm)
                                            </SelectItem>
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </Field>
                            {profileId && (
                                <>
                                    <Choice
                                        label="Etichette fisiche da stampare"
                                        value={printMode}
                                        choices={printModes}
                                        onChange={(value) => {
                                            setPrintMode(
                                                value as LabelPrintMode,
                                            );
                                            invalidate();
                                        }}
                                    />
                                    <LabelLengthSettings
                                        settings={settings}
                                        faceLength={layouts[0].faceLength}
                                        onChange={(next) => {
                                            setSettings(next);
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
                                        {busy
                                            ? 'Preparazione…'
                                            : `Genera PDF · ${layouts.length} ${layouts.length === 1 ? 'etichetta' : 'etichette'}`}
                                    </Button>
                                </>
                            )}
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
                    <section className="flex min-w-0 flex-col gap-5 lg:sticky lg:top-6">
                        <h2 className="text-base font-medium">
                            Anteprima di stampa
                        </h2>
                        {profileId ? (
                            <LabelPreviews
                                layouts={layouts}
                                printMode={printMode}
                                settings={settings}
                                color={cable.color}
                            />
                        ) : (
                            <Alert>
                                <AlertDescription>
                                    Seleziona un profilo per preparare le
                                    etichette.
                                </AlertDescription>
                            </Alert>
                        )}
                    </section>
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
