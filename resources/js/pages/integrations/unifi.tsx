import { UnifiMark } from '@/components/unifi-mark';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Cloud, Plus, Search, Server } from 'lucide-react';
import { useState } from 'react';
import { IntegrationTabs } from '@/components/integration-tabs';
import { TabsContent } from '@/components/ui/tabs';
import { index as administration } from '@/routes/administration';
import { index as unifiIndex } from '@/routes/administration/integrations/unifi';
import { UnifiConnectionForm } from '@/components/unifi/connection-form';
import { UnifiConnectionPanel } from '@/components/unifi/connection-panel';
import type { UnifiCompany, UnifiConnection } from '@/components/unifi/types';
import { Button } from '@/components/ui/button';
import { Card, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
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
                <header className="flex flex-col items-start gap-3">
                    {!company ? (
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="-ml-2"
                        >
                            <Link href={integrations()}>
                                <ArrowLeft data-icon="inline-start" />
                                Integrazioni
                            </Link>
                        </Button>
                    ) : null}
                    <h1 className="flex items-center gap-3 text-2xl font-medium tracking-tight">
                        <UnifiMark className="size-6" />
                        UniFi
                    </h1>
                </header>
                <IntegrationTabs value="unifi" company={company}>
                    <TabsContent
                        value="unifi"
                        className="flex min-w-0 flex-col gap-6"
                    >
                        {!company ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <Cloud className="size-5" />
                                            Cloud
                                        </CardTitle>
                                    </CardHeader>
                                    <CardFooter>
                                        <Button
                                            variant="outline"
                                            className="w-full justify-between"
                                            onClick={() =>
                                                setEditor({ mode: 'cloud' })
                                            }
                                        >
                                            Collega Site Manager
                                            <Plus data-icon="inline-end" />
                                        </Button>
                                    </CardFooter>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <Server className="size-5" />
                                            Diretta
                                        </CardTitle>
                                    </CardHeader>
                                    <CardFooter>
                                        <Button
                                            variant="outline"
                                            className="w-full justify-between"
                                            onClick={() =>
                                                setEditor({ mode: 'direct' })
                                            }
                                        >
                                            Collega UniFi Network
                                            <Plus data-icon="inline-end" />
                                        </Button>
                                    </CardFooter>
                                </Card>
                            </div>
                        ) : null}
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
                    </TabsContent>
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
