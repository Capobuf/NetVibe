import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CableFields, EndpointPicker } from './cable-inspector';
import { CablePreview } from './cable-preview';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    cableError,
    normalizeCableType,
    type Cable,
    type CableEnd,
} from '@/lib/rack-cabling';
import type { RackDevice } from '@/types/rack';

export function CableCreateDialog({
    id,
    rackId,
    devices,
    cables,
    initialEnds,
    onClose,
    onDirtyChange,
    onConfirm,
}: {
    id: number;
    rackId: number;
    devices: RackDevice[];
    cables: Cable[];
    initialEnds?: { a: CableEnd; b: CableEnd };
    onClose: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onConfirm: (cable: Cable) => boolean;
}) {
    const form = useForm<Cable>({
        id,
        a: initialEnds?.a ?? { device_id: 0, connector_id: '' },
        b: initialEnds?.b ?? { device_id: 0, connector_id: '' },
        type: 'copper',
        color: '#2563eb',
        purpose: null,
        name: null,
    });
    const [error, setError] = useState('');
    const [imageError, setImageError] = useState('');
    const [discard, setDiscard] = useState(false);
    useEffect(() => {
        onDirtyChange(form.isDirty);
    }, [form.isDirty, onDirtyChange]);
    useEffect(() => () => onDirtyChange(false), [onDirtyChange]);
    const rackDevices = devices
        .filter((device) => device.rack_item?.rack_id === rackId)
        .map((device) => device.id);
    const problem =
        cableError(form.data, cables, devices) ||
        (![form.data.a, form.data.b].some((end) =>
            rackDevices.includes(end.device_id),
        )
            ? 'Almeno un’estremità deve appartenere al rack aperto.'
            : null);
    const feedback =
        error ||
        (form.data.a.connector_id && form.data.b.connector_id
            ? problem
            : null) ||
        imageError;
    function change(cable: Cable) {
        form.setData({ ...form.data, ...normalizeCableType(cable, devices) });
        setError('');
    }
    function selectEnd(side: 'a' | 'b', end: CableEnd) {
        change({ ...form.data, [side]: end });
    }
    function close() {
        if (form.isDirty) setDiscard(true);
        else onClose();
    }
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="sm:max-w-5xl"
                    aria-describedby={undefined}
                >
                    <DialogHeader className="border-b pb-4">
                        <DialogTitle>Nuovo collegamento</DialogTitle>
                    </DialogHeader>
                    <form
                        className="flex min-w-0 flex-col gap-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (problem) {
                                setError(problem);
                                return;
                            }
                            if (onConfirm(form.data)) onClose();
                        }}
                    >
                        <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                            {(['a', 'b'] as const).map((side) => (
                                <section
                                    key={side}
                                    className="min-w-0 space-y-4 rounded-xl border bg-muted/20 p-4"
                                >
                                    <h3 className="text-base font-medium">
                                        {side === 'a'
                                            ? 'Partenza'
                                            : 'Destinazione'}
                                    </h3>
                                    <EndpointPicker
                                        label={
                                            side === 'a'
                                                ? 'Partenza'
                                                : 'Destinazione'
                                        }
                                        value={form.data[side]}
                                        devices={devices}
                                        cables={cables}
                                        cableId={form.data.id}
                                        disabled={false}
                                        otherEnd={
                                            form.data[side === 'a' ? 'b' : 'a']
                                        }
                                        allowedDeviceIds={
                                            form.data[side === 'a' ? 'b' : 'a']
                                                .device_id &&
                                            !rackDevices.includes(
                                                form.data[
                                                    side === 'a' ? 'b' : 'a'
                                                ].device_id,
                                            )
                                                ? rackDevices
                                                : undefined
                                        }
                                        onChange={(end) => selectEnd(side, end)}
                                    />
                                </section>
                            ))}
                        </div>
                        <CableFields
                            cable={form.data}
                            devices={devices}
                            disabled={false}
                            onChange={change}
                            className="grid items-start gap-5 sm:grid-cols-2"
                        />
                        {feedback && (
                            <Alert variant="destructive">
                                <AlertDescription>{feedback}</AlertDescription>
                            </Alert>
                        )}
                        {!!(form.data.a.device_id || form.data.b.device_id) && (
                            <CablePreview
                                cable={form.data}
                                devices={devices}
                                connected={!problem}
                                onError={setImageError}
                            />
                        )}
                        <DialogFooter className="border-t pt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={close}
                            >
                                Annulla
                            </Button>
                            <Button type="submit">Aggiungi alla bozza</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog open={discard} onOpenChange={setDiscard}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            Scartare il nuovo collegamento?
                        </DialogTitle>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDiscard(false)}
                        >
                            Continua
                        </Button>
                        <Button variant="destructive" onClick={onClose}>
                            Scarta
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
