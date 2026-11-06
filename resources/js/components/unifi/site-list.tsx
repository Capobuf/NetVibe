import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Eye,
    MoreHorizontal,
    Pencil,
    Server,
    Unlink,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { CatalogCombobox } from '@/components/devices/catalog-combobox';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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

type Destination = { id: number; name: string };

/**
 * Mapping table: UniFi consoles and sites on the left, the NetVibe company
 * and site they feed on the right, editable in place.
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
    const [pendingConsoles, setPendingConsoles] = useState<string[] | null>(
        null,
    );
    const [reading, setReading] = useState<string | null>(null);
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
    const removed = pendingConsoles
        ? connection.sites.filter(
              (source) =>
                  source.site && !pendingConsoles.includes(source.console_id),
          )
        : [];
    const destinations = companies.flatMap((company) =>
        company.sites.map((site) => ({
            id: site.id,
            name: site.name ? `${company.name} · ${site.name}` : company.name,
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

    function saveConsoles(ids: string[]) {
        consoleForm.transform(() => ({
            selected_console_ids: ids,
            revision: connection.revision,
        }));
        consoleForm.submit(selectConsoles(connection.id), {
            preserveScroll: true,
            onSuccess: () => setPendingConsoles(null),
            onFinish: () => setReading(null),
        });
    }

    function toggleConsole(id: string, include: boolean) {
        const ids = include
            ? [...saved, id]
            : saved.filter((item) => item !== id);
        // Excluding a console with linked sites suspends their acquisitions: confirm it.
        if (
            !include &&
            connection.sites.some(
                (source) => source.site && source.console_id === id,
            )
        )
            setPendingConsoles(ids);
        else {
            if (include) setReading(id);
            saveConsoles(ids);
        }
    }

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="py-4">
                <CardTitle>
                    {selectable ? 'Console da integrare' : 'Siti UniFi'}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-0 px-0">
                {consoleForm.hasErrors && !pendingConsoles ? (
                    <Alert variant="destructive" className="mx-4 mb-4 w-auto">
                        <AlertDescription>
                            {Object.values(consoleForm.errors).join(' ')}
                        </AlertDescription>
                    </Alert>
                ) : null}
                {consoles.length ? (
                    <Table aria-label="Collegamento dei siti UniFi alle sedi NetVibe">
                        <TableHeader className="bg-muted/50">
                            <TableRow>
                                <TableHead scope="col" className="w-1/2">
                                    UniFi
                                </TableHead>
                                <TableHead scope="col" className="w-10">
                                    <span className="sr-only">
                                        Collegamento
                                    </span>
                                </TableHead>
                                <TableHead scope="col" className="w-1/2">
                                    NetVibe
                                </TableHead>
                                <TableHead scope="col" className="w-12">
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
                                    !selectable || saved.includes(item.id);
                                const failure = (connection.hosts ?? []).find(
                                    (host) =>
                                        host.id === item.id && host.status,
                                );
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
                                                                toggleConsole(
                                                                    item.id,
                                                                    checked ===
                                                                        true,
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
                                                    {!included ? (
                                                        <span className="text-sm text-muted-foreground">
                                                            Non integrata
                                                        </span>
                                                    ) : failure ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="text-amber-700 dark:text-amber-500"
                                                            title={
                                                                failure.message ??
                                                                undefined
                                                            }
                                                        >
                                                            <AlertTriangle />
                                                            {failure.status ===
                                                            'forbidden'
                                                                ? 'Non accessibile con questa chiave API'
                                                                : failure.message}
                                                        </Badge>
                                                    ) : null}
                                                    {consoleForm.processing ? (
                                                        <Spinner />
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
                                                disabled={
                                                    disabled ||
                                                    !included ||
                                                    !connection.enabled ||
                                                    !source.available
                                                }
                                                dimmed={!included}
                                                onPreview={() =>
                                                    setPreviewId(source.id)
                                                }
                                            />
                                        ))}
                                        {(included &&
                                            !sites.length &&
                                            !failure) ||
                                        reading === item.id ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="pl-10 text-sm text-muted-foreground"
                                                >
                                                    {reading === item.id ? (
                                                        <span className="flex items-center gap-2">
                                                            <Spinner />
                                                            Lettura dei siti…
                                                        </span>
                                                    ) : (
                                                        'Nessun sito letto'
                                                    )}
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
                open={pendingConsoles !== null}
                onOpenChange={(open) => {
                    if (!open && !consoleForm.processing)
                        setPendingConsoles(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Escludere la console?</DialogTitle>
                        <DialogDescription>
                            Le nuove acquisizioni di questi siti verranno
                            sospese. I dati documentati saranno conservati.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="flex max-h-48 flex-col gap-2 overflow-y-auto text-sm">
                        {removed.map((source) => (
                            <li key={source.id}>
                                {source.console_name} · {source.name} →{' '}
                                {[source.site?.company.name, source.site?.name]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </li>
                        ))}
                    </ul>
                    {consoleForm.hasErrors ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {Object.values(consoleForm.errors).join(' ')}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPendingConsoles(null)}
                            disabled={consoleForm.processing}
                        >
                            Annulla
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={consoleForm.processing}
                            onClick={() => saveConsoles(pendingConsoles ?? [])}
                        >
                            {consoleForm.processing ? <Spinner /> : null}
                            Escludi
                        </Button>
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
    disabled,
    dimmed,
    onPreview,
}: {
    source: UnifiSite;
    connection: UnifiConnection;
    destinations: Destination[];
    readonly: boolean;
    disabled: boolean;
    dimmed: boolean;
    onPreview: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [target, setTarget] = useState<Destination | null>(null);
    const [confirm, setConfirm] = useState<'change' | 'revoke' | null>(null);
    const form = useForm({
        site_id: 0,
        revision: connection.revision ?? '',
        confirmed: true,
    });
    const via = source.linked_via;
    const site = source.site ?? via?.site ?? null;
    const label = site
        ? [site.company.name, site.name].filter(Boolean).join(' · ')
        : null;
    const linked = !!site && !dimmed;
    const editable = !readonly && !via;
    const busy = form.processing || disabled;

    function link(siteId: number) {
        form.transform(() => ({
            site_id: siteId,
            revision: connection.revision,
            confirmed: true,
        }));
        form.submit(
            associate({ unifiConnection: connection.id, unifiSite: source.id }),
            { preserveScroll: true, onSuccess: () => setConfirm(null) },
        );
    }

    function unlink() {
        form.transform(() => ({
            site_id: 0,
            revision: connection.revision,
            confirmed: true,
        }));
        form.submit(
            revoke({ unifiConnection: connection.id, unifiSite: source.id }),
            { preserveScroll: true, onSuccess: () => setConfirm(null) },
        );
    }

    function choose(value: string) {
        const next =
            destinations.find((item) => String(item.id) === value) ?? null;
        if (!next || next.id === source.site?.id) return;
        // A first link is immediate; moving an existing link changes where data lands.
        if (!source.site) link(next.id);
        else {
            setTarget(next);
            setConfirm('change');
        }
    }

    const selector = (
        <CatalogCombobox
            id={`unifi-site-${source.id}`}
            name="site_id"
            items={destinations}
            value=""
            onChange={choose}
            placeholder={
                source.site ? 'Scegli la nuova sede…' : 'Collega a una sede…'
            }
            createLabel="Aggiungi azienda"
            required
            disabled={busy}
            error={confirm ? undefined : Object.values(form.errors)[0]}
            onCreate={(name) =>
                new Promise<void>((resolve, reject) => {
                    let created: Destination | null = null;
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
                                    created = {
                                        id: company.site_id,
                                        name: company.name,
                                    };
                                }
                            },
                            // Link only after the company request has finished.
                            onSuccess: () => {
                                if (!created) return;
                                if (!source.site) link(created.id);
                                else {
                                    setTarget(created);
                                    setConfirm('change');
                                }
                            },
                            onError: (errors) =>
                                reject(
                                    new Error(
                                        Object.values(errors)[0] ??
                                            'Impossibile creare l’azienda.',
                                    ),
                                ),
                            onFinish: () => resolve(),
                        },
                    );
                })
            }
        />
    );

    return (
        <TableRow className={cn(dimmed && 'opacity-60')}>
            <TableCell className="pl-10 whitespace-normal">
                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium break-words">
                            {source.name}
                        </span>
                        {!source.available ? (
                            <Badge variant="outline">Non disponibile</Badge>
                        ) : null}
                    </div>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {[
                            source.version ? `Network ${source.version}` : null,
                            source.preview
                                ? `${source.preview.devices.length} dispositivi`
                                : 'Dati non ancora acquisiti',
                            source.preview
                                ? source.preview.networks === null
                                    ? 'reti non supportate'
                                    : `${source.preview.networks.length} reti`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                </div>
            </TableCell>
            <TableCell>
                <ArrowRight
                    className={cn(
                        'size-4',
                        linked ? 'text-primary' : 'text-muted-foreground/40',
                    )}
                    aria-label={linked ? 'Collegato' : 'Non collegato'}
                />
            </TableCell>
            <TableCell className="whitespace-normal">
                {editable && (!source.site || editing) ? (
                    <div className="flex max-w-md items-start gap-2">
                        <div className="min-w-0 flex-1">{selector}</div>
                        {editing ? (
                            <Button
                                variant="ghost"
                                disabled={form.processing}
                                onClick={() => {
                                    setEditing(false);
                                    form.clearErrors();
                                }}
                            >
                                Annulla
                            </Button>
                        ) : null}
                        {form.processing ? <Spinner className="mt-3" /> : null}
                    </div>
                ) : label ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium break-words">{label}</span>
                        {via ? (
                            <Badge variant="secondary">
                                tramite {via.connection}
                            </Badge>
                        ) : null}
                        {editable && !source.confirmed_at ? (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() => link(source.site!.id)}
                            >
                                {form.processing ? <Spinner /> : null}
                                Conferma
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <span className="text-muted-foreground">Non collegato</span>
                )}
            </TableCell>
            <TableCell className="text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Azioni per ${source.name}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            disabled={!source.preview}
                            onSelect={onPreview}
                        >
                            <Eye />
                            Anteprima
                        </DropdownMenuItem>
                        {editable && source.site ? (
                            <>
                                <DropdownMenuItem
                                    disabled={busy}
                                    onSelect={() => setEditing(true)}
                                >
                                    <Pencil />
                                    Cambia sede…
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    disabled={form.processing}
                                    onSelect={() => setConfirm('revoke')}
                                >
                                    <Unlink />
                                    Scollega
                                </DropdownMenuItem>
                            </>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
                <Dialog
                    open={confirm !== null}
                    onOpenChange={(open) => {
                        if (!open && !form.processing) {
                            setConfirm(null);
                            form.clearErrors();
                        }
                    }}
                >
                    <DialogContent className="text-left">
                        <DialogHeader>
                            <DialogTitle>
                                {confirm === 'revoke'
                                    ? 'Scollegare il sito?'
                                    : 'Cambiare la sede collegata?'}
                            </DialogTitle>
                            <DialogDescription>
                                {confirm === 'revoke'
                                    ? 'Le nuove acquisizioni per questa sede verranno sospese. I dati documentati saranno conservati.'
                                    : 'Le nuove acquisizioni andranno alla nuova sede. I dati documentati nella sede precedente saranno conservati.'}
                            </DialogDescription>
                        </DialogHeader>
                        <p className="text-sm">
                            {source.console_name} · {source.name}
                            <ArrowRight className="mx-2 inline size-4 text-muted-foreground" />
                            {confirm === 'revoke' ? (
                                <span className="text-muted-foreground line-through">
                                    {label}
                                </span>
                            ) : (
                                <span className="font-medium">
                                    {target?.name}
                                </span>
                            )}
                        </p>
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
                                onClick={() =>
                                    confirm === 'revoke'
                                        ? unlink()
                                        : link(target!.id)
                                }
                            >
                                {form.processing ? <Spinner /> : null}
                                {confirm === 'revoke'
                                    ? 'Scollega'
                                    : 'Cambia sede'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </TableCell>
        </TableRow>
    );
}
