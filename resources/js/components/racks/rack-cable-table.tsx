import { Plus, Printer } from 'lucide-react';
import { DeviceTable } from '@/components/devices/device-table';
import { IconButton } from '@/components/icon-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cableCode, cableTypes, endLabel, type Cable } from '@/lib/rack-cabling';
import type { RackDevice } from '@/types/rack';

export function RackCableTable({
    cables,
    devices,
    selectedCableId,
    processing,
    createDisabled,
    onCreate,
    onRowClick,
    onPrint,
}: {
    cables: Cable[];
    devices: RackDevice[];
    selectedCableId: number | null;
    processing: boolean;
    createDisabled: boolean;
    onCreate: () => void;
    onRowClick: (cable: Cable) => void;
    onPrint: (cable: Cable) => void;
}) {
    return (
        <section className="flex flex-col gap-4" aria-label="Cablaggi del rack">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-base font-medium">Cablaggi</h2>
                <Button disabled={createDisabled} onClick={onCreate}>
                    <Plus />
                    Nuovo
                </Button>
            </div>
            <DeviceTable
                name="rack-cables"
                rows={cables}
                rowOpensDialog={false}
                columns={[
                    { id: 'code', label: 'Codice', visible: true },
                    { id: 'name', label: 'Nome', visible: true },
                    { id: 'a', label: 'Partenza', visible: true },
                    { id: 'b', label: 'Destinazione', visible: true },
                    { id: 'type', label: 'Tipo', visible: true },
                    { id: 'purpose', label: 'Scopo', visible: true },
                    { id: 'color', label: 'Colore', visible: true },
                ]}
                value={(cable, column) =>
                    column === 'code'
                        ? cableCode(cable)
                        : column === 'a' || column === 'b'
                          ? endLabel(cable[column], devices, '@')
                          : column === 'type'
                            ? cableTypes[cable.type]
                            : cable[column]
                }
                render={(cable, column) =>
                    column === 'color' ? (
                        <span
                            role="img"
                            aria-label={`Colore del cavo ${cable.color}`}
                            className="block h-1 w-16 rounded-sm ring-1 ring-foreground/20 ring-inset"
                            style={{ background: cable.color }}
                        />
                    ) : column === 'code' ? (
                        <Badge
                            variant={
                                selectedCableId === cable.id
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            {cableCode(cable)}
                        </Badge>
                    ) : column === 'a' || column === 'b' ? (
                        endLabel(cable[column], devices, '@')
                    ) : column === 'type' ? (
                        cableTypes[cable.type]
                    ) : (
                        cable[column] || '—'
                    )
                }
                onRowClick={onRowClick}
                actions={(cable) => (
                    <IconButton
                        label="Prepara etichette"
                        disabled={processing || cable.id < 1}
                        onClick={() => onPrint(cable)}
                    >
                        <Printer />
                    </IconButton>
                )}
                empty="Nessun cavo documentato"
            />
        </section>
    );
}
