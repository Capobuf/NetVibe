import { Head } from '@inertiajs/react';
import { DataTable } from '@/components/data-table';
import IpStatusForm from '@/components/networks/ip-status-form';
import { index } from '@/routes/administration/ip-statuses';
import { index as administration } from '@/routes/administration';
import type { IpStatus } from '@/types/network';

type Props = { statuses: (IpStatus & { ip_addresses_count: number })[] };

export default function IpStatusIndex({ statuses }: Props) {
    return (
        <>
            <Head title="Stati IP" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-medium tracking-tight text-balance">
                        Stati IP
                    </h1>
                    <IpStatusForm />
                </header>
                <DataTable
                    name="ip-statuses"
                    variant="list"
                    searchLabel="Cerca stati IP"
                    rows={statuses}
                    columns={[
                        { id: 'name', label: 'Stato', visible: true },
                        {
                            id: 'ip_addresses_count',
                            label: 'IP documentati',
                            visible: true,
                        },
                    ]}
                    value={(status, column) => status[column]}
                    actions={(status) => <IpStatusForm status={status} />}
                    empty="Nessuno stato"
                />
            </div>
        </>
    );
}

IpStatusIndex.layout = () => ({
    breadcrumbs: [
        { title: 'Amministrazione', href: administration() },
        { title: 'Stati IP', href: index() },
    ],
});
