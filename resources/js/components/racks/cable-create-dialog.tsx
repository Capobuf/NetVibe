import { SaveButton } from '@/components/save-button';
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
import { store } from '@/routes/companies/sites/racks/cables';
import type { RackDevice } from '@/types/rack';

export function CableCreateDialog({
    id,
    route,
    devices,
    cables,
    revision,
    removedCableIds,
    initialEnds,
    onClose,
    onDirtyChange,
    onSaving,
    onSaved,
}: {
    id: number;
    route: { company: number; site: number; rack: number };
    devices: RackDevice[];
    cables: Cable[];
    revision: number;
    removedCableIds: number[];
    initialEnds?: { a: CableEnd; b: CableEnd };
    onClose: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onSaving: (saving: boolean) => void;
    onSaved: (cables: Cable[], revision: number) => void;
}) {
    const form = useForm<
        Cable & { cabling_revision: number; removed_cable_ids: number[] }
    >({
        id,
        a: initialEnds?.a ?? { device_id: 0, connector_id: '' },
        b: initialEnds?.b ?? { device_id: 0, connector_id: '' },
        type: 'copper',
        color: '#2563eb',
        purpose: null,
        name: null,
        cabling_revision: revision,
        removed_cable_ids: removedCableIds,
    });
    const [error, setError] = useState('');
    const [imageError, setImageError] = useState('');
    const [discard, setDiscard] = useState(false);
    useEffect(() => {
        onDirtyChange(form.isDirty);
    }, [form.isDirty, onDirtyChange]);
    useEffect(() => () => onDirtyChange(false), [onDirtyChange]);
    const rackDevices = devices
        .filter((device) => device.rack_item?.rack_id === route.rack)
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
        Object.values(form.errors).join(' ') ||
        (form.data.a.connector_id && form.data.b.connector_id
            ? problem
            : null) ||
        imageError;
    function change(cable: Cable) {
        form.setData({ ...form.data, ...normalizeCableType(cable, devices) });
        form.clearErrors();
        setError('');
    }
    function selectEnd(side: 'a' | 'b', end: CableEnd) {
        change({ ...form.data, [side]: end });
    }
    function close() {
        if (form.processing) return;
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
                            onSaving(true);
                            form.post(store.url(route), {
                                preserveScroll: true,
                                onSuccess: (page) => {
                                    onSaved(
                                        page.props.cables as Cable[],
                                        page.props.cablingRevision as number,
                                    );
                                    onClose();
                                },
                                onFinish: () => onSaving(false),
                            });
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
                                        disabled={form.processing}
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
                            disabled={form.processing}
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
                                disabled={form.processing}
                                onClick={close}
                            >
                                Annulla
                            </Button>
                            <SaveButton processing={form.processing}>
                                Salva collegamento
                            </SaveButton>
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
