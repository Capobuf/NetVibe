import { DeviceTable } from '@/components/devices/device-table';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDateTime } from '@/lib/utils';
import type { UnifiSite } from './types';

const deviceStates: Record<string, string> = {
    ONLINE: 'Online',
    OFFLINE: 'Offline',
    PENDING_ADOPTION: 'In attesa di adozione',
    UPDATING: 'Aggiornamento in corso',
    GETTING_READY: 'Preparazione in corso',
    ADOPTING: 'Adozione in corso',
    DELETING: 'Rimozione in corso',
    CONNECTION_INTERRUPTED: 'Connessione interrotta',
    ISOLATED: 'Isolato',
    U5G_INCORRECT_TOPOLOGY: 'Topologia U5G non corretta',
};

export function UnifiSitePreview({
    source,
    current,
    onClose,
}: {
    source: UnifiSite;
    current: boolean;
    onClose: () => void;
}) {
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-4xl">
                <SheetHeader>
                    <SheetTitle>{source.name}</SheetTitle>
                    <SheetDescription>
                        {source.console_name}
                        {source.version ? ` · Network ${source.version}` : ''}
                    </SheetDescription>
                </SheetHeader>
                <div className="flex min-w-0 flex-col gap-5 px-4 pb-6">
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <Badge variant="outline">Anteprima</Badge>
                        {source.discovered_at ? (
                            <span>
                                Acquisita il{' '}
                                {formatDateTime(source.discovered_at)}
                            </span>
                        ) : null}
                    </div>
                    {!current || !source.available ? (
                        <Alert>
                            <AlertDescription>
                                Ultima lettura disponibile · dati non
                                aggiornati.
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    {source.preview ? (
                        <Tabs defaultValue="devices" className="min-w-0 gap-5">
                            <TabsList aria-label="Anteprima sito UniFi">
                                <TabsTrigger value="devices">
                                    Dispositivi{' '}
                                    <span className="tabular-nums">
                                        {source.preview.devices.length}
                                    </span>
                                </TabsTrigger>
                                <TabsTrigger value="networks">
                                    Reti
                                    {source.preview.networks ? (
                                        <span className="tabular-nums">
                                            {source.preview.networks.length}
                                        </span>
                                    ) : null}
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent
                                value="devices"
                                forceMount
                                className="min-w-0 data-[state=inactive]:hidden"
                            >
                                <DeviceTable
                                    name="unifi-preview-devices"
                                    variant="list"
                                    rows={source.preview.devices}
                                    columns={[
                                        {
                                            id: 'name',
                                            label: 'Dispositivo',
                                            visible: true,
                                        },
                                        {
                                            id: 'model',
                                            label: 'Modello',
                                            visible: true,
                                        },
                                        {
                                            id: 'ip_address',
                                            label: 'Indirizzo IP',
                                            visible: true,
                                        },
                                        {
                                            id: 'mac_address',
                                            label: 'MAC',
                                            visible: true,
                                        },
                                        {
                                            id: 'state',
                                            label: 'Stato',
                                            visible: true,
                                        },
                                    ]}
                                    value={(device, column) =>
                                        column === 'state'
                                            ? (deviceStates[
                                                  device.state ?? ''
                                              ] ?? 'Stato non disponibile')
                                            : device[column]
                                    }
                                    empty="Nessun dispositivo acquisito"
                                    searchLabel="Cerca dispositivi"
                                />
                            </TabsContent>
                            <TabsContent
                                value="networks"
                                forceMount
                                className="min-w-0 data-[state=inactive]:hidden"
                            >
                                {source.preview.networks_status ===
                                'unsupported' ? (
                                    <Alert>
                                        <AlertDescription>
                                            Reti non supportate dalla versione
                                            API.
                                        </AlertDescription>
                                    </Alert>
                                ) : (
                                    <DeviceTable
                                        name="unifi-preview-networks"
                                        variant="list"
                                        rows={source.preview.networks ?? []}
                                        columns={[
                                            {
                                                id: 'name',
                                                label: 'Rete',
                                                visible: true,
                                            },
                                            {
                                                id: 'vlan_id',
                                                label: 'VLAN',
                                                visible: true,
                                            },
                                            {
                                                id: 'subnet',
                                                label: 'Subnet',
                                                visible: true,
                                            },
                                            {
                                                id: 'ip_address',
                                                label: 'IP configurato',
                                                visible: true,
                                            },
                                        ]}
                                        value={(network, column) =>
                                            network[column]
                                        }
                                        empty="Nessuna rete acquisita"
                                        searchLabel="Cerca reti"
                                    />
                                )}
                            </TabsContent>
                        </Tabs>
                    ) : (
                        <Alert>
                            <AlertDescription>
                                Dati non ancora acquisiti.
                            </AlertDescription>
                        </Alert>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
