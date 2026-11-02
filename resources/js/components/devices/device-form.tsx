import { SaveButton } from '@/components/save-button';
import { NewItemButton } from '@/components/new-item-button';
import { CopyValue } from '@/components/copy-value';
import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ExternalLink, Settings2, Trash2, TriangleAlert } from 'lucide-react';
import { GuidedDeletion } from '@/components/networks/guided-deletion';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogClose,
} from '@/components/ui/dialog';
import {
    MikrotikDevice,
    type MikrotikConnection,
} from '@/components/devices/mikrotik-device';
import {
    SnipeItDevice,
    snipeItProblem,
    type SnipeDevice,
} from '@/components/devices/snipe-it-device';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { SheetContent } from '@/components/ui/sheet';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import {
    deletionImpact,
    destroy,
    store,
    update,
} from '@/routes/companies/sites/devices';
import { store as storeModel } from '@/routes/manufacturers/models';
import { CatalogCombobox } from '@/components/devices/catalog-combobox';
import { CatalogForm } from '@/components/devices/catalog-form';
import type { Manufacturer, Device, ModelType } from '@/types/device';

export function DeviceForm({
    company,
    site,
    manufacturers,
    device,
    snipeIt,
    isMikrotik = false,
    mikrotik = null,
    initialManagementTab,
}: {
    company: number;
    site: number;
    manufacturers: Manufacturer[];
    device?: Device;
    snipeIt?: SnipeDevice | null;
    isMikrotik?: boolean;
    mikrotik?: MikrotikConnection | null;
    initialManagementTab?: string | null;
}) {
    const { auth, types } = usePage<{ types: ModelType[] }>().props;
    const [open, setOpen] = useState(!!initialManagementTab);
    const [selectedTab, setSelectedTab] = useState(
        initialManagementTab ?? 'general',
    );
    const tab =
        (selectedTab === 'snipe-it' && !snipeIt) ||
        (selectedTab === 'mikrotik' && !isMikrotik)
            ? 'general'
            : selectedTab;
    const [deleting, setDeleting] = useState(false);
    const [modelDraft, setModelDraft] = useState<{ name: string } | null>(null);
    const [model, setModel] = useState(String(device?.device_model_id ?? ''));
    const linked = !!snipeIt;
    const integrationProblem =
        (snipeIt && snipeItProblem(snipeIt)) ||
        (mikrotik?.sync_status && mikrotik.sync_status !== 'ok') ||
        (mikrotik?.check_status && mikrotik.check_status !== 'ok') ||
        (mikrotik?.sync_summary?.problems ?? 0) > 0;
    const Content = device ? SheetContent : DialogContent;
    const models = manufacturers.flatMap((manufacturer) =>
        (manufacturer.models ?? []).map((model) => ({
            ...model,
            name: `${manufacturer.name} · ${model.name}`,
        })),
    );
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) {
                    setDeleting(false);
                    setModel(String(device?.device_model_id ?? ''));
                }
            }}
        >
            <DialogTrigger asChild>
                {device ? (
                    <Button variant="outline">
                        <Settings2 data-icon="inline-start" />
                        Gestisci
                        {integrationProblem ? (
                            <>
                                <TriangleAlert
                                    className="text-destructive"
                                    data-icon="inline-end"
                                />
                                <span className="sr-only">
                                    : integrazioni da verificare
                                </span>
                            </>
                        ) : null}
                    </Button>
                ) : (
                    <NewItemButton aria-label="Nuovo: crea dispositivo" />
                )}
            </DialogTrigger>
            <Content
                className={cn(
                    'flex flex-col gap-0 overflow-hidden p-0',
                    device
                        ? tab === 'general' || deleting
                            ? 'w-full sm:max-w-2xl'
                            : 'w-full sm:max-w-5xl'
                        : 'max-h-[90dvh] sm:max-w-xl',
                )}
                aria-describedby={undefined}
            >
                <DialogHeader className="shrink-0 border-b p-6 pr-12">
                    <DialogTitle>
                        {device
                            ? `${deleting ? 'Elimina' : 'Gestisci'} ${device.name}`
                            : 'Nuovo dispositivo'}
                    </DialogTitle>
                </DialogHeader>
                <Tabs
                    value={tab}
                    onValueChange={setSelectedTab}
                    className={cn(
                        'min-h-0 min-w-0 flex-1 gap-0',
                        deleting && 'hidden',
                    )}
                >
                    {device && (isMikrotik || snipeIt) ? (
                        <div className="shrink-0 overflow-x-auto border-b px-6 py-4">
                            <TabsList aria-label="Gestione dispositivo">
                                <TabsTrigger value="general">
                                    Anagrafica
                                </TabsTrigger>
                                {isMikrotik ? (
                                    <TabsTrigger value="mikrotik">
                                        <MikrotikMark />
                                        MikroTik
                                    </TabsTrigger>
                                ) : null}
                                {snipeIt ? (
                                    <TabsTrigger value="snipe-it">
                                        <SnipeItMark className="size-5" />
                                        Snipe-IT
                                    </TabsTrigger>
                                ) : null}
                            </TabsList>
                        </div>
                    ) : null}
                    <TabsContent
                        value="general"
                        aria-label="Anagrafica dispositivo"
                        forceMount
                        className="flex min-h-0 flex-col data-[state=inactive]:hidden"
                    >
                        <Form
                            className="flex min-h-0 flex-1 flex-col"
                            {...(device
                                ? update.form({
                                      company,
                                      site,
                                      device: device.id,
                                  })
                                : store.form({ company, site }))}
                            onBefore={() => !linked && !modelDraft}
                            onSuccess={() => setOpen(false)}
                            options={{ preserveScroll: true }}
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="min-h-0 flex-1 overflow-y-auto p-6">
                                        {linked && device ? (
                                            <div className="flex flex-col gap-6">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    className="self-start"
                                                >
                                                    <a
                                                        href={snipeIt?.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        aria-label="Apri in Snipe-IT (nuova scheda)"
                                                    >
                                                        Apri in Snipe-IT
                                                        <ExternalLink data-icon="inline-end" />
                                                    </a>
                                                </Button>
                                                <dl className="grid gap-5 sm:grid-cols-2">
                                                    {[
                                                        ['Nome', device.name],
                                                        [
                                                            'Modello',
                                                            device.model?.name,
                                                        ],
                                                        [
                                                            'Seriale',
                                                            device.serial_number,
                                                        ],
                                                        [
                                                            'Asset tag',
                                                            device.asset_tag,
                                                        ],
                                                    ].map(([label, value]) => (
                                                        <div
                                                            key={label}
                                                            className="flex min-w-0 flex-col gap-1"
                                                        >
                                                            <dt className="text-sm text-muted-foreground">
                                                                {label}
                                                            </dt>
                                                            <dd className="font-medium break-words">
                                                                {value ? (
                                                                    <CopyValue
                                                                        value={value}
                                                                        className="-mx-2 -my-1.5 font-medium"
                                                                    />
                                                                ) : (
                                                                    '—'
                                                                )}
                                                            </dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                            </div>
                                        ) : (
                                            <FieldGroup>
                                                <Field
                                                    data-invalid={!!errors.name}
                                                >
                                                    <FieldLabel htmlFor="device-name">
                                                        Nome *
                                                    </FieldLabel>
                                                    <Input
                                                        id="device-name"
                                                        name="name"
                                                        defaultValue={
                                                            device?.name
                                                        }
                                                        maxLength={255}
                                                        autoFocus={
                                                            tab === 'general'
                                                        }
                                                        required
                                                        disabled={processing}
                                                        aria-invalid={
                                                            !!errors.name
                                                        }
                                                    />
                                                    <InputError
                                                        message={errors.name}
                                                    />
                                                </Field>
                                                <Field
                                                    data-invalid={
                                                        !!errors.device_model_id
                                                    }
                                                >
                                                    <FieldLabel htmlFor="device-model">
                                                        Modello *
                                                    </FieldLabel>
                                                    <CatalogCombobox
                                                        id="device-model"
                                                        name="device_model_id"
                                                        items={models}
                                                        required
                                                        value={model}
                                                        onChange={setModel}
                                                        placeholder={
                                                            auth.user
                                                                .is_superadmin
                                                                ? 'Cerca o aggiungi modello…'
                                                                : 'Cerca modello…'
                                                        }
                                                        onCreate={
                                                            auth.user
                                                                .is_superadmin
                                                                ? (name) => {
                                                                      setModelDraft(
                                                                          {
                                                                              name,
                                                                          },
                                                                      );
                                                                  }
                                                                : undefined
                                                        }
                                                        disabled={processing}
                                                        error={
                                                            errors.device_model_id
                                                        }
                                                    />
                                                </Field>
                                                <Field
                                                    data-invalid={
                                                        !!errors.serial_number
                                                    }
                                                >
                                                    <FieldLabel htmlFor="device-serial">
                                                        Seriale
                                                    </FieldLabel>
                                                    <Input
                                                        id="device-serial"
                                                        name="serial_number"
                                                        defaultValue={
                                                            device?.serial_number ??
                                                            ''
                                                        }
                                                        maxLength={255}
                                                        disabled={processing}
                                                        aria-invalid={
                                                            !!errors.serial_number
                                                        }
                                                        aria-describedby="device-serial-error"
                                                    />
                                                    <InputError
                                                        id="device-serial-error"
                                                        message={
                                                            errors.serial_number
                                                        }
                                                    />
                                                </Field>
                                                <Field
                                                    data-invalid={
                                                        !!errors.asset_tag
                                                    }
                                                >
                                                    <FieldLabel htmlFor="device-asset-tag">
                                                        Asset tag
                                                    </FieldLabel>
                                                    <Input
                                                        id="device-asset-tag"
                                                        name="asset_tag"
                                                        defaultValue={
                                                            device?.asset_tag ??
                                                            ''
                                                        }
                                                        maxLength={255}
                                                        disabled={processing}
                                                        aria-invalid={
                                                            !!errors.asset_tag
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.asset_tag
                                                        }
                                                    />
                                                </Field>
                                            </FieldGroup>
                                        )}
                                    </div>
                                    <DialogFooter className="shrink-0 border-t p-6 sm:flex-wrap">
                                        {device ? (
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                id="device-delete"
                                                className="sm:mr-auto"
                                                disabled={processing}
                                                onClick={() =>
                                                    setDeleting(true)
                                                }
                                            >
                                                <Trash2 data-icon="inline-start" />
                                                Elimina dispositivo
                                            </Button>
                                        ) : null}
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                {linked ? 'Chiudi' : 'Annulla'}
                                            </Button>
                                        </DialogClose>
                                        {!linked ? (
                                            <SaveButton
                                                processing={processing}
                                                disabled={!!modelDraft}
                                            />
                                        ) : null}
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </TabsContent>
                    {device && isMikrotik ? (
                        <TabsContent
                            value="mikrotik"
                            forceMount
                            className="min-h-0 overflow-y-auto p-6 data-[state=inactive]:hidden"
                        >
                            <MikrotikDevice
                                company={company}
                                site={site}
                                device={device.id}
                                connection={mikrotik}
                            />
                        </TabsContent>
                    ) : null}
                    {device && snipeIt ? (
                        <TabsContent
                            value="snipe-it"
                            className="min-h-0 overflow-y-auto p-6"
                        >
                            <SnipeItDevice
                                company={company}
                                source={snipeIt}
                                device={device}
                            />
                        </TabsContent>
                    ) : null}
                </Tabs>
                {deleting && device ? (
                    <div className="min-h-0 flex-1 overflow-y-auto p-6">
                        <GuidedDeletion
                            kind="device"
                            previewUrl={deletionImpact.url({
                                company,
                                site,
                                device: device.id,
                            })}
                            action={destroy.form({
                                company,
                                site,
                                device: device.id,
                            })}
                            onCancel={() => {
                                setDeleting(false);
                                requestAnimationFrame(() =>
                                    document
                                        .getElementById(
                                            linked
                                                ? 'device-delete'
                                                : 'device-name',
                                        )
                                        ?.focus(),
                                );
                            }}
                            onSuccess={() => setOpen(false)}
                        />
                    </div>
                ) : null}
                {modelDraft && (
                    <CatalogForm
                        title="Nuovo modello"
                        open
                        onOpenChange={(value) => {
                            if (!value) setModelDraft(null);
                        }}
                        initialName={modelDraft.name}
                        manufacturers={manufacturers}
                        manufacturerId={
                            models.find((item) => String(item.id) === model)
                                ?.manufacturer_id
                        }
                        types={types ?? []}
                        action={(producer) => storeModel.form(producer)}
                        onCreated={(created) => {
                            setModel(String(created.id));
                        }}
                    />
                )}
            </Content>
        </Dialog>
    );
}
