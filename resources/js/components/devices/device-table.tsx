import { useState, type ReactNode } from 'react';
import { Search } from 'lucide-react';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { sortTableRows, type TableSort } from '@/lib/table-sort';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { cn } from '@/lib/utils';

export function DeviceTable<Row extends { id: number }, Column extends string>({
    name,
    rows,
    columns: defaults,
    value,
    render,
    actions,
    onRowClick,
    filters,
    empty = 'Nessun risultato',
}: {
    name: string;
    rows: Row[];
    columns: TableColumn<Column>[];
    value: (row: Row, column: Column) => string | number | null;
    render?: (row: Row, column: Column) => ReactNode;
    actions?: (row: Row) => ReactNode;
    onRowClick?: (row: Row) => void;
    filters?: ReactNode;
    empty?: string;
}) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<TableSort<Column>>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences(name, defaults);
    const visible = columns.filter((c) => c.visible);
    const filtered = rows.filter((row) =>
        defaults
            .map((c) => value(row, c.id))
            .join(' ')
            .toLocaleLowerCase()
            .includes(search.trim().toLocaleLowerCase()),
    );
    const sorted = sortTableRows(filtered, sort, value);
    return (
        <div className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <InputGroup className="max-w-sm">
                    <InputGroupAddon>
                        <Search />
                    </InputGroupAddon>
                    <InputGroupInput
                        aria-label="Cerca nell’elenco"
                        placeholder="Cerca nell’elenco…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </InputGroup>
                {filters}
                <span className="ml-auto text-sm text-muted-foreground tabular-nums">
                    {sorted.length} risultati
                </span>
            </div>
            <div className="overflow-hidden rounded-xl border">
                <Table
                    className={cn(
                        separators && '[&_td+td]:border-l [&_th+th]:border-l',
                    )}
                >
                    <TableHeader>
                        <TableRow>
                            {visible.map((column) => (
                                <TableSortHead
                                    key={column.id}
                                    column={column}
                                    sort={sort}
                                    onSort={setSort}
                                />
                            ))}
                            <TableHead className="w-16 text-right">
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
                        {sorted.map((row) => (
                            <TableRow
                                key={row.id}
                                id={
                                    onRowClick
                                        ? `row-${name}-${row.id}`
                                        : undefined
                                }
                                tabIndex={onRowClick ? 0 : undefined}
                                aria-haspopup={
                                    onRowClick ? 'dialog' : undefined
                                }
                                className={
                                    onRowClick
                                        ? 'cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring'
                                        : undefined
                                }
                                onKeyDown={
                                    onRowClick
                                        ? (event) => {
                                              if (
                                                  event.target ===
                                                      event.currentTarget &&
                                                  (event.key === 'Enter' ||
                                                      event.key === ' ')
                                              ) {
                                                  event.preventDefault();
                                                  onRowClick(row);
                                              }
                                          }
                                        : undefined
                                }
                                onClick={
                                    onRowClick
                                        ? (event) => {
                                              if (
                                                  window.getSelection()
                                                      ?.isCollapsed !== false &&
                                                  !(
                                                      event.target as HTMLElement
                                                  ).closest(
                                                      'a,button,input,select,textarea,[role="button"]',
                                                  )
                                              )
                                                  onRowClick(row);
                                          }
                                        : undefined
                                }
                            >
                                {visible.map((c) => (
                                    <TableCell
                                        key={c.id}
                                        className="max-w-lg py-4 break-words whitespace-normal"
                                    >
                                        {render
                                            ? render(row, c.id)
                                            : (value(row, c.id) ?? '—')}
                                    </TableCell>
                                ))}
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {actions?.(row)}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                        {!sorted.length && (
                            <TableRow>
                                <TableCell colSpan={visible.length + 1}>
                                    <Empty>
                                        <EmptyHeader>
                                            <EmptyTitle>
                                                {search
                                                    ? 'Nessun risultato'
                                                    : empty}
                                            </EmptyTitle>
                                        </EmptyHeader>
                                    </Empty>
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
