import { useState, type ReactNode } from 'react';
import { Search } from 'lucide-react';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { TableSearch } from '@/components/table-search';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

export function DeviceTable<
    Row extends { id: number | string },
    Column extends string,
>({
    name,
    rows,
    columns: defaults,
    hiddenColumns = [],
    value,
    render,
    actions,
    onRowClick: handleRowClick,
    isRowClickable,
    rowOpensDialog = true,
    filters,
    headerActions,
    bulkActions,
    empty = 'Nessun risultato',
    variant = 'default',
    total = rows.length,
    filterKey = '',
    searchLabel = 'Cerca nell’elenco',
    defaultSort = null,
}: {
    name: string;
    rows: Row[];
    columns: TableColumn<Column>[];
    hiddenColumns?: Column[];
    value: (row: Row, column: Column) => string | number | null;
    render?: (row: Row, column: Column) => ReactNode;
    actions?: (row: Row) => ReactNode;
    onRowClick?: (row: Row) => void;
    isRowClickable?: (row: Row) => boolean;
    rowOpensDialog?: boolean;
    filters?: ReactNode;
    headerActions?: ReactNode;
    bulkActions?: (rows: Row[], clearSelection: () => void) => ReactNode;
    empty?: string;
    variant?: 'default' | 'list';
    total?: number;
    filterKey?: string;
    searchLabel?: string;
    defaultSort?: TableSort<Column>;
}) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<TableSort<Column>>(defaultSort);
    const [selectedIds, setSelectedIds] = useState<Row['id'][]>([]);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences(name, defaults);
    const availableColumns = columns.filter(
        (c) => !hiddenColumns.includes(c.id),
    );
    if (availableColumns.length && !availableColumns.some((c) => c.visible)) {
        availableColumns[0] = { ...availableColumns[0], visible: true };
    }
    const visible = availableColumns.filter((c) => c.visible);
    const filtered = rows.filter((row) =>
        defaults
            .map((c) => value(row, c.id))
            .join(' ')
            .toLocaleLowerCase()
            .includes(search.trim().toLocaleLowerCase()),
    );
    const sorted = sortTableRows(filtered, sort ?? defaultSort, value);
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
    const selected = filtered.filter((row) => selectedIds.includes(row.id));
    const allSelected =
        filtered.length > 0 && selected.length === filtered.length;
    function clearSelection() {
        setSelectedIds([]);
    }
    function changeSearch(value: string) {
        setSearch(value);
        clearSelection();
    }
    return (
        <div className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                {isList ? (
                    <TableSearch
                        value={search}
                        onChange={changeSearch}
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
                            onChange={(e) => changeSearch(e.target.value)}
                        />
                    </InputGroup>
                )}
                {filters}
                {bulkActions && selected.length > 0 ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <span role="status" className="text-sm tabular-nums">
                            {selected.length} selezionati
                        </span>
                        {bulkActions(selected, clearSelection)}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearSelection}
                        >
                            Annulla selezione
                        </Button>
                    </div>
                ) : null}
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
                            {bulkActions ? (
                                <TableHead className="w-12">
                                    <Checkbox
                                        aria-label="Seleziona tutti i risultati"
                                        checked={
                                            allSelected
                                                ? true
                                                : selected.length > 0
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        disabled={!filtered.length}
                                        onCheckedChange={(checked) =>
                                            setSelectedIds(
                                                checked === true
                                                    ? filtered.map(
                                                          (row) => row.id,
                                                      )
                                                    : [],
                                            )
                                        }
                                    />
                                </TableHead>
                            ) : null}
                            {visible.map((column) => (
                                <TableSortHead
                                    key={column.id}
                                    column={column}
                                    sort={sort}
                                    onSort={setSort}
                                />
                            ))}
                            <TableHead className="w-16 text-right">
                                <div className="flex items-center justify-end gap-1">
                                    {headerActions}
                                    <TableColumns
                                        columns={availableColumns}
                                        onChange={(next) => {
                                            let index = 0;
                                            setColumns(
                                                columns.map((column) =>
                                                    hiddenColumns.includes(
                                                        column.id,
                                                    )
                                                        ? column
                                                        : next[index++],
                                                ),
                                            );
                                        }}
                                        separators={separators}
                                        onSeparatorsChange={setSeparators}
                                    />
                                </div>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {(isList ? pageRows : sorted).map((row) => {
                            const onRowClick =
                                isRowClickable?.(row) === false
                                    ? undefined
                                    : handleRowClick;
                            return (
                                <TableRow
                                    key={row.id}
                                    data-state={
                                        bulkActions &&
                                        selectedIds.includes(row.id)
                                            ? 'selected'
                                            : undefined
                                    }
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
                                                          ?.isCollapsed !==
                                                          false &&
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
                                    {bulkActions ? (
                                        <TableCell>
                                            <Checkbox
                                                aria-label={`Seleziona ${value(row, defaults[0].id)}`}
                                                checked={selectedIds.includes(
                                                    row.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    setSelectedIds((current) =>
                                                        checked === true
                                                            ? [
                                                                  ...current,
                                                                  row.id,
                                                              ]
                                                            : current.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      row.id,
                                                              ),
                                                    )
                                                }
                                            />
                                        </TableCell>
                                    ) : null}
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
                            );
                        })}
                        {!sorted.length && (
                            <TableRow>
                                <TableCell
                                    colSpan={
                                        visible.length +
                                        1 +
                                        (bulkActions ? 1 : 0)
                                    }
                                >
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
                                                onClick={() => changeSearch('')}
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
