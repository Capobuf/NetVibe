import { Form, usePage } from '@inertiajs/react';
import { SlidersHorizontal, Plus, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ipv4Number, ipv4RangeSummary } from '@/lib/ipv4';
import {
    destroy,
    store,
    update,
} from '@/routes/companies/sites/subnets/excluded-ranges';
import type { ExcludedRange } from '@/types/network';

export default function ExcludedRanges({
    companyId,
    siteId,
    subnetId,
    subnetCidr,
    context,
    excludedRanges,
}: {
    companyId: number;
    siteId: number;
    subnetId: number;
    subnetCidr: string;
    context: string;
    excludedRanges: ExcludedRange[];
}) {
    const { auth } = usePage().props;
    const [mode, setMode] = useState<'list' | 'add' | 'edit' | 'remove'>(
        'list',
    );
    const [editingId, setEditingId] = useState<number | null>(null);
    const [startIp, setStartIp] = useState('');
    const [endIp, setEndIp] = useState('');
    const id = useId();
    const ipPrefix = subnetCidr.slice(0, subnetCidr.lastIndexOf('.') + 1);
    const route = { company: companyId, site: siteId, subnet: subnetId };
    const start = ipv4Number(startIp);
    const end = ipv4Number(endIp);
    const rangeSummary = ipv4RangeSummary(subnetCidr, startIp, endIp);
    const overlapping =
        start !== null && end !== null && start <= end
            ? excludedRanges.filter(
                  (range) =>
                      (mode !== 'edit' || range.id !== editingId) &&
                      ipv4Number(range.start_ip)! <= end &&
                      ipv4Number(range.end_ip)! >= start,
              )
            : [];
    const first = overlapping[0];
    const last = overlapping.at(-1);
    const mergedStart =
        first && ipv4Number(first.start_ip)! < start!
            ? first.start_ip
            : startIp;
    const mergedEnd =
        last && ipv4Number(last.end_ip)! > end! ? last.end_ip : endIp;

    return (
        <Dialog
            onOpenChange={(open) => {
                if (open) setMode('list');
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">
                    <SlidersHorizontal data-icon="inline-start" />
                    Esclusioni
                    <Badge variant="secondary" className="tabular-nums">
                        {excludedRanges.length}
                    </Badge>
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {mode === 'list'
                            ? 'Intervalli esclusi'
                            : mode === 'add'
                              ? 'Escludi un intervallo'
                              : mode === 'edit'
                                ? 'Modifica intervallo'
                                : 'Rimuovi esclusione'}
                    </DialogTitle>
                    <DialogDescription>{context}</DialogDescription>
                </DialogHeader>
                {mode === 'list' ? (
                    <>
                        <div className="overflow-hidden rounded-lg border">
                            <Table aria-label="Intervalli esclusi dai suggerimenti">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Da IPv4</TableHead>
                                        <TableHead>A IPv4</TableHead>
                                        {auth.canEditIpam ? (
                                            <TableHead>
                                                <span className="sr-only">
                                                    Azioni
                                                </span>
                                            </TableHead>
                                        ) : null}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {excludedRanges.map((range) => (
                                        <TableRow key={range.start_ip}>
                                            <TableCell className="tabular-nums">
                                                {range.start_ip}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {range.end_ip}
                                            </TableCell>
                                            {auth.canEditIpam ? (
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => {
                                                                setEditingId(
                                                                    range.id,
                                                                );
                                                                setStartIp(
                                                                    range.start_ip,
                                                                );
                                                                setEndIp(
                                                                    range.end_ip,
                                                                );
                                                                setMode('edit');
                                                            }}
                                                            aria-label={`Modifica esclusione ${range.start_ip} – ${range.end_ip}`}
                                                        >
                                                            Modifica
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => {
                                                                setStartIp(
                                                                    range.start_ip,
                                                                );
                                                                setEndIp(
                                                                    range.end_ip,
                                                                );
                                                                setMode(
                                                                    'remove',
                                                                );
                                                            }}
                                                            aria-label={`Rimuovi esclusione ${range.start_ip} – ${range.end_ip}`}
                                                        >
                                                            Rimuovi
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            ) : null}
                                        </TableRow>
                                    ))}
                                    {excludedRanges.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={
                                                    auth.canEditIpam
                                                        ? 3
                                                        : 2
                                                }
                                                className="h-24 text-center text-muted-foreground"
                                            >
                                                Nessun intervallo escluso.
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                </TableBody>
                            </Table>
                        </div>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button variant="outline">Chiudi</Button>
                            </DialogClose>
                            {auth.canEditIpam ? (
                                <Button
                                    onClick={() => {
                                        setStartIp(ipPrefix);
                                        setEndIp(ipPrefix);
                                        setMode('add');
                                    }}
                                >
                                    <Plus data-icon="inline-start" />
                                    Aggiungi intervallo
                                </Button>
                            ) : null}
                        </DialogFooter>
                    </>
                ) : (
                    <Form
                        {...(mode === 'add'
                            ? store.form(route)
                            : mode === 'edit'
                              ? update.form({
                                    ...route,
                                    excludedRange: editingId!,
                                })
                              : destroy.form(route))}
                        onSuccess={() => setMode('list')}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                {mode !== 'add' ? (
                                    <p className="text-sm text-muted-foreground">
                                        {mode === 'remove'
                                            ? 'Puoi indicare l’intero intervallo o solo la porzione da rimuovere. Le parti esterne resteranno escluse.'
                                            : 'I nuovi estremi sostituiscono quelli attuali. Gli IP lasciati fuori non saranno più esclusi da questo intervallo.'}
                                    </p>
                                ) : null}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor={`${id}-start`}>
                                            Da IPv4
                                        </Label>
                                        <Input
                                            id={`${id}-start`}
                                            name="start_ip"
                                            value={startIp}
                                            onChange={(event) =>
                                                setStartIp(event.target.value)
                                            }
                                            required
                                            maxLength={15}
                                            autoFocus
                                            disabled={processing}
                                            aria-invalid={Boolean(
                                                errors.start_ip,
                                            )}
                                            aria-describedby={`${id}-start-error`}
                                        />
                                        <InputError
                                            id={`${id}-start-error`}
                                            message={errors.start_ip}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor={`${id}-end`}>
                                            A IPv4
                                        </Label>
                                        <Input
                                            id={`${id}-end`}
                                            name="end_ip"
                                            value={endIp}
                                            onChange={(event) =>
                                                setEndIp(event.target.value)
                                            }
                                            required
                                            maxLength={15}
                                            disabled={processing}
                                            aria-invalid={Boolean(
                                                errors.end_ip,
                                            )}
                                            aria-describedby={`${id}-end-error`}
                                        />
                                        <InputError
                                            id={`${id}-end-error`}
                                            message={errors.end_ip}
                                        />
                                    </div>
                                </div>
                                {rangeSummary ? (
                                    <Card className="gap-3 py-4">
                                        <CardHeader className="gap-2 px-4">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <CardTitle>
                                                    Intervallo selezionato
                                                </CardTitle>
                                                <Badge
                                                    variant="secondary"
                                                    aria-live="polite"
                                                >
                                                    {rangeSummary.count.toLocaleString(
                                                        'it-IT',
                                                    )}{' '}
                                                    IP
                                                </Badge>
                                            </div>
                                            <CardDescription>
                                                {startIp} – {endIp}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="flex flex-col gap-2 px-4">
                                            <Progress
                                                value={
                                                    (rangeSummary.count /
                                                        rangeSummary.total) *
                                                    100
                                                }
                                                aria-label="Intervallo selezionato nella subnet"
                                                aria-valuetext={`${startIp} – ${endIp}: ${rangeSummary.count} IP`}
                                                indicatorStyle={{
                                                    transform: 'none',
                                                    marginLeft: `${rangeSummary.offset}%`,
                                                    width: `${(rangeSummary.count / rangeSummary.total) * 100}%`,
                                                    minWidth: '1px',
                                                }}
                                            />
                                            <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                                                <span>{subnetCidr}</span>
                                                <span>
                                                    {rangeSummary.total.toLocaleString(
                                                        'it-IT',
                                                    )}{' '}
                                                    IP totali
                                                </span>
                                            </div>
                                        </CardContent>
                                    </Card>
                                ) : null}
                                {mode !== 'remove' && overlapping.length > 0 ? (
                                    <Alert>
                                        <TriangleAlert />
                                        <AlertTitle>
                                            Intervalli sovrapposti
                                        </AlertTitle>
                                        <AlertDescription>
                                            Al salvataggio verranno uniti
                                            nell’intervallo {mergedStart} –{' '}
                                            {mergedEnd}. Gli IP documentati
                                            saranno conservati.
                                        </AlertDescription>
                                    </Alert>
                                ) : null}
                                {mode === 'remove' ? (
                                    <>
                                        <input
                                            type="hidden"
                                            name="confirmed"
                                            value="1"
                                        />
                                        <Alert>
                                            <TriangleAlert />
                                            <AlertTitle>
                                                Conferma rimozione
                                            </AlertTitle>
                                            <AlertDescription>
                                                Verrà rimossa solo l’esclusione
                                                fra {startIp || '…'} e{' '}
                                                {endIp || '…'}. Gli IP
                                                documentati resteranno occupati;
                                                gli altri indirizzi utilizzabili
                                                potranno essere proposti.
                                            </AlertDescription>
                                        </Alert>
                                        <InputError
                                            message={errors.confirmed}
                                        />
                                    </>
                                ) : null}
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                        onClick={() => setMode('list')}
                                    >
                                        Annulla
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant={
                                            mode === 'remove'
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        disabled={processing}
                                    >
                                        {processing ? <Spinner /> : null}
                                        {mode === 'add'
                                            ? 'Escludi intervallo'
                                            : mode === 'edit'
                                              ? 'Salva modifiche'
                                              : 'Conferma rimozione'}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
