import { formatDateTime } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, RefreshCw } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import {
    records,
    sync as refresh,
} from '@/routes/companies/settings/integrations/snipe-it';

export type SyncSummary = {
    id: number;
    active: boolean;
    activated_at: string | null;
    synced_at: string | null;
    error: string | null;
    linked: number;
    ipam: number;
    review: number;
    without_ip: number;
    excluded: number;
};

export function SnipeItOverview({
    company,
    sync,
    confirmed,
    onConfigure,
}: {
    company: number;
    sync: SyncSummary | null;
    confirmed: boolean;
    onConfigure: () => void;
}) {
    const form = useForm<{ sync?: string }>({});
    const error = form.errors.sync || sync?.error;
    const groups = [
        { view: 'ipam', label: 'Client nell’IPAM', count: sync?.ipam ?? 0 },
        {
            view: 'review',
            label: 'Da verificare',
            count: sync?.review ?? 0,
        },
        {
            view: 'linked',
            label: 'Apparati tecnici collegati',
            count: sync?.linked ?? 0,
        },
        {
            view: 'no-ip',
            label: 'Senza IP Ethernet',
            count: sync?.without_ip ?? 0,
        },
        { view: 'excluded', label: 'Esclusi', count: sync?.excluded ?? 0 },
    ];

    return (
        <Card>
            <CardHeader className="gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle>Sincronizzazione</CardTitle>
                    {confirmed ? (
                        <Button
                            disabled={form.processing}
                            onClick={() =>
                                form.submit(refresh(company), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            {form.processing ? <Spinner /> : <RefreshCw />}
                            {form.processing
                                ? 'Sincronizzazione…'
                                : 'Sincronizza ora'}
                        </Button>
                    ) : (
                        <Button onClick={onConfigure}>
                            Configura connessione
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                    <Badge variant="outline">
                        {!confirmed
                            ? 'Associazione azienda da confermare'
                            : sync?.activated_at
                              ? 'Ogni ora'
                              : 'Da avviare'}
                    </Badge>
                    {sync?.synced_at ? (
                        <span>
                            Ultima sincronizzazione:{' '}
                            {formatDateTime(sync.synced_at)}
                        </span>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {error ? (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                ) : null}
                <div className="flex flex-col divide-y">
                    {groups.map(({ view, label, count }) => (
                        <Button
                            key={view}
                            asChild
                            variant="ghost"
                            className="h-auto justify-between gap-4 rounded-none px-0 py-4"
                        >
                            <Link href={records(company, { query: { view } })}>
                                <span>{label}</span>
                                <span className="flex items-center gap-3">
                                    <Badge
                                        variant={
                                            view === 'review' && count
                                                ? 'outline'
                                                : 'secondary'
                                        }
                                    >
                                        {count}
                                    </Badge>
                                    <ArrowRight />
                                </span>
                            </Link>
                        </Button>
                    ))}
                </div>
            </CardContent>
            <CardFooter>
                <Button asChild variant="outline">
                    <Link
                        href={records(company, { query: { view: 'devices' } })}
                    >
                        Scegli apparati tecnici
                    </Link>
                </Button>
            </CardFooter>
        </Card>
    );
}
