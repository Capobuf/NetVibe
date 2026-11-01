import type { Device } from './device';

export type RackItem = {
    key: string;
    kind: 'device' | 'shelf' | 'drawer';
    device_id: number | null;
    // Identifies the supporting shelf or device; position is the root mounting unit.
    shelf_key: string | null;
    position: number;
    height: number;
    width: number;
    x: number;
};
export type Rack = {
    id: number;
    name: string;
    floor: string | null;
    height_u: number;
    numbering: 'bottom-up' | 'top-down';
    revision: number;
    items: RackItem[];
    items_count?: number;
    devices_count?: number;
};
export type RackDevice = Device & {
    assetUrls: Record<string, string>;
    rack_item: { rack_id: number; rack: { id: number; name: string } } | null;
};
