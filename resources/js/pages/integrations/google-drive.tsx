import { Head, Link, router } from '@inertiajs/react';
import {
    CircleUser,
    ExternalLink,
    FolderOpen,
    MoreHorizontal,
    Pencil,
    Plus,
    RefreshCw,
    Settings2,
    Trash2,
    Unplug,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { DataTable } from '@/components/data-table';
import { ProjectSheet } from '@/components/google-drive/project-sheet';
import {
    SetupGuide,
    type SetupStep,
} from '@/components/google-drive/setup-guide';
import { SyncEditor } from '@/components/google-drive/sync-editor';
import {
    capitalize,
    syncState,
    type DriveAccount,
    type DriveProject,
    type DriveSync,
    type Editing,
} from '@/components/google-drive/types';
import { GoogleDriveMark } from '@/components/google-drive-mark';
import { IconButton } from '@/components/icon-button';
import {
    IntegrationHeader,
    IntegrationStatus,
    intervalLabel,
    type IntegrationState,
} from '@/components/integration-header';
import { IntegrationTabs } from '@/components/integration-tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import { Spinner } from '@/components/ui/spinner';
import type { PickerConfig } from '@/lib/google-picker';
import { formatDateTime } from '@/lib/utils';
import { index as administration } from '@/routes/administration';
import { index as integrations } from '@/routes/administration/integrations';
import {
    connect,
    destroy,
    disconnect,
    index as driveIndex,
    sync as runSync,
} from '@/routes/administration/integrations/google-drive';
import { index as companyDrive } from '@/routes/companies/settings/integrations/google-drive';
import type { Company } from '@/types/company';

type Props = {
    company: Company | null;
    project: DriveProject;
    picker: PickerConfig | null;
    accounts: DriveAccount[];
    syncs: DriveSync[];
    companies: Company[];
    intervals: number[];
};
type Confirm = { sync: DriveSync } | { account: DriveAccount };
type Column =
    | 'company'
    | 'folder'
    | 'account'
    | 'interval'
    | 'synced_at'
    | 'state';

const stateLabels: Record<IntegrationState, string> = {
    active: 'Attiva',
    pending: 'Da avviare',
    unconfigured: 'Da configurare',
    suspended: 'Sospesa',
    error: 'Errore',
};

export default function GoogleDrive(props: Props) {
    const { company, project, accounts, syncs, companies } = props;
    const [projectOpen, setProjectOpen] = useState(false);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [removing, setRemoving] = useState(false);
    const [running, setRunning] = useState<number | null>(null);
    const available = company ? (syncs.length ? [] : [company]) : companies;
    const canAdd =
        project.configured && accounts.length > 0 && available.length > 0;
    const state: IntegrationState =
        !project.configured || !accounts.length
            ? 'unconfigured'
            : syncs.some((sync) => sync.error)
              ? 'error'
              : syncs.length
                ? 'active'
                : 'pending';
    const add = () => setEditing({ companies: available });
    const connectButton = (variant: 'default' | 'outline') =>
        project.configured ? (
            <Button asChild size="sm" variant={variant}>
                <a href={connect.url()}>
                    <Plus data-icon="inline-start" />
                    Collega account
                </a>
            </Button>
        ) : (
            <Button size="sm" variant={variant} disabled>
                <Plus data-icon="inline-start" />
                Collega account
            </Button>
        );
    const steps: SetupStep[] = [
        {
            title: 'Progetto Google Cloud',
            done: project.configured,
            status: project.configured
                ? `ID client ${project.client_id}`
                : 'Client OAuth e chiave API da inserire',
            action: (
                <Button size="sm" onClick={() => setProjectOpen(true)}>
                    <Settings2 data-icon="inline-start" />
                    Configura
                </Button>
            ),
        },
        {
            title: 'Account Google',
            done: accounts.length > 0,
            status: accounts.length
                ? accounts.map((account) => account.email).join(', ')
                : 'Nessun account collegato',
            action: connectButton('default'),
        },
        {
            title: company ? 'Cartella di destinazione' : 'Aziende',
            done: syncs.length > 0,
            status: 'Nessuna sincronizzazione',
            action: (
                <Button size="sm" disabled={!canAdd} onClick={add}>
                    <Plus data-icon="inline-start" />
                    {company ? 'Scegli cartella' : 'Aggiungi aziende'}
                </Button>
            ),
        },
    ];

    function run(sync: DriveSync) {
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

    const actions = (sync: DriveSync) => (
        <SyncActions
            sync={sync}
            running={running === sync.id}
            onRun={() => run(sync)}
            onEdit={() => setEditing({ sync })}
            onRemove={() => setConfirm({ sync })}
        />
    );

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
                                    : 'IPAM non sincronizzato'
                                : [
                                      syncs.length === 1
                                          ? '1 azienda sincronizzata'
                                          : `${syncs.length} aziende sincronizzate`,
                                      accounts.length === 1
                                          ? '1 account'
                                          : `${accounts.length} account`,
                                  ].join(' · ')
                        }
                        actions={
                            project.configured ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setProjectOpen(true)}
                                >
                                    <Settings2 data-icon="inline-start" />
                                    Progetto Google
                                </Button>
                            ) : null
                        }
                    />
                    {steps.some((step) => !step.done) ? (
                        <SetupGuide steps={steps} />
                    ) : null}
                    {company && syncs[0] ? (
                        <CompanySync
                            sync={syncs[0]}
                            actions={actions(syncs[0])}
                        />
                    ) : null}
                    {!company && syncs.length ? (
                        <section
                            className="flex flex-col gap-3"
                            aria-labelledby="drive-companies"
                        >
                            <h2
                                id="drive-companies"
                                className="text-base font-medium"
                            >
                                Aziende
                            </h2>
                            <DataTable<DriveSync, Column>
                                name="google-drive-syncs"
                                searchLabel="Cerca aziende"
                                rows={syncs}
                                columns={[
                                    {
                                        id: 'company',
                                        label: 'Azienda',
                                        visible: true,
                                    },
                                    {
                                        id: 'folder',
                                        label: 'Cartella',
                                        visible: true,
                                    },
                                    {
                                        id: 'account',
                                        label: 'Account',
                                        visible: false,
                                    },
                                    {
                                        id: 'interval',
                                        label: 'Frequenza',
                                        visible: true,
                                    },
                                    {
                                        id: 'synced_at',
                                        label: 'Ultima scrittura',
                                        visible: true,
                                    },
                                    {
                                        id: 'state',
                                        label: 'Stato',
                                        visible: true,
                                    },
                                ]}
                                value={(sync, column) =>
                                    ({
                                        company: sync.company.name,
                                        folder: sync.folder_name,
                                        account: sync.account?.email ?? null,
                                        interval: sync.interval,
                                        synced_at: sync.synced_at,
                                        state: stateLabels[syncState(sync)],
                                    })[column]
                                }
                                render={(sync, column) => (
                                    <SyncCell sync={sync} column={column} />
                                )}
                                actions={actions}
                                headerActions={
                                    canAdd ? (
                                        <Button size="sm" onClick={add}>
                                            <Plus data-icon="inline-start" />
                                            Aggiungi aziende
                                        </Button>
                                    ) : null
                                }
                                defaultSort={{
                                    column: 'company',
                                    direction: 'asc',
                                }}
                            />
                        </section>
                    ) : null}
                    {accounts.length ? (
                        <Card>
                            <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                                <CardTitle>Account Google</CardTitle>
                                {connectButton('outline')}
                            </CardHeader>
                            <CardContent>
                                <ItemGroup className="gap-2">
                                    {accounts.map((account) => (
                                        <Item
                                            key={account.id}
                                            variant="outline"
                                            size="sm"
                                            role="listitem"
                                        >
                                            <ItemMedia variant="icon">
                                                <CircleUser />
                                            </ItemMedia>
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
                            </CardContent>
                        </Card>
                    ) : null}
                </IntegrationTabs>
            </div>
            <ProjectSheet
                key={project.client_id ?? 'new'}
                project={project}
                open={projectOpen}
                onOpenChange={setProjectOpen}
            />
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

function FolderName({ sync }: { sync: DriveSync }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <FolderOpen className="size-4 shrink-0 text-muted-foreground" />
            {sync.folder_name}
        </span>
    );
}

function SyncCell({ sync, column }: { sync: DriveSync; column: Column }) {
    switch (column) {
        case 'company':
            return (
                <div className="flex min-w-0 flex-col gap-0.5">
                    <Link
                        href={companyDrive(sync.company.id)}
                        className="w-fit rounded-sm font-medium outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {sync.company.name}
                    </Link>
                    {sync.error ? (
                        <span className="text-xs break-words whitespace-normal text-destructive">
                            {sync.error}
                        </span>
                    ) : null}
                </div>
            );
        case 'folder':
            return <FolderName sync={sync} />;
        case 'interval':
            return capitalize(intervalLabel(sync.interval));
        case 'synced_at':
            return sync.synced_at ? (
                <span className="tabular-nums">
                    {formatDateTime(sync.synced_at)}
                </span>
            ) : (
                <span className="text-muted-foreground">Mai</span>
            );
        case 'state':
            return <IntegrationStatus state={syncState(sync)} />;
        default:
            return (
                sync.account?.email ?? (
                    <span className="text-muted-foreground">Nessuno</span>
                )
            );
    }
}

/** The company's single destination as a compact summary with its actions. */
function CompanySync({
    sync,
    actions,
}: {
    sync: DriveSync;
    actions: ReactNode;
}) {
    const details: [string, ReactNode][] = [
        ['Cartella', <FolderName key="folder" sync={sync} />],
        ['Account', sync.account?.email ?? 'Nessuno'],
        ['Frequenza', capitalize(intervalLabel(sync.interval))],
        [
            'Ultima scrittura',
            sync.synced_at ? formatDateTime(sync.synced_at) : 'Mai',
        ],
    ];

    return (
        <Card>
            <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                <CardTitle className="flex items-center gap-2">
                    Sincronizzazione
                    <IntegrationStatus state={syncState(sync)} />
                </CardTitle>
                {actions}
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {sync.error ? (
                    <Alert variant="destructive">
                        <AlertDescription>{sync.error}</AlertDescription>
                    </Alert>
                ) : null}
                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    {details.map(([label, value]) => (
                        <div
                            key={label}
                            className="flex min-w-0 flex-col gap-1"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="font-medium break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

function SyncActions({
    sync,
    running,
    onRun,
    onEdit,
    onRemove,
}: {
    sync: DriveSync;
    running: boolean;
    onRun: () => void;
    onEdit: () => void;
    onRemove: () => void;
}) {
    return (
        <div className="flex items-center justify-end gap-1">
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
            <IconButton
                label="Sincronizza ora"
                variant="ghost"
                disabled={running || !sync.account}
                onClick={onRun}
            >
                {running ? <Spinner /> : <RefreshCw />}
            </IconButton>
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
                    <DropdownMenuItem variant="destructive" onSelect={onRemove}>
                        <Trash2 />
                        Rimuovi
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
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
