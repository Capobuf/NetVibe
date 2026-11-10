import { Head, Link, router } from '@inertiajs/react';
import { FileText, Pencil } from 'lucide-react';
import { useSiteContext } from '@/hooks/use-site-context';
import { DataTable } from '@/components/data-table';
import { RackForm, RackDelete } from '@/components/racks/rack-form';
import { RackThumbnail } from '@/components/racks/rack-thumbnail';
import { IconButton } from '@/components/icon-button';
import { PrintMenu } from '@/components/print/print-menu';
import TextLink from '@/components/text-link';
import { documentation, index, show } from '@/routes/companies/sites/racks';
import type { Company, Site } from '@/types/company';
import type { Rack } from '@/types/rack';

type Props = { company: Company; site: Site; racks: Rack[] };
export default function Racks({ company, site, racks }: Props) {
    const route = { company: company.id, site: site.id };
    const context = useSiteContext(company, site);
    const value = (
        rack: Rack,
        column: 'name' | 'floor' | 'height_u' | 'numbering' | 'devices_count',
    ) =>
        column === 'floor'
            ? (rack.floor ?? '—')
            : column === 'numbering'
              ? rack.numbering === 'bottom-up'
                  ? 'Dal basso verso l’alto'
                  : 'Dall’alto verso il basso'
              : (rack[column] ?? 0);
    return (
        <>
            <Head title={`Rack · ${context}`} />
            <div className="flex min-w-0 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {context}
                        </p>
                        <h1 className="text-2xl font-medium tracking-tight">
                            Rack
                        </h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <PrintMenu
                            documents={[
                                {
                                    label: 'Piano di Installazione',
                                    icon: FileText,
                                    onSelect: () =>
                                        router.visit(documentation(route)),
                                },
                            ]}
                        />
                        <RackForm {...route} />
                    </div>
                </header>
                <DataTable
                    name="racks"
                    rows={racks}
                    columns={[
                        { id: 'name', label: 'Nome', visible: true },
                        { id: 'floor', label: 'Piano', visible: true },
                        { id: 'height_u', label: 'Altezza (U)', visible: true },
                        {
                            id: 'numbering',
                            label: 'Numerazione',
                            visible: true,
                        },
                        {
                            id: 'devices_count',
                            label: 'Dispositivi',
                            visible: true,
                        },
                    ]}
                    value={value}
                    render={(rack, column) =>
                        column === 'name' ? (
                            <TextLink
                                href={show({ ...route, rack: rack.id })}
                                className="inline-flex items-center gap-3"
                            >
                                <RackThumbnail rack={rack} />
                                <span>{rack.name}</span>
                            </TextLink>
                        ) : (
                            value(rack, column)
                        )
                    }
                    onRowClick={(rack) =>
                        router.visit(show({ ...route, rack: rack.id }))
                    }
                    rowOpensDialog={false}
                    actions={(rack) => (
                        <>
                            <IconButton label={`Modifica ${rack.name}`} asChild>
                                <Link
                                    href={show(
                                        { ...route, rack: rack.id },
                                        { query: { edit: 1 } },
                                    )}
                                >
                                    <Pencil />
                                </Link>
                            </IconButton>
                            <RackDelete {...route} rack={rack} />
                        </>
                    )}
                    empty="Nessun rack"
                />
            </div>
        </>
    );
}
Racks.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        { title: 'Rack', href: index({ company: company.id, site: site.id }) },
    ],
});
