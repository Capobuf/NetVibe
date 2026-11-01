import { useState, type ReactNode } from 'react';
import { Search } from 'lucide-react';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { TableSearch } from '@/components/table-search';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { useTablePagination } from '@/hooks/use-table-pagination';
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
    rowOpensDialog = true,
    filters,
    empty = 'Nessun risultato',
    variant = 'default',
    total = rows.length,
    filterKey = '',
    searchLabel = 'Cerca nell’elenco',
}: {
    name: string;
    rows: Row[];
    columns: TableColumn<Column>[];
    value: (row: Row, column: Column) => string | number | null;
    render?: (row: Row, column: Column) => ReactNode;
    actions?: (row: Row) => ReactNode;
    onRowClick?: (row: Row) => void;
    rowOpensDialog?: boolean;
    filters?: ReactNode;
    empty?: string;
    variant?: 'default' | 'list';
    total?: number;
    filterKey?: string;
    searchLabel?: string;
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
    const {
        pageRows,
        page,
        pageCount,
        pageSize,
        needsPagination,
        setPage,
        setPageSize,
    } = useTablePagination(sorted, JSON.stringify([search, sort, filterKey]));
    const isList = variant === 'list';
    return (
        <div className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                {isList ? (
                    <TableSearch
                        value={search}
                        onChange={setSearch}
                        total={total}
                        label={searchLabel}
                    />
                ) : (
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
                )}
                {filters}
                {!isList ? (
                    <span className="ml-auto text-sm text-muted-foreground tabular-nums">
                        {sorted.length} risultati
                    </span>
                ) : null}
            </div>
            <div
                className={cn(
                    'min-w-0',
                    !isList && 'overflow-hidden rounded-xl border',
                )}
            >
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
                        {(isList ? pageRows : sorted).map((row) => (
                            <TableRow
                                key={row.id}
                                id={
                                    onRowClick
                                        ? `row-${name}-${row.id}`
                                        : undefined
                                }
                                tabIndex={onRowClick ? 0 : undefined}
                                aria-haspopup={
                                    onRowClick && rowOpensDialog
                                        ? 'dialog'
                                        : undefined
                                }
                                className={cn(
                                    isList &&
                                        'even:bg-muted/20 focus-within:bg-muted/40',
                                    onRowClick &&
                                        'cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
                                )}
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
                                                  event.currentTarget.contains(
                                                      event.target as Node,
                                                  ) &&
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
                                        className={cn(
                                            'max-w-lg py-4 break-words whitespace-normal',
                                            isList &&
                                                c.id === defaults[0]?.id &&
                                                'text-sm font-medium',
                                            isList &&
                                                typeof value(row, c.id) ===
                                                    'number' &&
                                                'tabular-nums',
                                        )}
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
                                        {isList && search ? (
                                            <Button
                                                variant="outline"
                                                onClick={() => setSearch('')}
                                            >
                                                Azzera ricerca
                                            </Button>
                                        ) : null}
                                    </Empty>
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
            {isList ? (
                needsPagination ? (
                    <TablePagination
                        summary={`${pageRows.length}/${sorted.length}`}
                        page={page}
                        pageCount={pageCount}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={setPageSize}
                    />
                ) : (
                    <p role="status" className="sr-only">
                        {sorted.length} risultati
                    </p>
                )
            ) : null}
        </div>
    );
}
