import { Link, useForm } from '@inertiajs/react';
import { Link2, RefreshCw } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    pending_sites: number;
    excluded: number;
};

export function SnipeItOverview({
    company,
    sync,
    confirmed,
}: {
    company: number;
    sync: SyncSummary | null;
    confirmed: boolean;
}) {
    const form = useForm({});
    return (
        <section
            aria-label="Sincronizzazione Snipe-IT"
            className="flex flex-col gap-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-base font-medium">Snipe-IT</h2>
                    <Badge variant="outline">
                        {!confirmed
                            ? 'Da riconfermare'
                            : sync?.activated_at
                              ? 'Sincronizzazione oraria'
                              : 'Prima associazione'}
                    </Badge>
                </div>
                <Button
                    variant="outline"
                    disabled={!confirmed || form.processing}
                    onClick={() =>
                        form.submit(refresh(company), { preserveScroll: true })
                    }
                >
                    {form.processing ? (
                        <Spinner />
                    ) : (
                        <RefreshCw data-icon="inline-start" />
                    )}
                    {sync?.synced_at
                        ? 'Sincronizza ora'
                        : 'Acquisisci anteprima'}
                </Button>
            </div>
            <p className="text-sm text-muted-foreground">
                {sync?.synced_at
                    ? `Ultimo aggiornamento riuscito: ${new Date(sync.synced_at).toLocaleString('it-IT')}`
                    : 'Nessuna acquisizione completata'}
            </p>
            {sync?.error ? (
                <Alert variant="destructive">
                    <AlertDescription>{sync.error}</AlertDescription>
                </Alert>
            ) : null}
            <div className="flex flex-wrap gap-3">
                <Button asChild variant="outline">
                    <Link
                        href={records(company, { query: { view: 'devices' } })}
                    >
                        <Link2 data-icon="inline-start" />
                        Collega dispositivi
                    </Link>
                </Button>
                {sync?.pending_sites ? (
                    <Button asChild>
                        <Link
                            href={records(company, {
                                query: { view: 'sites' },
                            })}
                        >
                            Da assegnare ({sync.pending_sites})
                        </Link>
                    </Button>
                ) : null}
                {sync?.excluded ? (
                    <Button asChild variant="ghost">
                        <Link
                            href={records(company, {
                                query: { view: 'excluded' },
                            })}
                        >
                            Esclusi ({sync.excluded})
                        </Link>
                    </Button>
                ) : null}
            </div>
        </section>
    );
}
