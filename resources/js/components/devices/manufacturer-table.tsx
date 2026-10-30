import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { CatalogForm } from '@/components/devices/catalog-form';
import { DeviceTable } from '@/components/devices/device-table';
import { IconButton } from '@/components/icon-button';
import TextLink from '@/components/text-link';
import { update, destroy } from '@/routes/manufacturers';
import { index } from '@/routes/device-models';
import type { Manufacturer } from '@/types/device';

export function ManufacturerTable({
    manufacturers,
}: {
    manufacturers: Manufacturer[];
}) {
    return (
        <DeviceTable
            name="manufacturers"
            rows={manufacturers}
            columns={[
                { id: 'name', label: 'Produttore', visible: true },
                { id: 'models_count', label: 'Modelli', visible: true },
                {
                    id: 'devices_count',
                    label: 'Dispositivi',
                    visible: true,
                },
            ]}
            value={(m, c) => m[c] ?? 0}
            render={(m, c) =>
                c === 'name' ? (
                    <TextLink href={index({ query: { manufacturer: m.id } })}>
                        {m.name}
                    </TextLink>
                ) : (
                    m[c]
                )
            }
            empty="Nessun produttore. Aggiungi il primo per creare modelli e dispositivi."
            actions={(m) => (
                <>
                    <CatalogForm
                        title="Modifica produttore"
                        item={m}
                        action={update.form(m.id)}
                        deletion={destroy.form(m.id)}
                    />
                    <IconButton label={`Apri modelli ${m.name}`} asChild>
                        <Link href={index({ query: { manufacturer: m.id } })}>
                            <ArrowRight />
                        </Link>
                    </IconButton>
                </>
            )}
        />
    );
}
