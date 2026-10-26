import { ArrowDown, ArrowUp, Columns3, GripVertical } from 'lucide-react';
import { useRef, useState } from 'react';
import { IconButton } from '@/components/icon-button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export type CompanyColumn = {
    id: 'name' | 'sites_count';
    label: string;
    visible: boolean;
};

export function TableColumns({
    columns,
    onChange,
}: {
    columns: CompanyColumn[];
    onChange: (columns: CompanyColumn[]) => void;
}) {
    const dragged = useRef<CompanyColumn['id'] | null>(null);
    const [announcement, setAnnouncement] = useState('');

    function move(id: CompanyColumn['id'], target: number) {
        const reordered = [...columns];
        const from = reordered.findIndex((column) => column.id === id);
        if (from === target) return;
        const [column] = reordered.splice(from, 1);
        reordered.splice(target, 0, column);
        onChange(reordered);
        setAnnouncement(`${column.label}: posizione ${target + 1}.`);
    }

    return (
        <Dialog>
            <DialogTrigger asChild>
                <IconButton label="Configura colonne">
                    <Columns3 />
                </IconButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Colonne della tabella</DialogTitle>
                    <DialogDescription>
                        Scegli quali mostrare. Trascina la maniglia o usa le
                        frecce per cambiarne l’ordine.
                    </DialogDescription>
                </DialogHeader>
                <fieldset className="flex flex-col gap-2">
                    <legend className="sr-only">
                        Visibilità e ordine delle colonne
                    </legend>
                    {columns.map((column, position) => (
                        <div
                            key={column.id}
                            className="flex items-center gap-3 rounded-md border p-2"
                            onDragOver={(event) => {
                                if (dragged.current) {
                                    event.preventDefault();
                                    event.dataTransfer.dropEffect = 'move';
                                }
                            }}
                            onDrop={(event) => {
                                event.preventDefault();
                                if (dragged.current)
                                    move(dragged.current, position);
                                dragged.current = null;
                            }}
                        >
                            <IconButton
                                label={`Trascina ${column.label}`}
                                variant="ghost"
                                className="cursor-grab active:cursor-grabbing"
                                draggable
                                onDragStart={(event) => {
                                    dragged.current = column.id;
                                    event.dataTransfer.effectAllowed = 'move';
                                    event.dataTransfer.setData(
                                        'text/plain',
                                        column.id,
                                    );
                                }}
                                onDragEnd={() => {
                                    dragged.current = null;
                                }}
                            >
                                <GripVertical />
                            </IconButton>
                            <Checkbox
                                id={`column-${column.id}`}
                                checked={column.visible}
                                disabled={
                                    column.visible &&
                                    columns.filter((item) => item.visible)
                                        .length === 1
                                }
                                onCheckedChange={(checked) =>
                                    onChange(
                                        columns.map((item) =>
                                            item.id === column.id
                                                ? {
                                                      ...item,
                                                      visible: checked === true,
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            />
                            <Label
                                htmlFor={`column-${column.id}`}
                                className="flex-1"
                            >
                                {column.label}
                            </Label>
                            <IconButton
                                label={`Sposta ${column.label} prima`}
                                variant="ghost"
                                disabled={position === 0}
                                onClick={() => move(column.id, position - 1)}
                            >
                                <ArrowUp />
                            </IconButton>
                            <IconButton
                                label={`Sposta ${column.label} dopo`}
                                variant="ghost"
                                disabled={position === columns.length - 1}
                                onClick={() => move(column.id, position + 1)}
                            >
                                <ArrowDown />
                            </IconButton>
                        </div>
                    ))}
                </fieldset>
                <p role="status" className="sr-only">
                    {announcement}
                </p>
            </DialogContent>
        </Dialog>
    );
}
