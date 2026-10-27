import type { TableColumn } from '../components/table-columns';

export type TablePreferences<Id extends string> = {
    columns: TableColumn<Id>[];
    separators: boolean;
};

export function restoreTablePreferences<Id extends string>(
    defaults: TablePreferences<Id>,
    stored: string | null,
): TablePreferences<Id> {
    if (!stored) return defaults;

    try {
        const saved = JSON.parse(stored);
        if (!saved || !Array.isArray(saved.columns)) return defaults;

        const columns: TableColumn<Id>[] = [];
        for (const item of saved.columns) {
            const column = defaults.columns.find(
                (column) => column.id === item?.id,
            );
            if (!column || columns.some((column) => column.id === item.id))
                continue;
            columns.push({
                ...column,
                visible:
                    typeof item.visible === 'boolean'
                        ? item.visible
                        : column.visible,
            });
        }
        columns.push(
            ...defaults.columns.filter(
                (column) => !columns.some((item) => item.id === column.id),
            ),
        );

        return {
            columns: columns.some((column) => column.visible)
                ? columns
                : defaults.columns,
            separators:
                typeof saved.separators === 'boolean'
                    ? saved.separators
                    : defaults.separators,
        };
    } catch {
        return defaults;
    }
}
