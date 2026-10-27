import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Head } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Search, Tags } from 'lucide-react';
import { useState } from 'react';
import IpStatusForm from '@/components/networks/ip-status-form';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index } from '@/routes/administration/ip-statuses';
import { index as administration } from '@/routes/administration';
import type { IpStatus } from '@/types/network';

type StatusColumn = TableColumn<'name' | 'ip_addresses_count'>;
type Props = { statuses: (IpStatus & { ip_addresses_count: number })[] };

export default function IpStatusIndex({ statuses }: Props) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<{
        column: StatusColumn['id'];
        direction: 'asc' | 'desc';
    } | null>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<StatusColumn['id']>('ip-statuses', [
            { id: 'name', label: 'Stato', visible: true },
            {
                id: 'ip_addresses_count',
                label: 'IP documentati',
                visible: true,
            },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const filtered = statuses.filter(
        (status) =>
            status.name
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase()) ||
            String(status.ip_addresses_count).includes(search.trim()),
    );
    const rows = sort
        ? filtered.toSorted((a, b) => {
              const comparison =
                  sort.column === 'name'
                      ? a.name.localeCompare(b.name, 'it')
                      : a.ip_addresses_count - b.ip_addresses_count;
              return (
                  comparison * (sort.direction === 'asc' ? 1 : -1) ||
                  a.id - b.id
              );
          })
        : filtered;

    return (
        <>
            <Head title="Stati IP" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h2 className="text-2xl font-semibold tracking-tight text-balance">
                        Stati IP
                    </h2>
                    <IpStatusForm />
                </header>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="gap-3 py-4">
                        <Label htmlFor="status-search" className="sr-only">
                            Cerca stati IP
                        </Label>
                        <InputGroup className="max-w-sm">
                            <InputGroupInput
                                id="status-search"
                                type="search"
                                placeholder="Cerca stati IP…"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <InputGroupAddon>
                                <Search aria-hidden="true" />
                            </InputGroupAddon>
                        </InputGroup>
                        <p className="text-sm text-muted-foreground tabular-nums">
                            {rows.length} di {statuses.length} stati
                        </p>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table
                            className="data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                            data-separators={separators}
                            aria-label="Catalogo globale degli stati IP"
                        >
                            <TableHeader className="bg-muted/50">
                                <TableRow>
                                    {visibleColumns.map((column) => (
                                        <TableHead
                                            key={column.id}
                                            scope="col"
                                            aria-sort={
                                                sort?.column === column.id
                                                    ? sort.direction === 'asc'
                                                        ? 'ascending'
                                                        : 'descending'
                                                    : 'none'
                                            }
                                        >
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setSort(
                                                        sort?.column !==
                                                            column.id
                                                            ? {
                                                                  column: column.id,
                                                                  direction:
                                                                      'asc',
                                                              }
                                                            : sort.direction ===
                                                                'asc'
                                                              ? {
                                                                    column: column.id,
                                                                    direction:
                                                                        'desc',
                                                                }
                                                              : null,
                                                    )
                                                }
                                            >
                                                {column.label}
                                                {sort?.column !== column.id ? (
                                                    <ArrowUpDown />
                                                ) : sort.direction === 'asc' ? (
                                                    <ArrowUp />
                                                ) : (
                                                    <ArrowDown />
                                                )}
                                            </Button>
                                        </TableHead>
                                    ))}
                                    <TableHead className="w-12 text-right">
                                        <span className="sr-only">Azioni</span>
                                        <TableColumns
                                            columns={columns}
                                            onChange={setColumns}
                                            separators={separators}
                                            onSeparatorsChange={setSeparators}
                                        />
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((status) => (
                                    <TableRow key={status.id}>
                                        {visibleColumns.map((column) => (
                                            <TableCell
                                                key={column.id}
                                                className="max-w-lg break-words whitespace-normal tabular-nums"
                                            >
                                                {status[column.id]}
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right">
                                            <IpStatusForm status={status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 1}
                                            className="h-32 text-center"
                                        >
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyMedia variant="icon">
                                                        <Tags />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        {' '}
                                                        {search
                                                            ? 'Nessuno stato trovato'
                                                            : 'Nessuno stato'}
                                                    </EmptyTitle>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

IpStatusIndex.layout = () => ({
    breadcrumbs: [
        { title: 'Amministrazione', href: administration() },
        { title: 'Stati IP', href: index() },
    ],
});
