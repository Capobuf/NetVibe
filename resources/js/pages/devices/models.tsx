import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, LayoutTemplate, Factory, Shapes } from 'lucide-react';
import { ListPageHeader } from '@/components/list-page-header';
import { Badge } from '@/components/ui/badge';
import { CatalogForm } from '@/components/devices/catalog-form';
import { CatalogBulkDelete } from '@/components/devices/catalog-bulk-delete';
import { ManufacturerTable } from '@/components/devices/manufacturer-table';
import { DeviceTable } from '@/components/devices/device-table';
import { PanelViewer } from '@/components/devices/panel-viewer';
import { Button } from '@/components/ui/button';
import TextLink from '@/components/text-link';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/device-models';
import {
    store as storeType,
    update as updateType,
    destroy as destroyType,
    bulkDestroy as bulkDestroyTypes,
} from '@/routes/model-types';
import { store as storeManufacturer } from '@/routes/manufacturers';
import {
    store,
    update,
    destroy,
    edit,
    deletionImpact,
} from '@/routes/manufacturers/models';
import { index as devices } from '@/routes/companies/sites/devices';
import { index as companies } from '@/routes/companies';
import type { Manufacturer, DeviceModel, ModelType } from '@/types/device';

type Props = {
    manufacturers: Manufacturer[];
    models: (DeviceModel & { manufacturer: Manufacturer })[];
    tab: 'models' | 'manufacturers' | 'types';
    types: ModelType[];
    manufacturerId: number | null;
};
export default function Models({
    manufacturers,
    models,
    types,
    tab,
    manufacturerId,
}: Props) {
    const manufacturerIds = new Set(
        models.map((model) => model.manufacturer_id),
    );
    const { companyNavigation } = usePage().props;
    const context =
        companyNavigation?.currentCompanyId && companyNavigation.currentSiteId
            ? {
                  company: companyNavigation.currentCompanyId,
                  site: companyNavigation.currentSiteId,
              }
            : null;
    function visit(
        nextTab: string,
        manufacturer: number | null = manufacturerId,
    ) {
        router.get(
            index.url({ query: { tab: nextTab, manufacturer } }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    }
    return (
        <>
            <Head title="Catalogo" />
            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={context ? devices(context) : companies()}>
                            <ArrowLeft data-icon="inline-start" />
                            {context ? 'Dispositivi' : 'Aziende'}
                        </Link>
                    </Button>
                </div>
                <ListPageHeader
                    title="Catalogo"
                    icon={LayoutTemplate}
                    actions={
                        tab === 'types' ? (
                            <CatalogForm
                                key="type"
                                title="Nuovo tipo"
                                action={storeType.form()}
                            />
                        ) : tab === 'manufacturers' ? (
                            <CatalogForm
                                key="manufacturer"
                                title="Nuovo produttore"
                                action={storeManufacturer.form()}
                            />
                        ) : (
                            <CatalogForm
                                key="model"
                                title="Nuovo modello"
                                types={types}
                                manufacturers={manufacturers}
                                manufacturerId={manufacturerId}
                                action={(manufacturer) =>
                                    store.form(manufacturer)
                                }
                            />
                        )
                    }
                />
                <Tabs
                    value={tab}
                    onValueChange={(value) => visit(value)}
                    className="gap-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <TabsList aria-label="Catalogo dispositivi">
                            <TabsTrigger value="models">
                                <LayoutTemplate />
                                Modelli
                            </TabsTrigger>
                            <TabsTrigger value="manufacturers">
                                <Factory />
                                Produttori
                            </TabsTrigger>
                            <TabsTrigger value="types">
                                <Shapes />
                                Tipi
                            </TabsTrigger>
                        </TabsList>
                    </div>
                    <TabsContent
                        value="models"
                        forceMount
                        className="data-[state=inactive]:hidden"
                    >
                        <DeviceTable
                            name="device-models"
                            variant="list"
                            searchLabel="Cerca modelli"
                            total={models.length}
                            filterKey={String(manufacturerId ?? 'all')}
                            filters={
                                manufacturerIds.size > 1 ||
                                manufacturerId !== null ? (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Select
                                            value={String(
                                                manufacturerId ?? 'all',
                                            )}
                                            onValueChange={(value) =>
                                                visit(
                                                    'models',
                                                    value === 'all'
                                                        ? null
                                                        : Number(value),
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-52 max-w-full"
                                                aria-label="Filtra per produttore"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectGroup>
                                                    <SelectItem value="all">
                                                        Tutti i produttori
                                                    </SelectItem>
                                                    {manufacturers
                                                        .filter(
                                                            (m) =>
                                                                manufacturerIds.has(
                                                                    m.id,
                                                                ) ||
                                                                m.id ===
                                                                    manufacturerId,
                                                        )
                                                        .map((m) => (
                                                            <SelectItem
                                                                key={m.id}
                                                                value={String(
                                                                    m.id,
                                                                )}
                                                            >
                                                                {m.name}
                                                            </SelectItem>
                                                        ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                        {manufacturerId !== null ? (
                                            <Button
                                                variant="ghost"
                                                onClick={() =>
                                                    visit('models', null)
                                                }
                                            >
                                                Azzera filtro
                                            </Button>
                                        ) : null}
                                    </div>
                                ) : null
                            }
                            rows={
                                manufacturerId
                                    ? models.filter(
                                          (m) =>
                                              m.manufacturer_id ===
                                              manufacturerId,
                                      )
                                    : models
                            }
                            columns={[
                                { id: 'name', label: 'Modello', visible: true },
                                {
                                    id: 'manufacturer',
                                    label: 'Produttore',
                                    visible: true,
                                },
                                { id: 'type', label: 'Tipo', visible: true },
                                {
                                    id: 'rack_height_u',
                                    label: 'Altezza rack',
                                    visible: true,
                                },
                                {
                                    id: 'panel',
                                    label: 'Pannello',
                                    visible: true,
                                },
                                {
                                    id: 'devices_count',
                                    label: 'Dispositivi',
                                    visible: true,
                                },
                            ]}
                            value={(m, c) =>
                                c === 'rack_height_u'
                                    ? (m.rack_height_u ?? 'Non rackmount')
                                    : c === 'type'
                                      ? (m.model_type?.name ?? '—')
                                      : c === 'manufacturer'
                                        ? m.manufacturer.name
                                        : c === 'panel'
                                          ? m.panel
                                              ? 'Con pannello'
                                              : 'Senza pannello'
                                          : (m[c] ?? 0)
                            }
                            render={(m, c) =>
                                c === 'rack_height_u' ? (
                                    m.rack_height_u ? (
                                        <span className="tabular-nums">
                                            {m.rack_height_u} U
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Non rackmount
                                        </span>
                                    )
                                ) : c === 'type' ? (
                                    m.model_type ? (
                                        <Badge variant="secondary">
                                            {m.model_type.name}
                                        </Badge>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )
                                ) : c === 'manufacturer' ? (
                                    m.manufacturer.name
                                ) : c === 'panel' ? (
                                    m.panel ? (
                                        <div className="w-72 max-w-full">
                                            <PanelViewer
                                                panel={m.panel}
                                                assetUrls={m.assetUrls ?? {}}
                                                compact
                                            />
                                        </div>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Senza pannello
                                        </span>
                                    )
                                ) : c === 'name' ? (
                                    <TextLink
                                        href={edit({
                                            manufacturer: m.manufacturer_id,
                                            model: m.id,
                                        })}
                                    >
                                        {m.name}
                                    </TextLink>
                                ) : (
                                    m.devices_count
                                )
                            }
                            empty={
                                models.length
                                    ? 'Nessun modello trovato'
                                    : 'Nessun modello'
                            }
                            actions={(m) => (
                                <CatalogForm
                                    title="Modifica modello"
                                    types={types}
                                    item={m}
                                    manufacturers={manufacturers}
                                    manufacturerId={m.manufacturer_id}
                                    action={update.form({
                                        manufacturer: m.manufacturer_id,
                                        model: m.id,
                                    })}
                                    deletion={destroy.form({
                                        manufacturer: m.manufacturer_id,
                                        model: m.id,
                                    })}
                                    deletionPreviewUrl={deletionImpact.url({
                                        manufacturer: m.manufacturer_id,
                                        model: m.id,
                                    })}
                                />
                            )}
                        />
                    </TabsContent>
                    <TabsContent
                        value="manufacturers"
                        forceMount
                        className="data-[state=inactive]:hidden"
                    >
                        <ManufacturerTable manufacturers={manufacturers} />
                    </TabsContent>
                    <TabsContent
                        value="types"
                        forceMount
                        className="data-[state=inactive]:hidden"
                    >
                        <DeviceTable
                            name="model-types"
                            variant="list"
                            searchLabel="Cerca tipi"
                            rows={types}
                            columns={[
                                { id: 'name', label: 'Tipo', visible: true },
                                {
                                    id: 'models_count',
                                    label: 'Modelli',
                                    visible: true,
                                },
                            ]}
                            value={(type, column) => type[column] ?? 0}
                            empty="Nessun tipo"
                            bulkActions={(items, clearSelection) => (
                                <CatalogBulkDelete
                                    items={items}
                                    action={bulkDestroyTypes.form()}
                                    onSuccess={clearSelection}
                                />
                            )}
                            actions={(type) => (
                                <CatalogForm
                                    title="Modifica tipo"
                                    item={type}
                                    action={updateType.form(type.id)}
                                    deletion={destroyType.form(type.id)}
                                    deletionMessage={`Verrà eliminato «${type.name}». La rimozione è bloccata se il tipo è associato a modelli.`}
                                />
                            )}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}
Models.layout = () => ({ breadcrumbs: [{ title: 'Catalogo', href: index() }] });
