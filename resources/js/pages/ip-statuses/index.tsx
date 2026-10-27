import { Head } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Search } from 'lucide-react';
import { useState } from 'react';
import IpStatusForm from '@/components/networks/ip-status-form';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import type { IpStatus } from '@/types/network';

type StatusColumn = TableColumn<'name' | 'ip_addresses_count'>;
type Props = { statuses: (IpStatus & { ip_addresses_count: number })[] };

export default function IpStatusIndex({ statuses }: Props) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<{
        column: StatusColumn['id'];
        direction: 'asc' | 'desc';
    } | null>(null);
    const [columns, setColumns] = useState<StatusColumn[]>([
        { id: 'name', label: 'Stato', visible: true },
        { id: 'ip_addresses_count', label: 'IP documentati', visible: true },
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
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Stati IP
                    </h1>
                    <IpStatusForm />
                </header>
                <div className="flex flex-col gap-3">
                    <Label htmlFor="status-search" className="sr-only">
                        Cerca stati IP
                    </Label>
                    <div className="flex w-full max-w-lg items-center gap-2">
                        <Search
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            id="status-search"
                            type="search"
                            placeholder="Cerca stati IP…"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {rows.length} di {statuses.length} stati
                    </p>
                    <div className="overflow-hidden rounded-lg border">
                        <Table aria-label="Catalogo globale degli stati IP">
                            <TableHeader>
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
                                                className="max-w-lg break-words whitespace-normal"
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
                                            {search
                                                ? 'Nessuno stato trovato'
                                                : 'Nessuno stato'}
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>
        </>
    );
}

IpStatusIndex.layout = () => ({
    breadcrumbs: [{ title: 'Stati IP', href: index() }],
});
