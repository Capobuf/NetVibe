import type { PanelDocument, Port } from '@/lib/hardware-editor/document';
export type Manufacturer = {
    id: number;
    name: string;
    models?: DeviceModel[];
    models_count?: number;
    devices_count?: number;
};
export type ModelType = {
    id: number;
    name: string;
    models_count?: number;
};
export type DeviceModel = {
    id: number;
    manufacturer_id: number;
    name: string;
    model_type_id: number | null;
    rack_height_u: number | null;
    without_rack_ears: boolean;
    model_type?: ModelType | null;
    panel: PanelDocument | null;
    panel_revision: number;
    assetUrls?: Record<string, string>;
    devices_count?: number;
};
export type CreatedModel = Pick<DeviceModel, 'id' | 'name' | 'manufacturer_id'>;
export type MikrotikInterface = {
    id: number;
    device_interface_id: number | null;
    name: string;
    default_name: string | null;
    mac_address: string | null;
    seen_at: string;
    stale: boolean;
    problem: string | null;
};
export type DeviceInterface = {
    id: number;
    connector_id: string | null;
    name: string | null;
    kind: 'physical' | 'logical';
    mac_address: string | null;
    mikrotik?: MikrotikInterface | null;
};
export type Device = {
    id: number;
    name: string;
    serial_number: string | null;
    asset_tag?: string | null;
    notes?: string | null;
    manufacturer_id: number;
    device_model_id: number | null;
    manufacturer: Manufacturer;
    model: DeviceModel | null;
    interfaces: DeviceInterface[];
    ips?: string[];
};
export function interfaceName(item: DeviceInterface, ports: Port[] = []) {
    return (
        item.name ||
        (!item.mikrotik?.problem && item.mikrotik?.name) ||
        ports.find((p) => p.id === item.connector_id)?.label ||
        (item.connector_id
            ? `Porta ${item.connector_id.slice(0, 8)}`
            : 'Interfaccia')
    );
}

export function interfaceMac(item: DeviceInterface) {
    return (
        item.mac_address ||
        (!item.mikrotik?.problem && item.mikrotik?.mac_address) ||
        null
    );
}
