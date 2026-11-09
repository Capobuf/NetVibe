import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Factory, LayoutTemplate, Shapes } from 'lucide-react';
import { ListPageHeader } from '@/components/list-page-header';
import { Badge } from '@/components/ui/badge';
import { CatalogForm } from '@/components/devices/catalog-form';
import { CatalogBulkDelete } from '@/components/devices/catalog-bulk-delete';
import { ManufacturerTable } from '@/components/devices/manufacturer-table';
import { DataTable } from '@/components/data-table';
import { ModelSheet, type ModelDevice } from '@/components/devices/model-sheet';
import { PanelViewer } from '@/components/devices/panel-viewer';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index } from '@/routes/device-models';
import {
    store as storeType,
    update as updateType,
    destroy as destroyType,
    bulkDestroy as bulkDestroyTypes,
} from '@/routes/model-types';
import { store as storeManufacturer } from '@/routes/manufacturers';
import { store } from '@/routes/manufacturers/models';
import type { Manufacturer, DeviceModel, ModelType } from '@/types/device';

type CatalogModel = DeviceModel & { manufacturer: Manufacturer };
type Props = {
    manufacturers: Manufacturer[];
    models: CatalogModel[];
    tab: 'models' | 'manufacturers' | 'types';
    types: ModelType[];
    manufacturerId: number | null;
    modelId: number | null;
    modelDevices: ModelDevice[];
};
const ALL = 'all';
const NONE = 'none';

export default function Models({
    manufacturers,
    models,
    types,
    tab,
    manufacturerId,
    modelId,
    modelDevices,
}: Props) {
    const { auth } = usePage().props;
    const canManage = auth.user.is_superadmin;
    // Opens immediately; the device list arrives with the partial reload.
    const [openId, setOpenId] = useState(modelId);
    const [typeFilter, setTypeFilter] = useState(ALL);
    const [panelFilter, setPanelFilter] = useState(ALL);
    const manufacturerIds = new Set(models.map((m) => m.manufacturer_id));
    const usedTypes = types.filter((type) =>
        models.some((m) => m.model_type_id === type.id),
    );
    const untyped = models.some((m) => m.model_type_id === null);
    const filtered = models.filter(
        (m) =>
            (manufacturerId === null || m.manufacturer_id === manufacturerId) &&
            (typeFilter === ALL ||
                (typeFilter === NONE
                    ? m.model_type_id === null
                    : m.model_type_id === Number(typeFilter))) &&
            (panelFilter === ALL || (panelFilter === 'with') === !!m.panel),
    );
    const filtering =
        manufacturerId !== null || typeFilter !== ALL || panelFilter !== ALL;

    function visit(
        query: {
            tab?: Props['tab'];
            manufacturer?: number | null;
            model?: number | null;
        },
        only?: string[],
    ) {
        const next = {
            tab: tab === 'models' ? null : tab,
            manufacturer: manufacturerId,
            model: openId,
            ...query,
        };
        router.get(
            index.url({
                query: {
                    tab: next.tab === 'models' ? null : next.tab,
                    manufacturer: next.manufacturer,
                    model: next.model,
                },
            }),
            {},
            { preserveState: true, preserveScroll: true, only },
        );
    }
    function openModel(id: number | null) {
        setOpenId(id);
        visit(
            { model: id },
            id === null ? ['modelId'] : ['modelId', 'modelDevices'],
        );
    }
    function clearFilters() {
        setTypeFilter(ALL);
        setPanelFilter(ALL);
        if (manufacturerId !== null) visit({ manufacturer: null });
    }

    return (
        <>
            <Head title="Catalogo" />
            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <ListPageHeader
                    title="Catalogo"
                    icon={LayoutTemplate}
                    actions={
                        canManage ? (
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
                        ) : null
                    }
                />
                <DataTable
                    name="device-models"
                    variant="list"
                    searchLabel="Cerca modelli"
                    total={models.length}
                    filterKey={[manufacturerId, typeFilter, panelFilter].join()}
                    onRowClick={(m) => openModel(m.id)}
                    filters={
                        <>
                            {canManage ? (
                                <Tabs
                                    value={tab}
                                    onValueChange={(value) =>
                                        visit({ tab: value as Props['tab'] })
                                    }
                                >
                                    <TabsList aria-label="Sezione del catalogo">
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
                                </Tabs>
                            ) : null}
                            {models.length ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    {manufacturerIds.size > 1 ||
                                    manufacturerId !== null ? (
                                        <Select
                                            value={String(manufacturerId ?? ALL)}
                                            onValueChange={(value) =>
                                                visit({
                                                    manufacturer:
                                                        value === ALL
                                                            ? null
                                                            : Number(value),
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-48 max-w-full"
                                                aria-label="Filtra per produttore"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectGroup>
                                                    <SelectItem value={ALL}>
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
                                                                value={String(m.id)}
                                                            >
                                                                {m.name}
                                                            </SelectItem>
                                                        ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    ) : null}
                                    {usedTypes.length > 1 ||
                                    (usedTypes.length && untyped) ? (
                                        <Select
                                            value={typeFilter}
                                            onValueChange={setTypeFilter}
                                        >
                                            <SelectTrigger
                                                className="w-44 max-w-full"
                                                aria-label="Filtra per tipo"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectGroup>
                                                    <SelectItem value={ALL}>
                                                        Tutti i tipi
                                                    </SelectItem>
                                                    {usedTypes.map((type) => (
                                                        <SelectItem
                                                            key={type.id}
                                                            value={String(type.id)}
                                                        >
                                                            {type.name}
                                                        </SelectItem>
                                                    ))}
                                                    {untyped ? (
                                                        <SelectItem value={NONE}>
                                                            Senza tipo
                                                        </SelectItem>
                                                    ) : null}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    ) : null}
                                    <Select
                                        value={panelFilter}
                                        onValueChange={setPanelFilter}
                                    >
                                        <SelectTrigger
                                            className="w-44 max-w-full"
                                            aria-label="Filtra per pannello"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                <SelectItem value={ALL}>
                                                    Tutti i pannelli
                                                </SelectItem>
                                                <SelectItem value="with">
                                                    Con pannello
                                                </SelectItem>
                                                <SelectItem value="without">
                                                    Senza pannello
                                                </SelectItem>
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    {filtering ? (
                                        <Button
                                            variant="ghost"
                                            onClick={clearFilters}
                                        >
                                            Azzera filtri
                                        </Button>
                                    ) : null}
                                </div>
                            ) : null}
                        </>
                    }
                    rows={filtered}
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
                        { id: 'panel', label: 'Pannello', visible: true },
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
                                <span className="text-muted-foreground">—</span>
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
                            <span className="font-medium">{m.name}</span>
                        ) : (
                            <span className="tabular-nums">
                                {m.devices_count}
                            </span>
                        )
                    }
                    empty={
                        models.length
                            ? 'Nessun modello trovato'
                            : 'Nessun modello'
                    }
                />
            </div>
            <ModelSheet
                model={models.find((m) => m.id === openId) ?? null}
                devices={modelId === openId ? modelDevices : null}
                canManage={canManage}
                manufacturers={manufacturers}
                types={types}
                onClose={() => openModel(null)}
            />
            {canManage ? (
                <Sheet
                    open={tab !== 'models'}
                    onOpenChange={(open) => !open && visit({ tab: 'models' })}
                >
                    <SheetContent
                        overlayClassName="bg-black/30"
                        className="w-full gap-0 overflow-y-auto sm:max-w-2xl"
                    >
                        <SheetHeader className="flex-row items-center justify-between gap-4 border-b px-6 py-4 pr-12">
                            <SheetTitle>
                                {tab === 'types' ? 'Tipi' : 'Produttori'}
                            </SheetTitle>
                            {tab === 'types' ? (
                                <CatalogForm
                                    key="type"
                                    kind="type"
                                    title="Nuovo tipo"
                                    action={storeType.form()}
                                />
                            ) : (
                                <CatalogForm
                                    key="manufacturer"
                                    title="Nuovo produttore"
                                    action={storeManufacturer.form()}
                                />
                            )}
                        </SheetHeader>
                        <div className="px-6 py-5">
                            {tab === 'types' ? (
                                <TypeTable types={types} />
                            ) : (
                                <ManufacturerTable
                                    manufacturers={manufacturers}
                                />
                            )}
                        </div>
                    </SheetContent>
                </Sheet>
            ) : null}
        </>
    );
}

function TypeTable({ types }: { types: ModelType[] }) {
    return (
        <DataTable
            name="model-types"
            variant="list"
            searchLabel="Cerca tipi"
            rows={types}
            columns={[
                { id: 'name', label: 'Tipo', visible: true },
                {
                    id: 'is_infrastructure',
                    label: 'Classificazione predefinita',
                    visible: true,
                },
                { id: 'models_count', label: 'Modelli', visible: true },
            ]}
            value={(type, column) =>
                column === 'is_infrastructure'
                    ? type.is_infrastructure
                        ? 'Infrastruttura'
                        : 'Altri dispositivi'
                    : (type[column] ?? 0)
            }
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
                    kind="type"
                    item={type}
                    action={updateType.form(type.id)}
                    deletion={destroyType.form(type.id)}
                    deletionMessage={`Verrà eliminato «${type.name}». La rimozione è bloccata se il tipo è associato a modelli.`}
                />
            )}
        />
    );
}

Models.layout = () => ({ breadcrumbs: [{ title: 'Catalogo', href: index() }] });
