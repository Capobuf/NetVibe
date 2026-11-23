import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ExternalLink,
    FolderOpen,
    MoreHorizontal,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    Unplug,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { GoogleDriveMark } from '@/components/google-drive-mark';
import { IconButton } from '@/components/icon-button';
import {
    IntegrationHeader,
    IntegrationStatus,
    StatusDetails,
    intervalLabel,
    type IntegrationState,
} from '@/components/integration-header';
import { IntegrationTabs } from '@/components/integration-tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemTitle,
} from '@/components/ui/item';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    pickFolder,
    type DriveFolder,
    type PickerConfig,
} from '@/lib/google-picker';
import { formatDateTime } from '@/lib/utils';
import { index as administration } from '@/routes/administration';
import { index as integrations } from '@/routes/administration/integrations';
import {
    connect,
    destroy,
    disconnect,
    index as driveIndex,
    store,
    sync as runSync,
    token,
    update,
} from '@/routes/administration/integrations/google-drive';
import { index as companyDrive } from '@/routes/companies/settings/integrations/google-drive';
import type { Company } from '@/types/company';

type Account = { id: number; email: string; syncs_count: number };
type Sync = {
    id: number;
    folder_id: string;
    folder_name: string;
    interval: number;
    file_url: string | null;
    synced_at: string | null;
    error: string | null;
    company: Company;
    account: { id: number; email: string } | null;
};
type Props = {
    company: Company | null;
    configured: boolean;
    picker: PickerConfig | null;
    accounts: Account[];
    syncs: Sync[];
    companies: Company[];
    intervals: number[];
};
type Editing = { sync: Sync } | { companies: Company[] };
type Confirm = { sync: Sync } | { account: Account };

const capitalize = (text: string) =>
    text.charAt(0).toUpperCase() + text.slice(1);

function syncState(sync: Sync): IntegrationState {
    if (sync.error) return 'error';
    if (!sync.account) return 'suspended';
    return sync.synced_at ? 'active' : 'pending';
}

export default function GoogleDrive(props: Props) {
    const { company, configured, accounts, syncs, companies } = props;
    const [editing, setEditing] = useState<Editing | null>(null);
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [removing, setRemoving] = useState(false);
    const [running, setRunning] = useState<number | null>(null);
    const available = company ? (syncs.length ? [] : [company]) : companies;
    const canAdd = configured && accounts.length > 0 && available.length > 0;
    const state: IntegrationState =
        !configured || !accounts.length
            ? 'unconfigured'
            : syncs.some((sync) => sync.error)
              ? 'error'
              : syncs.length
                ? 'active'
                : 'pending';

    function run(sync: Sync) {
        router.post(
            runSync(sync.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setRunning(sync.id),
                onFinish: () => setRunning(null),
            },
        );
    }

    function remove() {
        if (!confirm) return;
        router.delete(
            'sync' in confirm
                ? destroy(confirm.sync.id).url
                : disconnect(confirm.account.id).url,
            {
                preserveScroll: true,
                onStart: () => setRemoving(true),
                onFinish: () => setRemoving(false),
                onSuccess: () => setConfirm(null),
            },
        );
    }

    return (
        <>
            <Head title={`Google Drive · ${company?.name ?? 'Integrazioni'}`} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                {!company ? (
                    <h1 className="text-2xl font-medium tracking-tight">
                        Integrazioni
                    </h1>
                ) : null}
                <IntegrationTabs value="google-drive" company={company}>
                    <IntegrationHeader
                        mark={<GoogleDriveMark className="h-5" />}
                        title="Google Drive"
                        state={state}
                        description={
                            company
                                ? syncs[0]
                                    ? `Foglio IPAM aggiornato ${intervalLabel(syncs[0].interval)}`
                                    : 'Nessuna sincronizzazione'
                                : [
                                      syncs.length === 1
                                          ? '1 azienda sincronizzata'
                                          : `${syncs.length} aziende sincronizzate`,
                                      accounts.length === 1
                                          ? '1 account'
                                          : `${accounts.length} account`,
                                  ].join(' · ')
                        }
                    />
                    {!configured ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                Configurazione Google mancante: imposta
                                GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET,
                                GOOGLE_API_KEY e GOOGLE_APP_ID nel file .env del
                                server.
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <Card>
                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                            <CardTitle>
                                {company ? 'Sincronizzazione' : 'Aziende'}
                            </CardTitle>
                            {canAdd ? (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setEditing({
                                            companies: available,
                                        })
                                    }
                                >
                                    <Plus data-icon="inline-start" />
                                    {company ? 'Configura' : 'Aggiungi aziende'}
                                </Button>
                            ) : null}
                        </CardHeader>
                        <CardContent>
                            {syncs.length ? (
                                <ItemGroup className="gap-2">
                                    {syncs.map((sync) => (
                                        <SyncRow
                                            key={sync.id}
                                            sync={sync}
                                            inCompany={company !== null}
                                            running={running === sync.id}
                                            onRun={() => run(sync)}
                                            onEdit={() => setEditing({ sync })}
                                            onRemove={() =>
                                                setConfirm({ sync })
                                            }
                                        />
                                    ))}
                                </ItemGroup>
                            ) : (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <GoogleDriveMark />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            {company
                                                ? 'IPAM non sincronizzato su Drive'
                                                : 'Nessuna azienda sincronizzata'}
                                        </EmptyTitle>
                                    </EmptyHeader>
                                </Empty>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                            <CardTitle>Account Google</CardTitle>
                            {configured ? (
                                <Button asChild size="sm" variant="outline">
                                    <a href={connect.url()}>
                                        <Plus data-icon="inline-start" />
                                        Collega account
                                    </a>
                                </Button>
                            ) : null}
                        </CardHeader>
                        <CardContent>
                            {accounts.length ? (
                                <ItemGroup className="gap-2">
                                    {accounts.map((account) => (
                                        <Item
                                            key={account.id}
                                            variant="outline"
                                            size="sm"
                                            role="listitem"
                                        >
                                            <ItemContent className="min-w-0">
                                                <ItemTitle className="break-all">
                                                    {account.email}
                                                </ItemTitle>
                                                <ItemDescription>
                                                    {account.syncs_count === 1
                                                        ? 'Usato da 1 azienda'
                                                        : account.syncs_count
                                                          ? `Usato da ${account.syncs_count} aziende`
                                                          : 'Non in uso'}
                                                </ItemDescription>
                                            </ItemContent>
                                            <ItemActions>
                                                <IconButton
                                                    label="Scollega account"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setConfirm({ account })
                                                    }
                                                >
                                                    <Unplug />
                                                </IconButton>
                                            </ItemActions>
                                        </Item>
                                    ))}
                                </ItemGroup>
                            ) : (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            Nessun account collegato
                                        </EmptyTitle>
                                    </EmptyHeader>
                                </Empty>
                            )}
                        </CardContent>
                    </Card>
                </IntegrationTabs>
            </div>
            {editing ? (
                <SyncEditor
                    key={'sync' in editing ? editing.sync.id : 'new'}
                    editing={editing}
                    props={props}
                    onClose={() => setEditing(null)}
                />
            ) : null}
            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => {
                    if (!open && !removing) setConfirm(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirm && 'sync' in confirm
                                ? `Rimuovere la sincronizzazione di ${confirm.sync.company.name}?`
                                : `Scollegare ${confirm?.account.email}?`}
                        </DialogTitle>
                        <DialogDescription>
                            {confirm && 'sync' in confirm
                                ? `Il foglio già scritto resta nella cartella «${confirm.sync.folder_name}».`
                                : confirm?.account.syncs_count
                                  ? `Le sincronizzazioni di ${confirm.account.syncs_count === 1 ? '1 azienda' : `${confirm.account.syncs_count} aziende`} si fermeranno finché non scegli un altro account.`
                                  : 'NetVibe non potrà più scrivere su Drive con questo account.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={removing}
                            onClick={() => setConfirm(null)}
                        >
                            Annulla
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={removing}
                            onClick={remove}
                        >
                            {removing ? <Spinner /> : null}
                            {confirm && 'sync' in confirm
                                ? 'Rimuovi'
                                : 'Scollega'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function SyncRow({
    sync,
    inCompany,
    running,
    onRun,
    onEdit,
    onRemove,
}: {
    sync: Sync;
    inCompany: boolean;
    running: boolean;
    onRun: () => void;
    onEdit: () => void;
    onRemove: () => void;
}) {
    const last = sync.synced_at
        ? `aggiornato il ${formatDateTime(sync.synced_at)}`
        : 'mai scritto';

    return (
        <Item variant="outline" size="sm" role="listitem">
            <ItemContent className="min-w-0">
                <ItemTitle className="flex-wrap">
                    {inCompany ? (
                        <span className="inline-flex items-center gap-1.5 break-all">
                            <FolderOpen className="size-4 shrink-0 text-muted-foreground" />
                            {sync.folder_name}
                        </span>
                    ) : (
                        <Link
                            href={companyDrive(sync.company.id)}
                            className="rounded-sm outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            {sync.company.name}
                        </Link>
                    )}
                    <IntegrationStatus
                        state={syncState(sync)}
                        details={
                            <StatusDetails
                                items={[
                                    ['Account', sync.account?.email ?? '—'],
                                    ['Cartella', sync.folder_name],
                                    [
                                        'Frequenza',
                                        capitalize(
                                            intervalLabel(sync.interval),
                                        ),
                                    ],
                                    [
                                        'Ultima scrittura',
                                        sync.synced_at
                                            ? formatDateTime(sync.synced_at)
                                            : '—',
                                    ],
                                ]}
                            />
                        }
                    />
                </ItemTitle>
                <ItemDescription className="line-clamp-none">
                    {sync.error ? (
                        <span className="text-destructive">{sync.error}</span>
                    ) : (
                        [
                            inCompany
                                ? sync.account?.email
                                : `Cartella «${sync.folder_name}»`,
                            intervalLabel(sync.interval),
                            last,
                        ].join(' · ')
                    )}
                </ItemDescription>
            </ItemContent>
            <ItemActions>
                {sync.file_url ? (
                    <IconButton label="Apri il foglio" variant="ghost" asChild>
                        <a
                            href={sync.file_url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink />
                        </a>
                    </IconButton>
                ) : null}
                <Button
                    variant="outline"
                    size="sm"
                    disabled={running || !sync.account}
                    onClick={onRun}
                >
                    {running ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <RefreshCw data-icon="inline-start" />
                    )}
                    Sincronizza ora
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Altre azioni"
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={onEdit}>
                            <Pencil />
                            Modifica
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={onRemove}
                        >
                            <Trash2 />
                            Rimuovi
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </ItemActions>
        </Item>
    );
}

/** Account, frequency and folder of one sync, or of several new ones (one folder per company). */
function SyncEditor({
    editing,
    props,
    onClose,
}: {
    editing: Editing;
    props: Props;
    onClose: () => void;
}) {
    const { accounts, intervals, picker } = props;
    const existing = 'sync' in editing ? editing.sync : null;
    const options = 'companies' in editing ? editing.companies : [];
    const single = existing
        ? [existing.company]
        : options.length === 1
          ? options
          : null;
    const form = useForm({
        account_id: String(existing?.account?.id ?? accounts[0]?.id ?? ''),
        interval: String(existing?.interval ?? 1440),
    });
    // Selected companies with the folder chosen for each; a key present means selected.
    const [folders, setFolders] = useState<Record<number, DriveFolder | null>>(
        () =>
            existing
                ? {
                      [existing.company.id]: {
                          id: existing.folder_id,
                          name: existing.folder_name,
                      },
                  }
                : single
                  ? { [single[0].id]: null }
                  : {},
    );
    const [picking, setPicking] = useState(false);
    const selected = Object.entries(folders);
    const complete =
        form.data.account_id !== '' &&
        selected.length > 0 &&
        selected.every(([, folder]) => folder !== null);

    async function choose(companyId: number) {
        if (!picker || !form.data.account_id) return;
        // The Picker lives outside the dialog, which would otherwise block its clicks.
        setPicking(true);
        try {
            const folder = await pickFolder(
                token(Number(form.data.account_id)).url,
                picker,
            );
            if (folder) {
                // Selected companies still without a folder get the same one; each stays editable.
                setFolders((current) =>
                    Object.fromEntries(
                        Object.entries(current).map(([id, value]) => [
                            id,
                            Number(id) === companyId || value === null
                                ? folder
                                : value,
                        ]),
                    ),
                );
            }
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setPicking(false);
        }
    }

    function changeAccount(value: string) {
        form.setData('account_id', value);
        // Folders picked with another account may be out of reach for this one.
        setFolders((current) =>
            Object.fromEntries(Object.keys(current).map((id) => [id, null])),
        );
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();
        const payload = {
            account_id: Number(form.data.account_id),
            interval: Number(form.data.interval),
        };
        const options = { preserveScroll: true, onSuccess: onClose };
        if (existing) {
            const folder = folders[existing.company.id];
            form.transform(() => ({
                ...payload,
                folder_id: folder?.id ?? '',
                folder_name: folder?.name ?? '',
            }));
            form.put(update(existing.id).url, options);
        } else {
            form.transform(() => ({
                ...payload,
                targets: selected.map(([id, folder]) => ({
                    company_id: Number(id),
                    folder_id: folder?.id ?? '',
                    folder_name: folder?.name ?? '',
                })),
            }));
            form.post(store().url, options);
        }
    }

    const errors = Object.entries(form.errors)
        .filter(([key]) => key !== 'account_id' && key !== 'interval')
        .map(([, message]) => message);

    return (
        <Dialog
            open={!picking}
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <DialogHeader>
                        <DialogTitle>
                            {existing
                                ? `Sincronizzazione di ${existing.company.name}`
                                : single
                                  ? `Sincronizza ${single[0].name} su Drive`
                                  : 'Sincronizza aziende su Drive'}
                        </DialogTitle>
                        {existing ? (
                            <DialogDescription>
                                Con un altro account o un’altra cartella il
                                foglio viene scritto nella nuova destinazione;
                                quello attuale resta su Drive.
                            </DialogDescription>
                        ) : null}
                    </DialogHeader>
                    <FieldGroup>
                        <Field data-invalid={!!form.errors.account_id}>
                            <FieldLabel htmlFor="drive-account">
                                Account Google
                            </FieldLabel>
                            <div className="flex gap-2">
                                <Select
                                    value={form.data.account_id}
                                    onValueChange={changeAccount}
                                >
                                    <SelectTrigger
                                        id="drive-account"
                                        className="min-w-0 flex-1"
                                    >
                                        <SelectValue placeholder="Scegli un account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.email}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <IconButton
                                    label="Collega un altro account"
                                    asChild
                                >
                                    <a href={connect.url()}>
                                        <Plus />
                                    </a>
                                </IconButton>
                            </div>
                            <FieldError>{form.errors.account_id}</FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.interval}>
                            <FieldLabel htmlFor="drive-interval">
                                Frequenza
                            </FieldLabel>
                            <Select
                                value={form.data.interval}
                                onValueChange={(value) =>
                                    form.setData('interval', value)
                                }
                            >
                                <SelectTrigger id="drive-interval">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {intervals.map((minutes) => (
                                        <SelectItem
                                            key={minutes}
                                            value={String(minutes)}
                                        >
                                            {capitalize(intervalLabel(minutes))}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <FieldError>{form.errors.interval}</FieldError>
                        </Field>
                        {single ? (
                            <Field>
                                <FieldLabel>Cartella</FieldLabel>
                                <FolderButton
                                    folder={folders[single[0].id] ?? null}
                                    disabled={!form.data.account_id}
                                    onClick={() => choose(single[0].id)}
                                />
                            </Field>
                        ) : (
                            <FieldSet>
                                <FieldLegend variant="label">
                                    Aziende e cartelle
                                </FieldLegend>
                                <ItemGroup className="max-h-72 gap-2 overflow-y-auto">
                                    {options.map((option) => {
                                        const checked = option.id in folders;
                                        return (
                                            <Item
                                                key={option.id}
                                                variant="outline"
                                                size="sm"
                                                role="listitem"
                                            >
                                                <Checkbox
                                                    id={`drive-company-${option.id}`}
                                                    checked={checked}
                                                    onCheckedChange={(value) =>
                                                        setFolders(
                                                            (current) => {
                                                                const next = {
                                                                    ...current,
                                                                };
                                                                if (value)
                                                                    next[
                                                                        option.id
                                                                    ] = null;
                                                                else
                                                                    delete next[
                                                                        option
                                                                            .id
                                                                    ];
                                                                return next;
                                                            },
                                                        )
                                                    }
                                                />
                                                <ItemContent className="min-w-0">
                                                    <label
                                                        htmlFor={`drive-company-${option.id}`}
                                                        className="truncate text-sm font-medium"
                                                    >
                                                        {option.name}
                                                    </label>
                                                </ItemContent>
                                                {checked ? (
                                                    <ItemActions>
                                                        <FolderButton
                                                            folder={
                                                                folders[
                                                                    option.id
                                                                ]
                                                            }
                                                            disabled={
                                                                !form.data
                                                                    .account_id
                                                            }
                                                            onClick={() =>
                                                                choose(
                                                                    option.id,
                                                                )
                                                            }
                                                        />
                                                    </ItemActions>
                                                ) : null}
                                            </Item>
                                        );
                                    })}
                                </ItemGroup>
                            </FieldSet>
                        )}
                    </FieldGroup>
                    {errors.length ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {[...new Set(errors)].join(' ')}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            Annulla
                        </Button>
                        <Button
                            type="submit"
                            disabled={!complete || form.processing}
                        >
                            {form.processing ? <Spinner /> : null}
                            {existing ? 'Salva' : 'Conferma'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FolderButton({
    folder,
    disabled,
    onClick,
}: {
    folder: DriveFolder | null;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={disabled}
            onClick={onClick}
            className="max-w-64 justify-start"
        >
            <FolderOpen data-icon="inline-start" />
            <span className="truncate">
                {folder ? folder.name : 'Scegli cartella'}
            </span>
        </Button>
    );
}

GoogleDrive.layout = ({ company }: Props) => ({
    breadcrumbs: company
        ? []
        : [
              { title: 'Amministrazione', href: administration() },
              { title: 'Integrazioni', href: integrations() },
              { title: 'Google Drive', href: driveIndex() },
          ],
});
