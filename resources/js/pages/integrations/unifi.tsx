import { UnifiMark } from '@/components/unifi-mark';
import { Head } from '@inertiajs/react';
import { Cloud, Plus, Search, Server } from 'lucide-react';
import { useState } from 'react';
import { index as administration } from '@/routes/administration';
import {
    IntegrationHeader,
    type IntegrationState,
} from '@/components/integration-header';
import { IntegrationTabs } from '@/components/integration-tabs';
import { index as unifiIndex } from '@/routes/administration/integrations/unifi';
import { UnifiConnectionForm } from '@/components/unifi/connection-form';
import { UnifiConnectionPanel } from '@/components/unifi/connection-panel';
import type { UnifiCompany, UnifiConnection } from '@/components/unifi/types';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Separator } from '@/components/ui/separator';
import { index as integrations } from '@/routes/administration/integrations';
import type { Company } from '@/types/company';

type Props = {
    company: Company | null;
    connections: UnifiConnection[];
    companies: UnifiCompany[];
};

export default function Unifi({ company, connections, companies = [] }: Props) {
    const [editor, setEditor] = useState<{
        mode: 'cloud' | 'direct';
        connection?: UnifiConnection;
    } | null>(null);
    const [search, setSearch] = useState('');
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
    const matching = connections.filter(
        (connection) =>
            !search.trim() ||
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
                .includes(search.trim().toLocaleLowerCase()),
    );

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
                            description={[
                                connections.length === 1
                                    ? '1 connessione'
                                    : `${connections.length} connessioni`,
                                linkedSites === 1
                                    ? '1 sito collegato'
                                    : `${linkedSites} siti collegati`,
                            ].join(' · ')}
                            actions={
                                !company ? (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="outline">
                                                <Plus data-icon="inline-start" />
                                                Aggiungi connessione
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setEditor({ mode: 'cloud' })
                                                }
                                            >
                                                <Cloud />
                                                Site Manager (cloud)
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setEditor({
                                                        mode: 'direct',
                                                    })
                                                }
                                            >
                                                <Server />
                                                UniFi Network (diretta)
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                ) : null
                            }
                        />
                        {connections.length ? (
                            <>
                                <InputGroup className="max-w-lg">
                                    <InputGroupAddon>
                                        <Search />
                                    </InputGroupAddon>
                                    <InputGroupInput
                                        type="search"
                                        aria-label="Cerca connessioni, console, siti o sedi"
                                        placeholder="Cerca connessioni, console, siti o sedi…"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                    />
                                </InputGroup>
                                <div className="flex flex-col gap-8">
                                    {matching.map((connection, position) => (
                                        <div
                                            key={connection.id}
                                            className="flex min-w-0 flex-col gap-8"
                                        >
                                            {position > 0 ? (
                                                <Separator />
                                            ) : null}
                                            <UnifiConnectionPanel
                                                connection={connection}
                                                companies={companies}
                                                search={search}
                                                readonly={!!company}
                                                onEdit={() =>
                                                    setEditor({
                                                        mode: connection.mode,
                                                        connection,
                                                    })
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : null}
                        {!connections.length || !matching.length ? (
                            <Empty className="border">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <UnifiMark />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        {!connections.length
                                            ? company
                                                ? 'Nessun sito UniFi collegato'
                                                : 'Nessuna connessione UniFi'
                                            : 'Nessun risultato'}
                                    </EmptyTitle>
                                </EmptyHeader>
                                {search ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => setSearch('')}
                                    >
                                        Azzera ricerca
                                    </Button>
                                ) : null}
                            </Empty>
                        ) : null}
                    </div>
                </IntegrationTabs>
            </div>
            {editor ? (
                <UnifiConnectionForm
                    key={editor.connection?.id ?? editor.mode}
                    connection={editor.connection}
                    mode={editor.mode}
                    onClose={() => setEditor(null)}
                />
            ) : null}
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
