import { router, useForm } from '@inertiajs/react';
import { Eye, Link2, MapPin, Server, Unlink } from 'lucide-react';
import { useState } from 'react';
import { CatalogCombobox } from '@/components/devices/catalog-combobox';
import { store as storeCompany } from '@/routes/administration/companies';
import type { CreatedCompany } from '@/types/company';
import {
    associate,
    revoke,
} from '@/actions/App/Http/Controllers/UnifiController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { UnifiSitePreview } from './site-preview';
import type { UnifiCompany, UnifiConnection, UnifiSite } from './types';

type Destination = { id: number; label: string };

export function UnifiSiteList({
    connection,
    companies,
    search,
    readonly,
}: {
    connection: UnifiConnection;
    companies: UnifiCompany[];
    search: string;
    readonly: boolean;
}) {
    const [previewId, setPreviewId] = useState<number | null>(null);
    const preview = connection.sites.find((source) => source.id === previewId);
    const destinations = companies.flatMap((company) =>
        company.sites.map((site) => ({
            id: site.id,
            label: site.name ? `${company.name} · ${site.name}` : company.name,
        })),
    );
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
            .includes(search.trim().toLocaleLowerCase()),
    );
    const consoles = [...new Set(sources.map((source) => source.console_id))];

    return (
        <div className="flex min-w-0 flex-col gap-4">
            {consoles.map((consoleId) => {
                const sites = sources.filter(
                    (source) => source.console_id === consoleId,
                );
                return (
                    <Card key={consoleId}>
                        <CardHeader>
                            <CardTitle className="flex flex-wrap items-center gap-2">
                                <Server className="size-4 text-muted-foreground" />
                                {sites[0].console_name}
                                <Badge variant="outline">
                                    {sites.length}{' '}
                                    {sites.length === 1 ? 'sito' : 'siti'}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-5">
                            {sites.map((source, position) => (
                                <div
                                    key={source.id}
                                    className="flex flex-col gap-5"
                                >
                                    {position > 0 ? <Separator /> : null}
                                    <SiteRow
                                        key={`${source.id}:${source.site?.id ?? ''}:${source.confirmed_at ?? ''}`}
                                        source={source}
                                        connection={connection}
                                        destinations={destinations}
                                        readonly={readonly}
                                        onPreview={() =>
                                            setPreviewId(source.id)
                                        }
                                    />
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                );
            })}
            {!sources.length ? (
                <Empty className="border">
                    <EmptyHeader>
                        <EmptyTitle>
                            {search
                                ? 'Nessun sito corrisponde alla ricerca'
                                : connection.discovered_at
                                  ? 'Nessun sito disponibile'
                                  : 'Dati non ancora acquisiti'}
                        </EmptyTitle>
                    </EmptyHeader>
                </Empty>
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
        </div>
    );
}

function SiteRow({
    source,
    connection,
    destinations,
    readonly,
    onPreview,
}: {
    source: UnifiSite;
    connection: UnifiConnection;
    destinations: Destination[];
    readonly: boolean;
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
    const disabled = !connection.enabled || !source.available;

    return (
        <>
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex min-w-0 flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-medium break-words">
                            {source.name}
                        </h3>
                        {!source.available ? (
                            <Badge variant="outline">Non disponibile</Badge>
                        ) : null}
                        {source.version ? (
                            <Badge variant="outline">
                                Network {source.version}
                            </Badge>
                        ) : null}
                        {source.site && !source.confirmed_at ? (
                            <Badge variant="outline">Da riconfermare</Badge>
                        ) : null}
                    </div>
                    {destination ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <MapPin className="size-4 shrink-0" />
                            {destination}
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Sede non collegata
                        </p>
                    )}
                    <p className="text-xs text-muted-foreground tabular-nums">
                        {source.preview
                            ? `${source.preview.devices.length} dispositivi · ${source.preview.networks === null ? 'reti non supportate' : `${source.preview.networks.length} reti`}`
                            : 'Dati non ancora acquisiti'}
                    </p>
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                    <Button
                        variant="outline"
                        onClick={onPreview}
                        disabled={!source.preview}
                    >
                        <Eye data-icon="inline-start" />
                        Anteprima
                    </Button>
                    {!readonly ? (
                        <>
                            <Button
                                variant="outline"
                                disabled={
                                    disabled ||
                                    form.processing ||
                                    creatingCompany
                                }
                                onClick={() => setEditing(!editing)}
                            >
                                <Link2 data-icon="inline-start" />
                                {editing
                                    ? 'Annulla'
                                    : source.site
                                      ? 'Modifica sede'
                                      : 'Collega sede'}
                            </Button>
                            {source.site ? (
                                <Button
                                    variant="ghost"
                                    disabled={form.processing}
                                    onClick={() => setConfirm('revoke')}
                                >
                                    <Unlink data-icon="inline-start" />
                                    Scollega
                                </Button>
                            ) : null}
                        </>
                    ) : null}
                </div>
            </div>
            {editing && !readonly ? (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <Field
                        className="max-w-lg"
                        data-invalid={!!form.errors.site_id}
                    >
                        <FieldLabel htmlFor={`unifi-site-${source.id}`}>
                            Azienda e sede
                        </FieldLabel>
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
                    </Field>
                    <Button
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
                        Continua
                    </Button>
                </div>
            ) : null}
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
                                : source.site && source.site.id !== selected?.id
                                  ? 'Le nuove acquisizioni saranno associate alla nuova sede. I dati documentati nella sede precedente saranno conservati.'
                                  : 'Conferma la destinazione del sito UniFi.'}
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
                        <dt className="text-muted-foreground">Connessione</dt>
                        <dd className="break-words">{connection.name}</dd>
                        <dt className="text-muted-foreground">Console</dt>
                        <dd className="break-words">{source.console_name}</dd>
                        <dt className="text-muted-foreground">Sito UniFi</dt>
                        <dd className="break-words">{source.name}</dd>
                        {destination ? (
                            <>
                                <dt className="text-muted-foreground">
                                    Sede attuale
                                </dt>
                                <dd className="break-words">{destination}</dd>
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
                                confirm === 'revoke' ? 'destructive' : 'default'
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
                                              unifiConnection: connection.id,
                                              unifiSite: source.id,
                                          })
                                        : associate({
                                              unifiConnection: connection.id,
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
        </>
    );
}
