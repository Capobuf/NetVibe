import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { TableHead } from '@/components/ui/table';
import { nextTableSort, type TableSort } from '@/lib/table-sort';

export function TableSortHead<Id extends string>({
    column,
    sort,
    onSort,
}: {
    column: { id: Id; label: string; sortable?: boolean };
    sort: TableSort<Id>;
    onSort: (sort: TableSort<Id>) => void;
}) {
    const direction = sort?.column === column.id ? sort.direction : null;
    if (column.sortable === false)
        return <TableHead scope="col">{column.label}</TableHead>;
    return (
        <TableHead
            scope="col"
            aria-sort={
                direction === 'asc'
                    ? 'ascending'
                    : direction === 'desc'
                      ? 'descending'
                      : 'none'
            }
        >
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="-ml-2"
                onClick={() => onSort(nextTableSort(sort, column.id))}
                aria-label={`${column.label}: ${direction === 'asc' ? 'ordina decrescente' : direction === 'desc' ? 'ripristina ordine predefinito' : 'ordina crescente'}`}
            >
                {column.label}
                {direction === 'asc' ? (
                    <ArrowUp />
                ) : direction === 'desc' ? (
                    <ArrowDown />
                ) : (
                    <ArrowUpDown className="text-muted-foreground" />
                )}
            </Button>
        </TableHead>
    );
}
