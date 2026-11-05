import { useForm } from '@inertiajs/react';
import { Boxes, RefreshCw, Settings2 } from 'lucide-react';
import {
    IntegrationHeader,
    SectionTabs,
    type IntegrationState,
} from '@/components/integration-header';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/utils';
import {
    edit,
    records,
    sync as refresh,
} from '@/routes/companies/settings/integrations/snipe-it';

export type SnipeItCompanyState = {
    connected: boolean;
    association: { name: string; confirmed: boolean } | null;
    sync: { synced_at: string | null; error: string | null } | null;
};

/** Header shared by the company Snipe-IT sections, with the manual sync action. */
export function SnipeItCompanyHeader({
    company,
    state,
}: {
    company: number;
    state: SnipeItCompanyState;
}) {
    const form = useForm<{ sync?: string }>({});
    const { connected, association, sync } = state;
    const status: IntegrationState =
        !connected || !association
            ? 'unconfigured'
            : !association.confirmed
              ? 'suspended'
              : form.errors.sync || sync?.error
                ? 'error'
                : sync?.synced_at
                  ? 'active'
                  : 'pending';
    const ready = !!association?.confirmed;

    return (
        <IntegrationHeader
            mark={<SnipeItMark className="size-5" />}
            title="Snipe-IT"
            state={status}
            description={
                form.errors.sync || sync?.error ? (
                    <span className="text-destructive">
                        {form.errors.sync ?? sync?.error}
                    </span>
                ) : association ? (
                    [
                        `Azienda Snipe-IT: ${association.name}`,
                        sync?.synced_at
                            ? `ultima lettura ${formatDateTime(sync.synced_at)}`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')
                ) : (
                    'Nessuna azienda Snipe-IT associata'
                )
            }
            actions={
                ready ? (
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() =>
                            form.submit(refresh(company), {
                                preserveScroll: true,
                            })
                        }
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <RefreshCw data-icon="inline-start" />
                        )}
                        {sync?.synced_at
                            ? 'Sincronizza ora'
                            : 'Avvia sincronizzazione'}
                    </Button>
                ) : null
            }
        />
    );
}

export function SnipeItSections({
    company,
    value,
    pending,
}: {
    company: number;
    value: 'assets' | 'settings';
    pending?: number;
}) {
    return (
        <SectionTabs
            label="Snipe-IT"
            value={value}
            sections={[
                {
                    value: 'assets',
                    label: 'Asset',
                    icon: Boxes,
                    count: pending,
                    href: records(company),
                },
                {
                    value: 'settings',
                    label: 'Configurazione',
                    icon: Settings2,
                    href: edit(company),
                },
            ]}
        />
    );
}
