import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { TableColumn } from '@/components/table-columns';
import {
    restoreTablePreferences,
    type TablePreferences,
} from '@/lib/table-preferences';

export function useTablePreferences<Id extends string>(
    table: string,
    defaultColumns: TableColumn<Id>[],
    defaultSeparators = false,
) {
    const { auth } = usePage().props;
    const key = `netvibe:tables:v1:${auth.user.id}:${table}`;
    const [preferences, setPreferences] = useState<TablePreferences<Id>>(() => {
        const defaults = {
            columns: defaultColumns,
            separators: defaultSeparators,
        };
        try {
            return restoreTablePreferences(defaults, localStorage.getItem(key));
        } catch {
            return defaults;
        }
    });

    function update(changes: Partial<TablePreferences<Id>>) {
        const next = { ...preferences, ...changes };
        setPreferences(next);
        try {
            localStorage.setItem(
                key,
                JSON.stringify({
                    columns: next.columns.map(({ id, visible }) => ({
                        id,
                        visible,
                    })),
                    separators: next.separators,
                }),
            );
        } catch {
            toast.error(
                'Impossibile salvare le preferenze. Consenti il salvataggio dei dati del sito nel browser.',
            );
        }
    }

    return {
        ...preferences,
        setColumns: (columns: TableColumn<Id>[]) => update({ columns }),
        setSeparators: (separators: boolean) => update({ separators }),
    };
}
