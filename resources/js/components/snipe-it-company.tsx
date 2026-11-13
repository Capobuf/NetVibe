import { useForm } from '@inertiajs/react';
import { Boxes, RefreshCw, Settings2, SlidersHorizontal } from 'lucide-react';
import {
    IntegrationHeader,
    intervalLabel,
    SectionTabs,
    StatusDetails,
    type IntegrationState,
} from '@/components/integration-header';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/utils';
import {
    edit,
    mapping,
    records,
    sync as refresh,
} from '@/routes/companies/settings/integrations/snipe-it';

export type SnipeItCompanyState = {
    connected: boolean;
    interval: number;
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
    const { connected, interval, association, sync } = state;
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
            details={
                association ? (
                    <StatusDetails
                        items={[
                            ['Azienda Snipe-IT', association.name],
                            ['Sincronizzazione', intervalLabel(interval)],
                            [
                                'Ultima lettura',
                                sync?.synced_at
                                    ? formatDateTime(sync.synced_at)
                                    : 'mai',
                            ],
                        ]}
                    />
                ) : undefined
            }
            description={
                form.errors.sync || sync?.error ? (
                    <span className="text-destructive">
                        {form.errors.sync ?? sync?.error}
                    </span>
                ) : association ? undefined : (
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
    configured,
}: {
    company: number;
    value: 'assets' | 'fields' | 'settings';
    pending?: number;
    configured: boolean;
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
                    disabled: !configured,
                },
                {
                    value: 'fields',
                    label: 'Mappatura campi',
                    icon: SlidersHorizontal,
                    href: mapping(company),
                    disabled: !configured,
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
