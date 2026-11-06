import {
    UnifiObservationIndicator,
    UnifiObservedValue,
    type UnifiObservation,
} from '@/components/networks/unifi-observation';
import { DeviceAssociation } from '@/components/devices/device-association';
import type { DeviceInterface } from '@/types/device';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    ChevronDown,
    ChevronRight,
    MapPin,
    ListTree,
    Search,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { DeviceTable } from '@/components/devices/device-table';
import { IconButton } from '@/components/icon-button';
import { type TableColumn } from '@/components/table-columns';
import TextLink from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cableTypes } from '@/lib/rack-cabling';
import { TableColumns } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { cn } from '@/lib/utils';
import { show } from '@/routes/companies/sites/subnets';
import { index } from '@/routes/companies/sites/search';
import { show as companyShow } from '@/routes/companies';
import type { Company, Site } from '@/types/company';

const groupLabels = {
    none: 'Nessuno',
    site: 'Sede',
};

const tabLabels = {
    ip: 'IP',
    devices: 'Dispositivi',
    models: 'Modelli',
    racks: 'Rack',
    cables: 'Cablaggi',
};
type Tab = keyof typeof tabLabels;
type DeviceResult = {
    id: number;
    href: string;
    name: string;
    manufacturer: string | null;
    model: string | null;
    rack: string | null;
    site: string | null;
    serial_number: string | null;
};
type ModelResult = {
    id: number;
    href: string;
    name: string;
    manufacturer: string | null;
    type: string | null;
    devices_count: number;
};
type RackResult = {
    id: number;
    href: string;
    name: string;
    floor: string | null;
    site: string | null;
    devices_count: number;
};
type CableResult = {
    id: number;
    href: string;
    code: string;
    name: string | null;
    a: string;
    b: string;
    type: keyof typeof cableTypes;
    purpose: string | null;
    site: string | null;
};

type Column = 'ip' | 'hostname' | 'mac_address' | 'site' | 'subnet' | 'device';
type Result = {
    id: number | null;
    unifi_observations?: UnifiObservation[];
    ip: string;
    hostname: string | null;
    mac_address: string | null;
    site: string | null;
    site_id: number;
    subnet: string;
    subnet_id: number;
    assignment_label: string | null;
    device: { id: number; name: string } | null;
    device_interface: DeviceInterface | null;
};

export default function SearchIndex({
    company,
    site,
    results,
    query,
    tab,
    ...inventory
}: {
    company: Company;
    site: Site;
    tab: Tab | null;
    devices: DeviceResult[];
    models: ModelResult[];
    racks: RackResult[];
    cables: CableResult[];
    results: {
        data: Result[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    query: {
        search: string;
        sort: Column | null;
        direction: 'asc' | 'desc';
        group: 'none' | 'site';
    };
}) {
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<Column>('global-search', [
            { id: 'ip', label: 'IP', visible: true },
            { id: 'hostname', label: 'Hostname', visible: true },
            { id: 'mac_address', label: 'MAC Address', visible: true },
            { id: 'device', label: 'Dispositivo / Interfaccia', visible: true },
            { id: 'site', label: 'Sede', visible: true },
            { id: 'subnet', label: 'Subnet', visible: true },
        ]);
    const route = { company: company.id, site: site.id };
    const visibleColumns = columns.filter((column) => column.visible);
    const viewKey = JSON.stringify([
        company.id,
        query.search,
        query.group,
        results.current_page,
    ]);
    const [collapsedView, setCollapsedView] = useState<{
        key: string;
        groups: number[];
    }>({ key: viewKey, groups: [] });
    const collapsedGroups =
        collapsedView.key === viewKey ? collapsedView.groups : [];
    const { companyNavigation } = usePage().props;
    const multipleSites = (companyNavigation?.sites.length ?? 0) > 1;
    const counts: Record<Tab, number> = {
        ip: results.total,
        devices: inventory.devices.length,
        models: inventory.models.length,
        racks: inventory.racks.length,
        cables: inventory.cables.length,
    };
    const tabs = (Object.keys(tabLabels) as Tab[]).filter(
        (key) => counts[key] > 0,
    );
    const total = tabs.reduce((sum, key) => sum + counts[key], 0);
    const [chosenTab, setChosenTab] = useState(tab);
    // A new search keeps the chosen tab only while it still has results.
    const activeTab =
        chosenTab && tabs.includes(chosenTab) ? chosenTab : tabs[0];

    return (
        <>
            <Head title={`Ricerca · ${company.name}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <header className="flex flex-col gap-2">
                    <h1 className="text-2xl font-medium tracking-tight text-balance">
                        Ricerca
                    </h1>
                    {query.search ? (
                        <p
                            role="status"
                            className="text-sm break-words text-muted-foreground tabular-nums"
                        >
                            {total.toLocaleString('it-IT')}{' '}
                            {total === 1 ? 'risultato' : 'risultati'} per «
                            {query.search}»
                        </p>
                    ) : null}
                </header>
                {activeTab ? (
                    <Tabs
                        value={activeTab}
                        onValueChange={(value) => setChosenTab(value as Tab)}
                        className="gap-4"
                    >
                        <TabsList aria-label="Tipo di risultato">
                            {tabs.map((key) => (
                                <TabsTrigger key={key} value={key}>
                                    {tabLabels[key]}
                                    <Badge
                                        variant="secondary"
                                        className="tabular-nums"
                                    >
                                        {counts[key].toLocaleString('it-IT')}
                                    </Badge>
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        <TabsContent value="devices">
                            <ResultTable
                                name="search-devices"
                                rows={inventory.devices}
                                columns={[
                                    {
                                        id: 'name',
                                        label: 'Nome',
                                        visible: true,
                                    },
                                    {
                                        id: 'manufacturer',
                                        label: 'Produttore',
                                        visible: true,
                                    },
                                    {
                                        id: 'model',
                                        label: 'Modello',
                                        visible: true,
                                    },
                                    {
                                        id: 'rack',
                                        label: 'Rack',
                                        visible: true,
                                    },
                                    {
                                        id: 'site',
                                        label: 'Sede',
                                        visible: multipleSites,
                                    },
                                    {
                                        id: 'serial_number',
                                        label: 'Numero di serie',
                                        visible: true,
                                    },
                                ]}
                                value={(row, column) => row[column]}
                            />
                        </TabsContent>
                        <TabsContent value="models">
                            <ResultTable
                                name="search-models"
                                rows={inventory.models}
                                columns={[
                                    {
                                        id: 'name',
                                        label: 'Modello',
                                        visible: true,
                                    },
                                    {
                                        id: 'manufacturer',
                                        label: 'Produttore',
                                        visible: true,
                                    },
                                    {
                                        id: 'type',
                                        label: 'Tipo',
                                        visible: true,
                                    },
                                    {
                                        id: 'devices_count',
                                        label: 'Dispositivi',
                                        visible: true,
                                    },
                                ]}
                                value={(row, column) => row[column]}
                            />
                        </TabsContent>
                        <TabsContent value="racks">
                            <ResultTable
                                name="search-racks"
                                rows={inventory.racks}
                                columns={[
                                    {
                                        id: 'name',
                                        label: 'Nome',
                                        visible: true,
                                    },
                                    {
                                        id: 'floor',
                                        label: 'Piano',
                                        visible: true,
                                    },
                                    {
                                        id: 'site',
                                        label: 'Sede',
                                        visible: multipleSites,
                                    },
                                    {
                                        id: 'devices_count',
                                        label: 'Dispositivi',
                                        visible: true,
                                    },
                                ]}
                                value={(row, column) => row[column]}
                            />
                        </TabsContent>
                        <TabsContent value="cables">
                            <ResultTable
                                name="search-cables"
                                rows={inventory.cables}
                                columns={[
                                    {
                                        id: 'code',
                                        label: 'Codice',
                                        visible: true,
                                    },
                                    {
                                        id: 'name',
                                        label: 'Nome',
                                        visible: true,
                                    },
                                    {
                                        id: 'a',
                                        label: 'Partenza',
                                        visible: true,
                                    },
                                    {
                                        id: 'b',
                                        label: 'Destinazione',
                                        visible: true,
                                    },
                                    {
                                        id: 'type',
                                        label: 'Tipo',
                                        visible: true,
                                    },
                                    {
                                        id: 'purpose',
                                        label: 'Scopo',
                                        visible: true,
                                    },
                                    {
                                        id: 'site',
                                        label: 'Sede',
                                        visible: multipleSites,
                                    },
                                ]}
                                value={(row, column) =>
                                    column === 'type'
                                        ? cableTypes[row.type]
                                        : row[column]
                                }
                            />
                        </TabsContent>
                        <TabsContent value="ip">
                            <Card className="gap-0 overflow-hidden py-0">
                                <CardContent className="px-0">
                                    <Table
                                        aria-label="Risultati della ricerca nell’azienda"
                                        data-separators={separators}
                                        className="data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                                    >
                                        <TableHeader className="bg-muted/50">
                                            <TableRow>
                                                {visibleColumns.map(
                                                    (column) => (
                                                        <TableSortHead
                                                            key={column.id}
                                                            column={column}
                                                            sort={
                                                                query.sort
                                                                    ? {
                                                                          column: query.sort,
                                                                          direction:
                                                                              query.direction,
                                                                      }
                                                                    : null
                                                            }
                                                            onSort={(sort) =>
                                                                router.get(
                                                                    index(
                                                                        route,
                                                                        {
                                                                            query: {
                                                                                search: query.search,
                                                                                group: query.group,
                                                                                sort: sort?.column,
                                                                                direction:
                                                                                    sort?.direction,
                                                                            },
                                                                        },
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveState: true,
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    ),
                                                )}
                                                <TableHead className="w-24 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                asChild
                                                            >
                                                                <IconButton
                                                                    label={`Raggruppamento: ${groupLabels[query.group]}`}
                                                                    variant={
                                                                        query.group ===
                                                                        'none'
                                                                            ? 'ghost'
                                                                            : 'secondary'
                                                                    }
                                                                >
                                                                    <ListTree />
                                                                </IconButton>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuGroup>
                                                                    <DropdownMenuLabel>
                                                                        Raggruppa
                                                                        per
                                                                    </DropdownMenuLabel>
                                                                    <DropdownMenuRadioGroup
                                                                        value={
                                                                            query.group
                                                                        }
                                                                        onValueChange={(
                                                                            group,
                                                                        ) =>
                                                                            router.get(
                                                                                index(
                                                                                    route,
                                                                                    {
                                                                                        query: {
                                                                                            ...query,
                                                                                            group,
                                                                                        },
                                                                                    },
                                                                                ),
                                                                                {},
                                                                                {
                                                                                    preserveState: true,
                                                                                    preserveScroll: true,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        {Object.entries(
                                                                            groupLabels,
                                                                        ).map(
                                                                            ([
                                                                                value,
                                                                                label,
                                                                            ]) => (
                                                                                <DropdownMenuRadioItem
                                                                                    key={
                                                                                        value
                                                                                    }
                                                                                    value={
                                                                                        value
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        label
                                                                                    }
                                                                                </DropdownMenuRadioItem>
                                                                            ),
                                                                        )}
                                                                    </DropdownMenuRadioGroup>
                                                                </DropdownMenuGroup>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                        <TableColumns
                                                            columns={columns}
                                                            onChange={
                                                                setColumns
                                                            }
                                                            separators={
                                                                separators
                                                            }
                                                            onSeparatorsChange={
                                                                setSeparators
                                                            }
                                                        />
                                                    </div>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {results.data.map(
                                                (result, position) => {
                                                    const id = result.site_id;
                                                    const grouped =
                                                        query.group !== 'none';
                                                    const startsGroup =
                                                        grouped &&
                                                        (position === 0 ||
                                                            results.data[
                                                                position - 1
                                                            ].site_id !== id);
                                                    const collapsed =
                                                        grouped &&
                                                        collapsedGroups.includes(
                                                            id,
                                                        );
                                                    const href = show(
                                                        {
                                                            company: company.id,
                                                            site: result.site_id,
                                                            subnet: result.subnet_id,
                                                        },
                                                        {
                                                            query: {
                                                                search: result.ip,
                                                                ip_address:
                                                                    result.id ??
                                                                    undefined,
                                                            },
                                                        },
                                                    );
                                                    return (
                                                        <Fragment
                                                            key={`${result.site_id}:${result.subnet_id}:${result.ip}`}
                                                        >
                                                            {startsGroup ? (
                                                                <TableRow className="bg-muted/40">
                                                                    <TableCell
                                                                        colSpan={
                                                                            visibleColumns.length +
                                                                            1
                                                                        }
                                                                        className="p-1"
                                                                    >
                                                                        <Button
                                                                            variant="ghost"
                                                                            className="h-auto min-h-11 w-full justify-start text-left whitespace-normal"
                                                                            aria-expanded={
                                                                                !collapsed
                                                                            }
                                                                            onClick={() =>
                                                                                setCollapsedView(
                                                                                    {
                                                                                        key: viewKey,
                                                                                        groups: collapsed
                                                                                            ? collapsedGroups.filter(
                                                                                                  (
                                                                                                      group,
                                                                                                  ) =>
                                                                                                      group !==
                                                                                                      id,
                                                                                              )
                                                                                            : [
                                                                                                  ...collapsedGroups,
                                                                                                  id,
                                                                                              ],
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            {collapsed ? (
                                                                                <ChevronRight />
                                                                            ) : (
                                                                                <ChevronDown />
                                                                            )}
                                                                            {query.group ===
                                                                                'site' &&
                                                                            result.site ? (
                                                                                <MapPin />
                                                                            ) : (
                                                                                <Building2 />
                                                                            )}
                                                                            <span className="break-words">
                                                                                {result.site ??
                                                                                    company.name}
                                                                            </span>
                                                                        </Button>
                                                                    </TableCell>
                                                                </TableRow>
                                                            ) : null}
                                                            <TableRow
                                                                hidden={
                                                                    collapsed
                                                                }
                                                            >
                                                                {visibleColumns.map(
                                                                    (
                                                                        column,
                                                                    ) => (
                                                                        <TableCell
                                                                            key={
                                                                                column.id
                                                                            }
                                                                            className={cn(
                                                                                'max-w-xs break-words whitespace-normal',
                                                                                [
                                                                                    'ip',
                                                                                    'mac_address',
                                                                                    'subnet',
                                                                                ].includes(
                                                                                    column.id,
                                                                                ) &&
                                                                                    'tabular-nums',
                                                                            )}
                                                                        >
                                                                            {column.id ===
                                                                            'ip' ? (
                                                                                <div className="flex items-center gap-2">
                                                                                    <Link
                                                                                        href={
                                                                                            href
                                                                                        }
                                                                                        className="rounded-sm font-medium transition-colors hover:text-primary focus-visible:outline-ring"
                                                                                    >
                                                                                        {
                                                                                            result.ip
                                                                                        }
                                                                                    </Link>
                                                                                    <UnifiObservationIndicator
                                                                                        company={
                                                                                            company.id
                                                                                        }
                                                                                        site={
                                                                                            result.site_id
                                                                                        }
                                                                                        observations={
                                                                                            result.unifi_observations ??
                                                                                            []
                                                                                        }
                                                                                    />
                                                                                </div>
                                                                            ) : column.id ===
                                                                              'device' ? (
                                                                                <DeviceAssociation
                                                                                    company={
                                                                                        company.id
                                                                                    }
                                                                                    site={
                                                                                        result.site_id
                                                                                    }
                                                                                    device={
                                                                                        result.device
                                                                                    }
                                                                                    item={
                                                                                        result.device_interface
                                                                                    }
                                                                                    label={
                                                                                        result.assignment_label
                                                                                    }
                                                                                />
                                                                            ) : column.id ===
                                                                                  'hostname' ||
                                                                              column.id ===
                                                                                  'mac_address' ? (
                                                                                <div className="flex flex-col items-start gap-1">
                                                                                    {
                                                                                        result[
                                                                                            column
                                                                                                .id
                                                                                        ]
                                                                                    }
                                                                                    <UnifiObservedValue
                                                                                        company={
                                                                                            company.id
                                                                                        }
                                                                                        site={
                                                                                            result.site_id
                                                                                        }
                                                                                        observations={
                                                                                            result.unifi_observations ??
                                                                                            []
                                                                                        }
                                                                                        field={
                                                                                            column.id ===
                                                                                            'mac_address'
                                                                                                ? 'mac'
                                                                                                : 'hostname_observed'
                                                                                        }
                                                                                        value={
                                                                                            result[
                                                                                                column
                                                                                                    .id
                                                                                            ]
                                                                                        }
                                                                                    />
                                                                                </div>
                                                                            ) : (
                                                                                (result[
                                                                                    column
                                                                                        .id
                                                                                ] ??
                                                                                '—')
                                                                            )}
                                                                        </TableCell>
                                                                    ),
                                                                )}
                                                                <TableCell className="text-right">
                                                                    <IconButton
                                                                        label={`Apri IP ${result.ip} · ${company.name}${result.site ? ` · ${result.site}` : ''}`}
                                                                        variant="ghost"
                                                                        asChild
                                                                    >
                                                                        <Link
                                                                            href={
                                                                                href
                                                                            }
                                                                        >
                                                                            <ArrowRight />
                                                                        </Link>
                                                                    </IconButton>
                                                                </TableCell>
                                                            </TableRow>
                                                        </Fragment>
                                                    );
                                                },
                                            )}
                                            {results.data.length === 0 ? (
                                                <TableRow>
                                                    <TableCell
                                                        colSpan={
                                                            visibleColumns.length +
                                                            1
                                                        }
                                                        className="h-48 whitespace-normal"
                                                    >
                                                        <Empty>
                                                            <EmptyHeader>
                                                                <EmptyMedia variant="icon">
                                                                    <Search />
                                                                </EmptyMedia>
                                                                <EmptyTitle>
                                                                    {query.search
                                                                        ? 'Nessun IP trovato'
                                                                        : 'Inserisci un IP, un MAC o un hostname nella ricerca in alto'}
                                                                </EmptyTitle>
                                                            </EmptyHeader>
                                                        </Empty>
                                                    </TableCell>
                                                </TableRow>
                                            ) : null}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                                {results.last_page > 1 ||
                                results.current_page > 1 ? (
                                    <CardFooter className="justify-end border-t py-4">
                                        <nav
                                            aria-label="Pagine dei risultati"
                                            className="flex items-center gap-3 text-sm text-muted-foreground tabular-nums"
                                        >
                                            {results.prev_page_url ? (
                                                <IconButton
                                                    label="Pagina precedente"
                                                    asChild
                                                >
                                                    <Link
                                                        href={
                                                            results.prev_page_url
                                                        }
                                                        preserveState
                                                        preserveScroll
                                                    >
                                                        <ArrowLeft />
                                                    </Link>
                                                </IconButton>
                                            ) : (
                                                <IconButton
                                                    label="Pagina precedente"
                                                    disabled
                                                >
                                                    <ArrowLeft />
                                                </IconButton>
                                            )}
                                            <span>
                                                {results.current_page} /{' '}
                                                {results.last_page}
                                            </span>
                                            {results.next_page_url ? (
                                                <IconButton
                                                    label="Pagina successiva"
                                                    asChild
                                                >
                                                    <Link
                                                        href={
                                                            results.next_page_url
                                                        }
                                                        preserveState
                                                        preserveScroll
                                                    >
                                                        <ArrowRight />
                                                    </Link>
                                                </IconButton>
                                            ) : (
                                                <IconButton
                                                    label="Pagina successiva"
                                                    disabled
                                                >
                                                    <ArrowRight />
                                                </IconButton>
                                            )}
                                        </nav>
                                    </CardFooter>
                                ) : null}
                            </Card>
                        </TabsContent>
                    </Tabs>
                ) : (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Search />
                            </EmptyMedia>
                            <EmptyTitle>
                                {query.search
                                    ? 'Nessun risultato'
                                    : 'Inserisci un testo nella ricerca in alto'}
                            </EmptyTitle>
                        </EmptyHeader>
                    </Empty>
                )}
            </div>
        </>
    );
}

/** Inventory results: the first column links to the record, as does the whole row. */
function ResultTable<
    Row extends { id: number; href: string },
    Column extends string,
>({
    name,
    rows,
    columns,
    value,
}: {
    name: string;
    rows: Row[];
    columns: TableColumn<Column>[];
    value: (row: Row, column: Column) => string | number | null;
}) {
    return (
        <DeviceTable
            name={name}
            variant="list"
            searchLabel="Cerca nei risultati"
            rows={rows}
            columns={columns}
            value={value}
            render={(row, column) =>
                column === columns[0].id ? (
                    <TextLink href={row.href}>{value(row, column)}</TextLink>
                ) : (
                    (value(row, column) ?? '—')
                )
            }
            onRowClick={(row) => router.visit(row.href)}
            rowOpensDialog={false}
        />
    );
}

SearchIndex.layout = ({ company, site }: { company: Company; site: Site }) => ({
    breadcrumbs: [
        { title: company.name, href: companyShow(company.id) },
        {
            title: 'Ricerca',
            href: index({ company: company.id, site: site.id }),
        },
    ],
});
