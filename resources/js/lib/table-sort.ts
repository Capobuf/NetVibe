export type TableSort<Id extends string> = {
    column: Id;
    direction: 'asc' | 'desc';
} | null;

export function nextTableSort<Id extends string>(
    sort: TableSort<Id>,
    column: Id,
): TableSort<Id> {
    if (sort?.column !== column) return { column, direction: 'asc' };
    return sort.direction === 'asc' ? { column, direction: 'desc' } : null;
}

export function sortTableRows<Row, Id extends string>(
    rows: Row[],
    sort: TableSort<Id>,
    value: (row: Row, column: Id) => string | number | null,
): Row[] {
    if (!sort) return rows;

    return rows.toSorted((a, b) => {
        const left = value(a, sort.column);
        const right = value(b, sort.column);
        const comparison =
            typeof left === 'number' && typeof right === 'number'
                ? left - right
                : String(left ?? '').localeCompare(String(right ?? ''), 'it', {
                      numeric: true,
                  });
        return comparison * (sort.direction === 'asc' ? 1 : -1);
    });
}
