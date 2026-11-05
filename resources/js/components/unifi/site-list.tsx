import { router, useForm } from '@inertiajs/react';
import { ArrowRight, Eye, Link2, MapPin, Server, Unlink } from 'lucide-react';
import { Fragment, useState } from 'react';
import { CatalogCombobox } from '@/components/devices/catalog-combobox';
import { IconButton } from '@/components/icon-button';
import { SaveButton } from '@/components/save-button';
import { store as storeCompany } from '@/routes/administration/companies';
import type { CreatedCompany } from '@/types/company';
import {
    associate,
    revoke,
    selectConsoles,
} from '@/actions/App/Http/Controllers/UnifiController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { UnifiSitePreview } from './site-preview';
import type { UnifiCompany, UnifiConnection, UnifiSite } from './types';

type Destination = { id: number; label: string };

/**
 * Mapping table: UniFi consoles and sites on the left, the NetVibe company
 * and site they feed on the right. Cloud consoles are selected in place.
 */
export function UnifiSiteList({
    connection,
    companies,
    search,
    readonly,
    disabled: busy = false,
}: {
    connection: UnifiConnection;
    companies: UnifiCompany[];
    search: string;
    readonly: boolean;
    disabled?: boolean;
}) {
    const [previewId, setPreviewId] = useState<number | null>(null);
    const [confirm, setConfirm] = useState(false);
    const preview = connection.sites.find((source) => source.id === previewId);
    const selectable =
        !readonly &&
        connection.mode === 'cloud' &&
        (connection.hosts?.length ?? 0) > 0;
    const saved = connection.selected_console_ids ?? [];
    const consoleForm = useForm({
        selected_console_ids: saved,
        revision: connection.revision ?? '',
    });
    const chosen = consoleForm.data.selected_console_ids;
    const changed =
        JSON.stringify([...chosen].sort()) !==
        JSON.stringify([...saved].sort());
    const removed = connection.sites.filter(
        (source) =>
            source.site &&
            saved.includes(source.console_id) &&
            !chosen.includes(source.console_id),
    );
    const destinations = companies.flatMap((company) =>
        company.sites.map((site) => ({
            id: site.id,
            label: site.name ? `${company.name} · ${site.name}` : company.name,
        })),
    );
    const query = search.trim().toLocaleLowerCase();
    const sources = connection.sites.filter((source) =>
        [
            connection.name,
            source.name,
            source.console_name,
            source.site?.company.name,
            source.site?.name,
        ]
            .join(' ')
            .toLocaleLowerCase()
            .includes(query),
    );
    // Accessible consoles are listed even before their sites are read.
    const consoles = [
        ...(selectable && !query
            ? (connection.hosts ?? []).map((host) => ({
                  id: host.id,
                  name: host.name,
              }))
            : []),
        ...sources.map((source) => ({
            id: source.console_id,
            name: source.console_name,
        })),
    ].filter(
        (item, index, all) =>
            all.findIndex((other) => other.id === item.id) === index,
    );
    const disabled = busy || consoleForm.processing;

    function saveConsoles() {
        consoleForm.transform((data) => ({
            ...data,
            revision: connection.revision,
        }));
        consoleForm.submit(selectConsoles(connection.id), {
            preserveScroll: true,
            onSuccess: () => setConfirm(false),
        });
    }

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="py-4">
                <CardTitle>
                    {selectable ? 'Console da integrare' : 'Siti UniFi'}
                </CardTitle>
            </CardHeader>
            <CardContent className="px-0">
                {consoles.length ? (
                    <Table aria-label="Collegamento dei siti UniFi alle sedi NetVibe">
                        <TableHeader className="bg-muted/50">
                            <TableRow>
                                <TableHead scope="col">UniFi</TableHead>
                                <TableHead scope="col" className="w-10">
                                    <span className="sr-only">
                                        Collegamento
                                    </span>
                                </TableHead>
                                <TableHead scope="col">NetVibe</TableHead>
                                <TableHead scope="col" className="w-0">
                                    <span className="sr-only">Azioni</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {consoles.map((item) => {
                                const sites = sources.filter(
                                    (source) => source.console_id === item.id,
                                );
                                const included =
                                    !selectable || chosen.includes(item.id);
                                const checkboxId = `unifi-console-${connection.id}-${item.id}`;
                                return (
                                    <Fragment key={item.id}>
                                        <TableRow className="bg-muted/30 hover:bg-muted/30">
                                            <TableCell colSpan={4}>
                                                <div className="flex flex-wrap items-center gap-3">
                                                    {selectable ? (
                                                        <Checkbox
                                                            id={checkboxId}
                                                            checked={included}
                                                            disabled={disabled}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                consoleForm.setData(
                                                                    'selected_console_ids',
                                                                    checked ===
                                                                        true
                                                                        ? [
                                                                              ...chosen,
                                                                              item.id,
                                                                          ]
                                                                        : chosen.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  item.id,
                                                                          ),
                                                                )
                                                            }
                                                        />
                                                    ) : null}
                                                    <label
                                                        htmlFor={
                                                            selectable
                                                                ? checkboxId
                                                                : undefined
                                                        }
                                                        className="flex items-center gap-2 font-medium"
                                                    >
                                                        <Server className="size-4 text-muted-foreground" />
                                                        {item.name}
                                                    </label>
                                                    <Badge variant="outline">
                                                        {sites.length}{' '}
                                                        {sites.length === 1
                                                            ? 'sito'
                                                            : 'siti'}
                                                    </Badge>
                                                    {!included ? (
                                                        <span className="text-sm text-muted-foreground">
                                                            Non integrata
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        {sites.map((source) => (
                                            <SiteRow
                                                key={`${source.id}:${source.site?.id ?? ''}:${source.confirmed_at ?? ''}`}
                                                source={source}
                                                connection={connection}
                                                destinations={destinations}
                                                readonly={readonly}
                                                included={included}
                                                onPreview={() =>
                                                    setPreviewId(source.id)
                                                }
                                            />
                                        ))}
                                        {included && !sites.length ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="pl-10 text-sm text-muted-foreground"
                                                >
                                                    Siti non ancora acquisiti
                                                </TableCell>
                                            </TableRow>
                                        ) : null}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </Table>
                ) : (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                {search
                                    ? 'Nessun sito corrisponde alla ricerca'
                                    : connection.discovered_at
                                      ? 'Nessun sito disponibile'
                                      : 'Dati non ancora acquisiti'}
                            </EmptyTitle>
                            {!search && !connection.discovered_at ? (
                                <EmptyDescription>
                                    Usa “Aggiorna siti” per leggerli dalla
                                    console.
                                </EmptyDescription>
                            ) : null}
                        </EmptyHeader>
                    </Empty>
                )}
            </CardContent>
            {selectable ? (
                <CardFooter className="flex-wrap gap-3 border-t py-3">
                    <SaveButton
                        type="button"
                        processing={consoleForm.processing}
                        disabled={disabled || !changed}
                        saved={!changed}
                        onClick={() =>
                            removed.length ? setConfirm(true) : saveConsoles()
                        }
                    >
                        Salva console
                    </SaveButton>
                    <FieldError
                        errors={Object.values(consoleForm.errors).map(
                            (message) => ({ message }),
                        )}
                    />
                </CardFooter>
            ) : null}
            {preview ? (
                <UnifiSitePreview
                    source={preview}
                    current={
                        connection.enabled && connection.check_status === 'ok'
                    }
                    onClose={() => setPreviewId(null)}
                />
            ) : null}
            <Dialog
                open={confirm}
                onOpenChange={(open) => {
                    if (!consoleForm.processing) setConfirm(open);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Modificare le console selezionate?
                        </DialogTitle>
                        <DialogDescription>
                            Le nuove acquisizioni dei siti delle console
                            deselezionate verranno sospese.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="flex max-h-48 flex-col gap-2 overflow-y-auto text-sm">
                        {removed.map((source) => (
                            <li key={source.id}>
                                {source.console_name} · {source.name}
                            </li>
                        ))}
                    </ul>
                    <FieldError
                        errors={Object.values(consoleForm.errors).map(
                            (message) => ({ message }),
                        )}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirm(false)}
                            disabled={consoleForm.processing}
                        >
                            Annulla
                        </Button>
                        <SaveButton
                            type="button"
                            processing={consoleForm.processing}
                            onClick={saveConsoles}
                        >
                            Conferma
                        </SaveButton>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

function SiteRow({
    source,
    connection,
    destinations,
    readonly,
    included,
    onPreview,
}: {
    source: UnifiSite;
    connection: UnifiConnection;
    destinations: Destination[];
    readonly: boolean;
    included: boolean;
    onPreview: () => void;
}) {
    const [selected, setSelected] = useState<Destination | null>(null);
    const [editing, setEditing] = useState(false);
    const [creatingCompany, setCreatingCompany] = useState(false);
    const [confirm, setConfirm] = useState<'associate' | 'revoke' | null>(null);
    const form = useForm({
        site_id: 0,
        revision: connection.revision ?? '',
        confirmed: true,
    });
    const destination = source.site
        ? [source.site.company.name, source.site.name]
              .filter(Boolean)
              .join(' · ')
        : null;
    const disabled = !connection.enabled || !source.available || !included;
    const linked = !!source.site && !!source.confirmed_at && included;

    return (
        <TableRow className={cn(!included && 'opacity-60')}>
            <TableCell className="pl-10 align-top whitespace-normal">
                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium break-words">
                            {source.name}
                        </span>
                        {!source.available ? (
                            <Badge variant="outline">Non disponibile</Badge>
                        ) : null}
                        {source.version ? (
                            <Badge variant="outline">
                                Network {source.version}
                            </Badge>
                        ) : null}
                    </div>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {source.preview
                            ? `${source.preview.devices.length} dispositivi · ${source.preview.networks === null ? 'reti non supportate' : `${source.preview.networks.length} reti`}`
                            : 'Dati non ancora acquisiti'}
                    </span>
                </div>
            </TableCell>
            <TableCell className="align-top">
                <ArrowRight
                    className={cn(
                        'mt-0.5 size-4',
                        linked ? 'text-primary' : 'text-muted-foreground/40',
                    )}
                    aria-label={linked ? 'Collegato' : 'Non collegato'}
                />
            </TableCell>
            <TableCell className="align-top whitespace-normal">
                {editing && !readonly ? (
                    <div className="flex max-w-lg flex-col gap-2 sm:flex-row sm:items-start">
                        <div className="min-w-0 flex-1">
                            <CatalogCombobox
                                id={`unifi-site-${source.id}`}
                                name="site_id"
                                items={destinations.map((item) => ({
                                    id: item.id,
                                    name: item.label,
                                }))}
                                value={selected ? String(selected.id) : ''}
                                onChange={(value) =>
                                    setSelected(
                                        destinations.find(
                                            (item) => String(item.id) === value,
                                        ) ?? null,
                                    )
                                }
                                placeholder="Cerca azienda o sede…"
                                createLabel="Aggiungi azienda"
                                disabled={form.processing || disabled}
                                error={form.errors.site_id}
                                onCreatingChange={setCreatingCompany}
                                onCreate={(name) =>
                                    new Promise<void>((resolve, reject) => {
                                        router.post(
                                            storeCompany(),
                                            { name, inline: true },
                                            {
                                                preserveScroll: true,
                                                errorBag: 'newCompany',
                                                onFlash: (flash) => {
                                                    if (flash.createdCompany) {
                                                        const company =
                                                            flash.createdCompany as CreatedCompany;
                                                        setSelected({
                                                            id: company.site_id,
                                                            label: company.name,
                                                        });
                                                    }
                                                },
                                                onError: (errors) =>
                                                    reject(
                                                        new Error(
                                                            Object.values(
                                                                errors,
                                                            )[0] ??
                                                                'Impossibile creare l’azienda.',
                                                        ),
                                                    ),
                                                onFinish: () => resolve(),
                                            },
                                        );
                                    })
                                }
                            />
                        </div>
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                disabled={
                                    !selected ||
                                    creatingCompany ||
                                    form.processing ||
                                    disabled ||
                                    (selected.id === source.site?.id &&
                                        !!source.confirmed_at)
                                }
                                onClick={() => setConfirm('associate')}
                            >
                                Collega
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={form.processing}
                                onClick={() => setEditing(false)}
                            >
                                Annulla
                            </Button>
                        </div>
                    </div>
                ) : destination ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <MapPin className="size-4 shrink-0 text-muted-foreground" />
                        <span className="break-words">{destination}</span>
                        {!source.confirmed_at ? (
                            <Badge variant="outline">Da riconfermare</Badge>
                        ) : null}
                    </div>
                ) : (
                    <span className="text-muted-foreground">Non collegato</span>
                )}
            </TableCell>
            <TableCell className="align-top">
                <div className="flex justify-end gap-1">
                    <IconButton
                        label={`Anteprima di ${source.name}`}
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={onPreview}
                        disabled={!source.preview}
                    >
                        <Eye />
                    </IconButton>
                    {!readonly && !editing ? (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                disabled || form.processing || creatingCompany
                            }
                            onClick={() => setEditing(true)}
                        >
                            <Link2 data-icon="inline-start" />
                            {source.site ? 'Modifica' : 'Collega'}
                        </Button>
                    ) : null}
                    {!readonly && source.site ? (
                        <IconButton
                            label={`Scollega ${source.name}`}
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            disabled={form.processing}
                            onClick={() => setConfirm('revoke')}
                        >
                            <Unlink />
                        </IconButton>
                    ) : null}
                </div>
                <Dialog
                    open={confirm !== null}
                    onOpenChange={(open) => {
                        if (!open && !form.processing) setConfirm(null);
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {confirm === 'revoke'
                                    ? 'Scollegare il sito?'
                                    : 'Collegare il sito alla sede?'}
                            </DialogTitle>
                            <DialogDescription>
                                {confirm === 'revoke'
                                    ? 'Le nuove acquisizioni per questa sede verranno sospese. I dati documentati saranno conservati.'
                                    : source.site &&
                                        source.site.id !== selected?.id
                                      ? 'Le nuove acquisizioni saranno associate alla nuova sede. I dati documentati nella sede precedente saranno conservati.'
                                      : 'Conferma la destinazione del sito UniFi.'}
                            </DialogDescription>
                        </DialogHeader>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
                            <dt className="text-muted-foreground">
                                Connessione
                            </dt>
                            <dd className="break-words">{connection.name}</dd>
                            <dt className="text-muted-foreground">Console</dt>
                            <dd className="break-words">
                                {source.console_name}
                            </dd>
                            <dt className="text-muted-foreground">
                                Sito UniFi
                            </dt>
                            <dd className="break-words">{source.name}</dd>
                            {destination ? (
                                <>
                                    <dt className="text-muted-foreground">
                                        Sede attuale
                                    </dt>
                                    <dd className="break-words">
                                        {destination}
                                    </dd>
                                </>
                            ) : null}
                            {confirm === 'associate' ? (
                                <>
                                    <dt className="text-muted-foreground">
                                        Destinazione
                                    </dt>
                                    <dd className="font-medium break-words">
                                        {selected?.label}
                                    </dd>
                                </>
                            ) : null}
                        </dl>
                        {form.hasErrors ? (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {Object.values(form.errors).join(' ')}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setConfirm(null)}
                                disabled={form.processing}
                            >
                                Annulla
                            </Button>
                            <Button
                                variant={
                                    confirm === 'revoke'
                                        ? 'destructive'
                                        : 'default'
                                }
                                disabled={form.processing}
                                onClick={() => {
                                    form.transform(() => ({
                                        site_id: selected?.id ?? 0,
                                        revision: connection.revision,
                                        confirmed: true,
                                    }));
                                    form.submit(
                                        confirm === 'revoke'
                                            ? revoke({
                                                  unifiConnection:
                                                      connection.id,
                                                  unifiSite: source.id,
                                              })
                                            : associate({
                                                  unifiConnection:
                                                      connection.id,
                                                  unifiSite: source.id,
                                              }),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setConfirm(null);
                                                setEditing(false);
                                            },
                                        },
                                    );
                                }}
                            >
                                {form.processing ? <Spinner /> : null}
                                {confirm === 'revoke' ? 'Scollega' : 'Collega'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </TableCell>
        </TableRow>
    );
}
