import { UnifiMark } from '@/components/unifi-mark';
import { Head, usePage } from '@inertiajs/react';
import { ChevronRight, Cloud, Plus, Search, Server } from 'lucide-react';
import { useState } from 'react';
import { index as administration } from '@/routes/administration';
import {
    IntegrationHeader,
    IntegrationStatus,
    type IntegrationState,
} from '@/components/integration-header';
import { IntegrationTabs } from '@/components/integration-tabs';
import { index as unifiIndex } from '@/routes/administration/integrations/unifi';
import { UnifiConnectionForm } from '@/components/unifi/connection-form';
import {
    UnifiConnectionPanel,
    connectionState,
} from '@/components/unifi/connection-panel';
import {
    unifiAccess,
    type UnifiCompany,
    type UnifiConnection,
    type UnifiMode,
} from '@/components/unifi/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import {
    Empty,
    EmptyContent,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemMedia,
    ItemSeparator,
    ItemTitle,
} from '@/components/ui/item';
import { index as integrations } from '@/routes/administration/integrations';
import { formatDateTime } from '@/lib/utils';
import type { Company } from '@/types/company';

type Props = {
    company: Company | null;
    connections: UnifiConnection[];
    companies: UnifiCompany[];
};

export default function Unifi({ company, connections, companies = [] }: Props) {
    const { auth } = usePage().props;
    const [editor, setEditor] = useState<{
        mode: UnifiMode;
        connection?: UnifiConnection;
    } | null>(null);
    const [openId, setOpenId] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const opened = connections.find((connection) => connection.id === openId);
    // The administration manages Cloud Connectors; a company manages its own hosts.
    const mode: UnifiMode = company ? 'direct' : 'cloud';
    const manageable = (connection: UnifiConnection) =>
        auth.user.is_superadmin && connection.mode === mode;
    const linkedSites = connections
        .flatMap((connection) => connection.sites)
        .filter((site) => site.site !== null).length;
    const state: IntegrationState = !connections.length
        ? 'unconfigured'
        : connections.some(
                (connection) =>
                    connection.enabled &&
                    connection.checked_at &&
                    connection.check_status !== 'ok',
            )
          ? 'error'
          : connections.every((connection) => !connection.enabled)
            ? 'suspended'
            : linkedSites
              ? 'active'
              : 'pending';
    const query = search.trim().toLocaleLowerCase();
    const matching = connections.filter(
        (connection) =>
            !query ||
            [
                connection.name,
                ...connection.sites.flatMap((site) => [
                    site.name,
                    site.console_name,
                    site.site?.company.name,
                    site.site?.name,
                ]),
            ]
                .join(' ')
                .toLocaleLowerCase()
                .includes(query),
    );
    const addLabel = company ? 'Aggiungi UniFi Host' : 'Aggiungi Cloud Connector';
    // A single Cloud Connector serves the whole installation.
    const add =
        auth.user.is_superadmin &&
        (company || !connections.some((connection) => connection.mode === 'cloud')) ? (
        <Button variant="outline" onClick={() => setEditor({ mode })}>
            <Plus data-icon="inline-start" />
            {addLabel}
        </Button>
    ) : null;

    return (
        <>
            <Head title={`UniFi · ${company?.name ?? 'Integrazioni'}`} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                {!company ? (
                    <h1 className="text-2xl font-medium tracking-tight">
                        Integrazioni
                    </h1>
                ) : null}
                <IntegrationTabs value="unifi" company={company}>
                    <div className="flex min-w-0 flex-col gap-6">
                        <IntegrationHeader
                            mark={<UnifiMark className="size-5" />}
                            title="UniFi"
                            state={state}
                            details={
                                state === 'active'
                                    ? 'Sincronizzazione ogni ora'
                                    : undefined
                            }
                            description={[
                                connections.length === 1
                                    ? '1 accesso'
                                    : `${connections.length} accessi`,
                                linkedSites === 1
                                    ? '1 sito collegato'
                                    : `${linkedSites} siti collegati`,
                            ].join(' · ')}
                            actions={add}
                        />
                        {connections.length > 3 ? (
                            <InputGroup className="max-w-lg">
                                <InputGroupAddon>
                                    <Search />
                                </InputGroupAddon>
                                <InputGroupInput
                                    type="search"
                                    aria-label="Cerca accessi, console, siti o sedi"
                                    placeholder="Cerca accessi, console, siti o sedi…"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </InputGroup>
                        ) : null}
                        {matching.length ? (
                            <ItemGroup className="rounded-lg border">
                                {matching.map((connection, position) => (
                                    <AccessRow
                                        key={connection.id}
                                        connection={connection}
                                        separator={position > 0}
                                        onOpen={() => setOpenId(connection.id)}
                                    />
                                ))}
                            </ItemGroup>
                        ) : (
                            <Empty className="border">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <UnifiMark />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        {connections.length
                                            ? 'Nessun risultato'
                                            : company
                                              ? 'Nessun sito UniFi collegato'
                                              : 'Nessun Cloud Connector'}
                                    </EmptyTitle>
                                </EmptyHeader>
                                <EmptyContent>
                                    {search ? (
                                        <Button
                                            variant="outline"
                                            onClick={() => setSearch('')}
                                        >
                                            Azzera ricerca
                                        </Button>
                                    ) : (
                                        add
                                    )}
                                </EmptyContent>
                            </Empty>
                        )}
                    </div>
                </IntegrationTabs>
            </div>
            <Dialog
                open={!!opened}
                onOpenChange={(open) => {
                    if (!open) setOpenId(null);
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
                    {opened ? (
                        <UnifiConnectionPanel
                            key={opened.id}
                            connection={opened}
                            companies={companies}
                            readonly={!manageable(opened)}
                            onEdit={() =>
                                setEditor({
                                    mode: opened.mode,
                                    connection: opened,
                                })
                            }
                        />
                    ) : null}
                </DialogContent>
            </Dialog>
            {editor ? (
                <UnifiConnectionForm
                    key={editor.connection?.id ?? editor.mode}
                    connection={editor.connection}
                    mode={editor.mode}
                    companyId={company?.id ?? null}
                    onClose={() => setEditor(null)}
                />
            ) : null}
        </>
    );
}

function AccessRow({
    connection,
    separator,
    onOpen,
}: {
    connection: UnifiConnection;
    separator: boolean;
    onOpen: () => void;
}) {
    const linked = connection.sites.filter((site) => site.site !== null);
    const consoles = connection.selected_console_ids?.length;

    return (
        <>
            {separator ? <ItemSeparator /> : null}
            <Item asChild>
                <button
                    type="button"
                    className="w-full text-left hover:bg-muted/50 focus-visible:bg-muted/50"
                    onClick={onOpen}
                >
                    <ItemMedia variant="icon">
                        {connection.mode === 'cloud' ? <Cloud /> : <Server />}
                    </ItemMedia>
                    <ItemContent className="min-w-0">
                        <ItemTitle>
                            <span className="break-words">
                                {connection.name}
                            </span>
                            <IntegrationStatus
                                state={connectionState(connection)}
                            />
                        </ItemTitle>
                        <ItemDescription className="line-clamp-none">
                            {[
                                unifiAccess[connection.mode],
                                consoles !== undefined &&
                                connection.mode === 'cloud'
                                    ? `${consoles} di ${connection.hosts?.length ?? 0} console integrate`
                                    : null,
                                linked.length === 1
                                    ? '1 sito collegato'
                                    : `${linked.length} siti collegati`,
                                connection.checked_at
                                    ? `verificato il ${formatDateTime(connection.checked_at)}`
                                    : 'mai verificato',
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </ItemDescription>
                    </ItemContent>
                    <ItemActions>
                        <ChevronRight className="size-4 text-muted-foreground" />
                    </ItemActions>
                </button>
            </Item>
        </>
    );
}

Unifi.layout = ({ company }: Props) => ({
    breadcrumbs: company
        ? []
        : [
              { title: 'Amministrazione', href: administration() },
              { title: 'Integrazioni', href: integrations() },
              { title: 'UniFi', href: unifiIndex() },
          ],
});
