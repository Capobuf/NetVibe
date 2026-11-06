import { Link } from '@inertiajs/react';
import { Monitor, PanelsTopLeft } from 'lucide-react';
import { CatalogForm } from '@/components/devices/catalog-form';
import { PanelViewer } from '@/components/devices/panel-viewer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Item,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { show } from '@/routes/companies/sites/devices';
import {
    deletionImpact,
    destroy,
    edit,
    update,
} from '@/routes/manufacturers/models';
import type { DeviceModel, Manufacturer, ModelType } from '@/types/device';

export type ModelDevice = {
    id: number;
    name: string;
    site: { id: number; name: string };
    company: { id: number; name: string };
};

export function ModelSheet({
    model,
    devices,
    canManage,
    manufacturers,
    types,
    onClose,
}: {
    model: (DeviceModel & { manufacturer: Manufacturer }) | null;
    devices: ModelDevice[] | null;
    canManage: boolean;
    manufacturers: Manufacturer[];
    types: ModelType[];
    onClose: () => void;
}) {
    const route = model
        ? { manufacturer: model.manufacturer_id, model: model.id }
        : null;
    return (
        <Sheet open={!!model} onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                overlayClassName="bg-black/30"
                className="w-full gap-0 overflow-y-auto sm:max-w-2xl"
            >
                {model && route ? (
                    <>
                        <SheetHeader className="gap-2 border-b px-6 py-5 pr-12">
                            <SheetDescription>
                                {model.manufacturer.name}
                            </SheetDescription>
                            <SheetTitle className="text-xl font-semibold break-words">
                                {model.name}
                            </SheetTitle>
                            <div className="flex flex-wrap items-center gap-2">
                                {model.model_type ? (
                                    <Badge variant="secondary">
                                        {model.model_type.name}
                                    </Badge>
                                ) : null}
                                <Badge variant="outline">
                                    {model.rack_height_u
                                        ? `${model.rack_height_u} U`
                                        : 'Non rackmount'}
                                </Badge>
                            </div>
                        </SheetHeader>
                        <div className="flex flex-col gap-6 px-6 py-5">
                            {model.panel ? (
                                <PanelViewer
                                    key={model.id}
                                    panel={model.panel}
                                    assetUrls={model.assetUrls ?? {}}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Senza pannello
                                </p>
                            )}
                            <section
                                aria-labelledby="model-devices"
                                className="flex flex-col gap-3"
                            >
                                <h3
                                    id="model-devices"
                                    className="text-sm font-medium"
                                >
                                    Dispositivi
                                    {devices ? (
                                        <span className="ml-2 text-muted-foreground tabular-nums">
                                            {devices.length}
                                        </span>
                                    ) : null}
                                </h3>
                                {devices === null ? (
                                    <div className="flex flex-col gap-2">
                                        <Skeleton className="h-12" />
                                        <Skeleton className="h-12" />
                                    </div>
                                ) : devices.length ? (
                                    <ItemGroup className="gap-1">
                                        {devices.map((device) => (
                                            <Item
                                                key={device.id}
                                                size="sm"
                                                variant="outline"
                                                asChild
                                            >
                                                <Link
                                                    href={show({
                                                        company:
                                                            device.company.id,
                                                        site: device.site.id,
                                                        device: device.id,
                                                    })}
                                                >
                                                    <ItemMedia variant="icon">
                                                        <Monitor />
                                                    </ItemMedia>
                                                    <ItemContent>
                                                        <ItemTitle>
                                                            {device.name}
                                                        </ItemTitle>
                                                        <ItemDescription>
                                                            {[
                                                                device.company
                                                                    .name,
                                                                device.site
                                                                    .name,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </ItemDescription>
                                                    </ItemContent>
                                                </Link>
                                            </Item>
                                        ))}
                                    </ItemGroup>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Nessun dispositivo
                                    </p>
                                )}
                            </section>
                        </div>
                        {canManage ? (
                            <SheetFooter className="flex-row flex-wrap justify-end gap-2 border-t px-6 py-4">
                                <CatalogForm
                                    title="Modifica modello"
                                    types={types}
                                    item={model}
                                    manufacturers={manufacturers}
                                    manufacturerId={model.manufacturer_id}
                                    action={update.form(route)}
                                    deletion={destroy.form(route)}
                                    deletionPreviewUrl={deletionImpact.url(
                                        route,
                                    )}
                                />
                                <Button asChild>
                                    <Link href={edit(route)}>
                                        <PanelsTopLeft data-icon="inline-start" />
                                        {model.panel
                                            ? 'Modifica pannello'
                                            : 'Crea pannello'}
                                    </Link>
                                </Button>
                            </SheetFooter>
                        ) : null}
                    </>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
