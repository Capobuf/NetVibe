import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Save } from 'lucide-react';
import { LabelEditor } from '@/components/labels/label-editor';
import { SampleLabelPreview } from '@/components/labels/label-preview';
import { Choice } from '@/components/hardware-editor/editor-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    defaultLabelSettings,
    endpointFields,
    sampleLabelData,
    type CableType,
    type LabelTemplate,
    type LabelWidth,
} from '@/lib/cable-labels';
import { cableTypes } from '@/lib/rack-cabling';
import { index, store } from '@/routes/administration/labels';
import { index as administration } from '@/routes/administration';

export default function Labels({ templates }: { templates: LabelTemplate[] }) {
    function templateFor(width: LabelWidth, type: CableType): LabelTemplate {
        return (
            templates.find(
                (template) =>
                    template.width === width && template.cable_type === type,
            ) ?? {
                width,
                cable_type: type,
                revision: 0,
                settings: defaultLabelSettings(width, type),
            }
        );
    }
    const form = useForm<LabelTemplate>(templateFor(12, 'copper'));
    const [sample, setSample] = useState(sampleLabelData);
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const allowLeave = useRef(false);
    useEffect(() => {
        const unload = (event: BeforeUnloadEvent) => {
            if (form.isDirty) event.preventDefault();
        };
        const stop = router.on('before', (event) => {
            if (
                !form.isDirty ||
                allowLeave.current ||
                event.detail.visit.method !== 'get'
            )
                return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave(() => () => {
                allowLeave.current = true;
                router.visit(visit.url, {
                    ...visit,
                    onFinish: () => {
                        allowLeave.current = false;
                    },
                });
            });
        });
        window.addEventListener('beforeunload', unload);
        return () => {
            stop();
            window.removeEventListener('beforeunload', unload);
        };
    }, [form.isDirty]);
    function select(width: LabelWidth, type: CableType) {
        const change = () => {
            const next = templateFor(width, type);
            form.setData(next);
            form.setDefaults(next);
            form.clearErrors();
        };
        if (form.isDirty) setLeave(() => change);
        else change();
    }
    return (
        <>
            <Head title="Etichette · Amministrazione" />
            <div className="flex min-w-0 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-medium tracking-tight">
                            Etichette
                        </h1>
                        <Badge variant="outline">
                            {form.isDirty
                                ? 'Modifiche da salvare'
                                : form.data.revision
                                  ? 'Template salvato'
                                  : 'Preset iniziale'}
                        </Badge>
                    </div>
                    <Button
                        type="submit"
                        form="label-template"
                        disabled={form.processing}
                    >
                        <Save />
                        {form.processing ? 'Salvataggio…' : 'Salva template'}
                    </Button>
                </header>
                <Tabs defaultValue="cables" className="gap-5">
                    <TabsList>
                        <TabsTrigger value="cables">Cavi</TabsTrigger>
                    </TabsList>
                    <TabsContent value="cables" className="flex flex-col gap-5">
                        <div className="flex flex-wrap items-end gap-5">
                            <Field className="w-auto">
                                <FieldLabel id="label-width">
                                    Larghezza TZe
                                </FieldLabel>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    value={String(form.data.width)}
                                    disabled={form.processing}
                                    aria-labelledby="label-width"
                                    onValueChange={(value) => {
                                        if (value)
                                            select(
                                                Number(value) as LabelWidth,
                                                form.data.cable_type,
                                            );
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
                            <div className="w-56">
                                <Choice
                                    label="Tipo di cavo"
                                    value={form.data.cable_type}
                                    choices={cableTypes}
                                    disabled={form.processing}
                                    onChange={(type) =>
                                        select(
                                            form.data.width,
                                            type as CableType,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        {Object.keys(form.errors).length > 0 && (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {Object.values(form.errors).join(' ')}
                                </AlertDescription>
                            </Alert>
                        )}
                        <div className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(20rem,0.9fr)_minmax(0,1.1fr)]">
                            <form
                                id="label-template"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.submit(store(), {
                                        preserveScroll: true,
                                        onSuccess: (page) => {
                                            const saved = (
                                                page.props
                                                    .templates as LabelTemplate[]
                                            ).find(
                                                (template) =>
                                                    template.width ===
                                                        form.data.width &&
                                                    template.cable_type ===
                                                        form.data.cable_type,
                                            )!;
                                            form.setData(saved);
                                            form.setDefaults(saved);
                                        },
                                    });
                                }}
                            >
                                <fieldset
                                    disabled={form.processing}
                                    className="min-w-0"
                                >
                                    <LabelEditor
                                        key={`${form.data.width}-${form.data.cable_type}`}
                                        settings={form.data.settings}
                                        width={form.data.width}
                                        onChange={(settings) =>
                                            form.setData('settings', settings)
                                        }
                                    />
                                </fieldset>
                            </form>
                            <aside className="flex min-w-0 flex-col gap-5 xl:sticky xl:top-6">
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="text-base font-medium">
                                        Anteprima
                                    </h2>
                                    <Badge variant="secondary">
                                        Dati di esempio
                                    </Badge>
                                </div>
                                <SampleLabelPreview
                                    settings={form.data.settings}
                                    width={form.data.width}
                                    data={sample}
                                />
                                <details className="rounded-lg border p-4">
                                    <summary className="cursor-pointer rounded-sm text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring">
                                        Dati di esempio
                                    </summary>
                                    <FieldGroup className="mt-4 gap-4">
                                        <Field>
                                            <FieldLabel htmlFor="sample-name">
                                                Nome del cavo
                                            </FieldLabel>
                                            <Input
                                                id="sample-name"
                                                value={sample.name ?? ''}
                                                maxLength={255}
                                                onChange={(event) =>
                                                    setSample({
                                                        ...sample,
                                                        name:
                                                            event.target
                                                                .value || null,
                                                    })
                                                }
                                            />
                                        </Field>
                                        {(['a', 'b'] as const).map((side) => (
                                            <FieldGroup
                                                key={side}
                                                className="grid gap-3 sm:grid-cols-2"
                                            >
                                                {Object.entries(
                                                    endpointFields,
                                                ).map(([key, label]) => (
                                                    <Field key={key}>
                                                        <FieldLabel
                                                            htmlFor={`sample-${side}-${key}`}
                                                        >
                                                            {label} ·{' '}
                                                            {side.toUpperCase()}
                                                        </FieldLabel>
                                                        <Input
                                                            id={`sample-${side}-${key}`}
                                                            value={
                                                                sample[side][
                                                                    key as keyof typeof endpointFields
                                                                ] ?? ''
                                                            }
                                                            maxLength={255}
                                                            onChange={(event) =>
                                                                setSample({
                                                                    ...sample,
                                                                    [side]: {
                                                                        ...sample[
                                                                            side
                                                                        ],
                                                                        [key]:
                                                                            event
                                                                                .target
                                                                                .value ||
                                                                            null,
                                                                    },
                                                                })
                                                            }
                                                        />
                                                    </Field>
                                                ))}
                                            </FieldGroup>
                                        ))}
                                    </FieldGroup>
                                </details>
                            </aside>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
            <Dialog
                open={!!leave}
                onOpenChange={(open) => {
                    if (!open) setLeave(null);
                }}
            >
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            Scartare le modifiche al template?
                        </DialogTitle>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setLeave(null)}
                        >
                            Continua modifica
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                leave?.();
                                setLeave(null);
                            }}
                        >
                            Scarta modifiche
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
Labels.layout = {
    breadcrumbs: [
        { title: 'Amministrazione', href: administration() },
        { title: 'Etichette', href: index() },
    ],
};
