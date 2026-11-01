import { formatDateTime } from '@/lib/utils';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ExternalLink, TriangleAlert, Unlink } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    CardFooter,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogTrigger,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { detach } from '@/routes/companies/settings/integrations/snipe-it';
import type { Device } from '@/types/device';

export type SnipeDevice = {
    id: number;
    url: string;
    active: boolean;
    present: boolean;
    synced_at: string | null;
    error: string | null;
    problem: string | null;
    model_problem: string | null;
    snapshot: {
        name: string | null;
        asset_tag: string | null;
        serial: string | null;
    };
    model: {
        name: string | null;
        manufacturer: string | null;
        category: string | null;
    } | null;
};

export function snipeItProblem(source: SnipeDevice) {
    return !source.active
        ? 'Collegamento sospeso: riconferma l’azienda Snipe-IT.'
        : !source.present
          ? 'Asset non più presente nell’azienda Snipe-IT.'
          : source.error || source.problem || source.model_problem;
}

export function SnipeItDevice({
    company,
    source,
    device,
}: {
    company: number;
    source: SnipeDevice;
    device: Device;
}) {
    const { auth } = usePage().props;
    const problem = snipeItProblem(source);
    const differences = [
        {
            label: 'Nome',
            current: device.name,
            external: source.snapshot.name || source.snapshot.asset_tag,
        },
        {
            label: 'Seriale',
            current: device.serial_number,
            external: source.snapshot.serial,
        },
        {
            label: 'Asset tag',
            current: device.asset_tag ?? null,
            external: source.snapshot.asset_tag,
        },
        {
            label: 'Produttore',
            current: device.manufacturer.name,
            external: source.model?.manufacturer ?? null,
        },
        {
            label: 'Modello',
            current: device.model?.name ?? null,
            external: source.model?.name ?? null,
        },
        {
            label: 'Tipo',
            current: device.model?.model_type?.name ?? null,
            external:
                source.model?.category ??
                device.model?.model_type?.name ??
                null,
        },
    ].filter((field) => field.current !== field.external);
    return (
        <Card>
            <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                <CardTitle>Snipe-IT</CardTitle>
                <Badge variant={problem ? 'destructive' : 'secondary'}>
                    {problem ? 'Da verificare' : 'Collegato'}
                </Badge>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
                {source.synced_at ? (
                    <dl className="flex flex-col gap-1 text-sm">
                        <dt className="text-muted-foreground">
                            Ultimo aggiornamento
                        </dt>
                        <dd>
                            <time dateTime={source.synced_at}>
                                {formatDateTime(source.synced_at)}
                            </time>
                        </dd>
                    </dl>
                ) : null}
                <div className="flex flex-wrap items-center gap-3">
                    <Button asChild variant="outline" size="sm">
                        <a
                            href={source.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Apri in Snipe-IT (nuova scheda)"
                        >
                            Apri in Snipe-IT
                            <ExternalLink data-icon="inline-end" />
                        </a>
                    </Button>
                </div>
                {problem ? (
                    <Alert>
                        <TriangleAlert />
                        <AlertTitle className="line-clamp-none">
                            Aggiornamento da Snipe-IT da verificare
                        </AlertTitle>
                        <AlertDescription className="gap-3 text-foreground">
                            <p>{problem}</p>
                            {source.active &&
                            source.present &&
                            !source.error &&
                            differences.length > 0 ? (
                                <dl className="grid w-full gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {differences.map((field) => (
                                        <div
                                            key={field.label}
                                            className="min-w-0 rounded-md bg-muted/50 p-3"
                                        >
                                            <dt className="font-medium">
                                                {field.label}
                                            </dt>
                                            <dd className="mt-1 break-words">
                                                <span className="text-muted-foreground">
                                                    NetVibe:{' '}
                                                </span>
                                                {field.current ??
                                                    'Non indicato'}
                                            </dd>
                                            <dd className="break-words">
                                                <span className="text-muted-foreground">
                                                    Snipe-IT:{' '}
                                                </span>
                                                {field.external ??
                                                    'Non indicato'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            ) : null}
                        </AlertDescription>
                    </Alert>
                ) : null}
            </CardContent>
            {auth.user.is_superadmin ? (
                <CardFooter className="border-t pt-6">
                    <SnipeItDetach company={company} assetId={source.id} />
                </CardFooter>
            ) : null}
        </Card>
    );
}

export function SnipeItDetach({
    company,
    assetId,
}: {
    company: number;
    assetId: number;
}) {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);
    const form = useForm({
        asset_id: assetId,
        confirmed: true,
        exclude: true,
    });
    if (!auth.user.is_superadmin) return null;
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!form.processing) setOpen(value);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={form.processing}
                    aria-label="Scollega da Snipe-IT"
                >
                    <Unlink data-icon="inline-start" />
                    Scollega da Snipe-IT
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Scollega da Snipe-IT</DialogTitle>
                    <DialogDescription>
                        Il dispositivo diventa manuale. IP, interfacce, rack,
                        cablaggi e note restano conservati.
                    </DialogDescription>
                </DialogHeader>
                <Field orientation="horizontal">
                    <Checkbox
                        id="exclude-detached"
                        checked={form.data.exclude}
                        disabled={form.processing}
                        onCheckedChange={(value) =>
                            form.setData('exclude', value === true)
                        }
                    />
                    <FieldLabel htmlFor="exclude-detached">
                        Escludi dalle prossime importazioni
                    </FieldLabel>
                </Field>
                {Object.values(form.errors)[0] ? (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {Object.values(form.errors)[0]}
                        </AlertDescription>
                    </Alert>
                ) : null}
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => setOpen(false)}
                    >
                        Annulla
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={() =>
                            form.submit(detach(company), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setOpen(false);
                                },
                            })
                        }
                    >
                        {form.processing ? <Spinner /> : null}Scollega
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
