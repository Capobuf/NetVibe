import { SaveButton } from '@/components/save-button';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Copy, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react';
import {
    CompositionPicker,
    LabelEditor,
} from '@/components/labels/label-editor';
import {
    createLabelMeasure,
    SampleLabelPreview,
} from '@/components/labels/label-preview';
import { Choice } from '@/components/hardware-editor/editor-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    defaultLabelSettings,
    endpointFields,
    labelPreset,
    labelCompositions,
    layoutLabel,
    sampleLabelData,
    type CableType,
    type LabelTemplate,
    type LabelWidth,
} from '@/lib/cable-labels';
import { cableTypes } from '@/lib/rack-cabling';
import { destroy, index, store, update } from '@/routes/administration/labels';
import { index as administration } from '@/routes/administration';

function emptyProfile(width: LabelWidth = 12): LabelTemplate {
    return {
        id: null,
        name: '',
        width,
        cable_type: 'copper',
        revision: 0,
        settings: defaultLabelSettings(width, 'copper'),
    };
}

export default function Labels({
    templates,
    selectedTemplateId,
}: {
    templates: LabelTemplate[];
    selectedTemplateId: number | null;
}) {
    const initial =
        templates.find((template) => template.id === selectedTemplateId) ??
        templates[0];
    const form = useForm<LabelTemplate>(initial ?? emptyProfile());
    const [active, setActive] = useState(!!initial);
    const [query, setQuery] = useState('');
    const [sample, setSample] = useState(sampleLabelData);
    const [measure] = useState(createLabelMeasure);
    const [creating, setCreating] = useState(false);
    const [newName, setNewName] = useState('');
    const [newWidth, setNewWidth] = useState<LabelWidth>(12);
    const [newType, setNewType] = useState<CableType>('copper');
    const [preset, setPreset] = useState('endpoint');
    const [deleting, setDeleting] = useState(false);
    const [metadata, setMetadata] = useState<Pick<
        LabelTemplate,
        'name' | 'width' | 'cable_type'
    > | null>(null);
    const [editingSample, setEditingSample] = useState(false);
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
    function load(next: LabelTemplate) {
        form.setDefaults(next.id ? next : { ...next, name: '' });
        form.setData(next);
        form.clearErrors();
        setActive(true);
    }
    function guard(change: () => void) {
        if (form.isDirty) setLeave(() => change);
        else change();
    }
    const saved = !!form.data.id && !form.isDirty;
    const visible = templates.filter((template) =>
        `${template.name} ${template.width} mm ${cableTypes[template.cable_type]}`
            .toLocaleLowerCase()
            .includes(query.toLocaleLowerCase()),
    );
    const profileTab = active ? String(form.data.id ?? 'draft') : 'empty';
    const faceLength = layoutLabel(
        form.data.settings,
        form.data.width,
        sample,
        'a',
        measure,
    ).faceLength;
    return (
        <>
            <Head title="Etichette · Amministrazione" />
            <div className="flex min-w-0 flex-col gap-5 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-medium tracking-tight">
                        Etichette
                    </h1>
                </header>
                <Tabs defaultValue="cables" className="gap-5">
                    <TabsList>
                        <TabsTrigger value="cables">Cavi</TabsTrigger>
                    </TabsList>
                    <TabsContent value="cables">
                        <Tabs
                            value={profileTab}
                            activationMode="manual"
                            className="min-w-0 gap-5"
                            onValueChange={(value) => {
                                const next = templates.find(
                                    (template) => String(template.id) === value,
                                );
                                if (next && next.id !== form.data.id)
                                    guard(() => load(next));
                            }}
                        >
                            <div className="flex min-w-0 flex-wrap items-center gap-3">
                                <div className="min-w-0 basis-full overflow-x-auto p-1 xl:flex-1 xl:basis-auto">
                                    <TabsList aria-label="Profili etichette">
                                        {visible.map((template) => (
                                            <TabsTrigger
                                                key={template.id}
                                                value={String(template.id)}
                                                disabled={form.processing}
                                                className="shrink-0 px-3"
                                            >
                                                {template.name}
                                            </TabsTrigger>
                                        ))}
                                        {active && !form.data.id && (
                                            <TabsTrigger
                                                value="draft"
                                                disabled={form.processing}
                                                className="shrink-0 px-3"
                                            >
                                                Nuovo profilo
                                            </TabsTrigger>
                                        )}
                                    </TabsList>
                                </div>
                                <Input
                                    aria-label="Cerca profili"
                                    placeholder="Cerca profili…"
                                    className="w-48"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        guard(() => {
                                            setNewWidth(form.data.width);
                                            setNewName('');
                                            setCreating(true);
                                        })
                                    }
                                    disabled={form.processing}
                                >
                                    <Plus />
                                    Nuovo profilo
                                </Button>
                            </div>
                            {!visible.length && query && (
                                <p className="text-sm text-muted-foreground">
                                    Nessun profilo trovato.
                                </p>
                            )}
                            <TabsContent
                                value={profileTab}
                                aria-labelledby={undefined}
                                aria-label="Profilo etichetta"
                            >
                                {active ? (
                                    <div className="flex min-w-0 flex-col gap-6">
                                        <form
                                            id="label-template"
                                            className="flex min-w-0 flex-col gap-6"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                form.submit(
                                                    form.data.id
                                                        ? update(form.data.id)
                                                        : store(),
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: (page) => {
                                                            const saved = (
                                                                page.props
                                                                    .templates as LabelTemplate[]
                                                            ).find(
                                                                (template) =>
                                                                    template.id ===
                                                                    page.props
                                                                        .selectedTemplateId,
                                                            );
                                                            if (saved)
                                                                load(saved);
                                                        },
                                                    },
                                                );
                                            }}
                                        >
                                            <fieldset
                                                disabled={form.processing}
                                                className="flex min-w-0 flex-col gap-6"
                                            >
                                                <div className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-4 rounded-lg border bg-background p-4">
                                                    <div className="min-w-0 flex-1">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            className="-mx-2 h-auto max-w-full flex-col items-start gap-1 px-2 py-2 text-left wrap-break-word whitespace-normal"
                                                            aria-label={`Modifica nome, larghezza e tipo di cavo del profilo ${form.data.name}`}
                                                            onClick={() =>
                                                                setMetadata({
                                                                    name: form
                                                                        .data
                                                                        .name,
                                                                    width: form
                                                                        .data
                                                                        .width,
                                                                    cable_type:
                                                                        form
                                                                            .data
                                                                            .cable_type,
                                                                })
                                                            }
                                                        >
                                                            <span>
                                                                {form.data.name}
                                                            </span>
                                                            <span className="text-sm font-normal text-muted-foreground">
                                                                {
                                                                    form.data
                                                                        .width
                                                                }{' '}
                                                                mm ·{' '}
                                                                {
                                                                    cableTypes[
                                                                        form
                                                                            .data
                                                                            .cable_type
                                                                    ]
                                                                }
                                                            </span>
                                                        </Button>
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-3">
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="icon"
                                                                    aria-label="Azioni profilo"
                                                                    disabled={
                                                                        form.processing
                                                                    }
                                                                >
                                                                    <MoreHorizontal />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuGroup>
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            setMetadata(
                                                                                {
                                                                                    name: form
                                                                                        .data
                                                                                        .name,
                                                                                    width: form
                                                                                        .data
                                                                                        .width,
                                                                                    cable_type:
                                                                                        form
                                                                                            .data
                                                                                            .cable_type,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <Pencil />
                                                                        Nome e
                                                                        formato
                                                                    </DropdownMenuItem>
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            guard(
                                                                                () =>
                                                                                    load(
                                                                                        {
                                                                                            ...structuredClone(
                                                                                                form.data,
                                                                                            ),
                                                                                            id: null,
                                                                                            name: `${form.data.name.slice(0, 247)} — copia`,
                                                                                            revision: 0,
                                                                                        },
                                                                                    ),
                                                                            )
                                                                        }
                                                                    >
                                                                        <Copy />
                                                                        Duplica
                                                                        profilo
                                                                    </DropdownMenuItem>
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            setEditingSample(
                                                                                true,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Pencil />
                                                                        Dati di
                                                                        esempio
                                                                    </DropdownMenuItem>
                                                                </DropdownMenuGroup>
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    disabled={
                                                                        !form
                                                                            .data
                                                                            .id
                                                                    }
                                                                    onSelect={() =>
                                                                        setDeleting(
                                                                            true,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 />
                                                                    Elimina
                                                                    profilo
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                        <SaveButton
                                                            processing={
                                                                form.processing
                                                            }
                                                            saved={saved}
                                                        >
                                                            Salva profilo
                                                        </SaveButton>
                                                    </div>
                                                </div>
                                                {Object.keys(form.errors)
                                                    .length > 0 && (
                                                    <Alert variant="destructive">
                                                        <AlertDescription>
                                                            {Object.values(
                                                                form.errors,
                                                            ).join(' ')}
                                                        </AlertDescription>
                                                    </Alert>
                                                )}
                                                <div className="grid min-w-0 items-start gap-6 xl:grid-cols-[20rem_minmax(0,1fr)]">
                                                    <section
                                                        className="min-w-0 rounded-lg border p-4 md:p-5"
                                                        aria-label="Modifica profilo"
                                                    >
                                                        <LabelEditor
                                                            key={
                                                                form.data.id ??
                                                                'new'
                                                            }
                                                            settings={
                                                                form.data
                                                                    .settings
                                                            }
                                                            width={
                                                                form.data.width
                                                            }
                                                            faceLength={
                                                                faceLength
                                                            }
                                                            onChange={(
                                                                settings,
                                                            ) =>
                                                                form.setData(
                                                                    'settings',
                                                                    settings,
                                                                )
                                                            }
                                                        />
                                                    </section>
                                                    <section className="flex min-w-0 flex-col gap-4 xl:sticky xl:top-28">
                                                        <h2 className="text-lg font-medium">
                                                            Anteprima
                                                        </h2>
                                                        <SampleLabelPreview
                                                            settings={
                                                                form.data
                                                                    .settings
                                                            }
                                                            width={
                                                                form.data.width
                                                            }
                                                            data={sample}
                                                            cableType={
                                                                form.data
                                                                    .cable_type
                                                            }
                                                        />
                                                    </section>
                                                </div>
                                            </fieldset>
                                        </form>
                                    </div>
                                ) : (
                                    <Alert>
                                        <AlertDescription>
                                            Crea un profilo per iniziare.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </TabsContent>
                        </Tabs>
                    </TabsContent>
                </Tabs>
            </div>
            <Dialog
                open={!!metadata}
                onOpenChange={(open) => {
                    if (!open) setMetadata(null);
                }}
            >
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>Nome e formato del profilo</DialogTitle>
                    </DialogHeader>
                    {metadata && (
                        <form
                            className="flex flex-col gap-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.setData({ ...form.data, ...metadata });
                                setMetadata(null);
                            }}
                        >
                            <FieldGroup className="gap-4">
                                <Field>
                                    <FieldLabel htmlFor="profile-name">
                                        Nome del profilo
                                    </FieldLabel>
                                    <Input
                                        id="profile-name"
                                        value={metadata.name}
                                        required
                                        maxLength={255}
                                        onChange={(event) =>
                                            setMetadata({
                                                ...metadata,
                                                name: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Choice
                                    label="Larghezza TZe"
                                    value={String(metadata.width)}
                                    choices={{
                                        '12': '12 mm',
                                        '18': '18 mm',
                                        '24': '24 mm',
                                    }}
                                    onChange={(value) =>
                                        setMetadata({
                                            ...metadata,
                                            width: Number(value) as LabelWidth,
                                        })
                                    }
                                />
                                <Choice
                                    label="Tipo di cavo"
                                    value={metadata.cable_type}
                                    choices={cableTypes}
                                    onChange={(value) =>
                                        setMetadata({
                                            ...metadata,
                                            cable_type: value as CableType,
                                        })
                                    }
                                />
                            </FieldGroup>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setMetadata(null)}
                                >
                                    Annulla
                                </Button>
                                <Button type="submit">Applica</Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
            <Dialog open={editingSample} onOpenChange={setEditingSample}>
                <DialogContent
                    aria-describedby={undefined}
                    className="sm:max-w-2xl"
                >
                    <DialogHeader>
                        <DialogTitle>Dati di esempio</DialogTitle>
                    </DialogHeader>
                    <FieldGroup className="gap-4 pt-4">
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
                                        name: event.target.value || null,
                                    })
                                }
                            />
                        </Field>
                        {(['a', 'b'] as const).map((side) => (
                            <FieldGroup
                                key={side}
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {Object.entries(endpointFields).map(
                                    ([key, label]) => (
                                        <Field key={key}>
                                            <FieldLabel
                                                htmlFor={`sample-${side}-${key}`}
                                            >
                                                {label} · {side.toUpperCase()}
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
                                                            ...sample[side],
                                                            [key]:
                                                                event.target
                                                                    .value ||
                                                                null,
                                                        },
                                                    })
                                                }
                                            />
                                        </Field>
                                    ),
                                )}
                            </FieldGroup>
                        ))}
                    </FieldGroup>

                    <DialogFooter>
                        <Button onClick={() => setEditingSample(false)}>
                            Torna all’anteprima
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent
                    aria-describedby={undefined}
                    className="sm:max-w-2xl"
                >
                    <DialogHeader>
                        <DialogTitle>Nuovo profilo cavi</DialogTitle>
                    </DialogHeader>
                    <form
                        className="flex flex-col gap-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const settings = defaultLabelSettings(
                                newWidth,
                                newType,
                            );
                            settings.front = labelPreset(newWidth, preset);
                            load({
                                id: null,
                                name:
                                    newName.trim() ||
                                    `${cableTypes[newType]} — ${labelCompositions[preset as keyof typeof labelCompositions].name} (${newWidth} mm)`,
                                width: newWidth,
                                cable_type: newType,
                                revision: 0,
                                settings,
                            });
                            setCreating(false);
                        }}
                    >
                        <FieldGroup className="gap-4">
                            <Field>
                                <FieldLabel htmlFor="new-profile-name">
                                    Nome del profilo
                                </FieldLabel>
                                <Input
                                    id="new-profile-name"
                                    value={newName}
                                    maxLength={255}
                                    placeholder={`${cableTypes[newType]} — ${labelCompositions[preset as keyof typeof labelCompositions].name} (${newWidth} mm)`}
                                    onChange={(event) =>
                                        setNewName(event.target.value)
                                    }
                                />
                            </Field>
                            <FieldGroup className="grid grid-cols-2 gap-4">
                                <Choice
                                    label="Larghezza TZe"
                                    value={String(newWidth)}
                                    choices={{
                                        '12': '12 mm',
                                        '18': '18 mm',
                                        '24': '24 mm',
                                    }}
                                    onChange={(value) =>
                                        setNewWidth(Number(value) as LabelWidth)
                                    }
                                />
                                <Choice
                                    label="Tipo di cavo"
                                    value={newType}
                                    choices={cableTypes}
                                    onChange={(value) =>
                                        setNewType(value as CableType)
                                    }
                                />
                            </FieldGroup>
                            <Field>
                                <FieldLabel>Composizione iniziale</FieldLabel>
                                <CompositionPicker
                                    value={preset}
                                    onChange={setPreset}
                                />
                            </Field>
                        </FieldGroup>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreating(false)}
                            >
                                Annulla
                            </Button>
                            <Button type="submit">Apri editor</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Eliminare «{form.data.name}»?</DialogTitle>
                        <DialogDescription>
                            Il profilo sarà rimosso dal catalogo di stampa. I
                            cavi rimangono invariati.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => setDeleting(false)}
                        >
                            Annulla
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={form.processing}
                            onClick={() => {
                                if (!form.data.id) return;
                                form.submit(destroy(form.data.id), {
                                    preserveScroll: true,
                                    onSuccess: (page) => {
                                        setDeleting(false);
                                        const next = (
                                            page.props
                                                .templates as LabelTemplate[]
                                        )[0];
                                        if (next) load(next);
                                        else {
                                            setActive(false);
                                            form.setDefaults(
                                                emptyProfile(form.data.width),
                                            );
                                            form.setData(
                                                emptyProfile(form.data.width),
                                            );
                                            form.clearErrors();
                                        }
                                    },
                                    onError: () => setDeleting(false),
                                });
                            }}
                        >
                            Elimina profilo
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!leave}
                onOpenChange={(open) => {
                    if (!open) setLeave(null);
                }}
            >
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            Scartare le modifiche al profilo?
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
