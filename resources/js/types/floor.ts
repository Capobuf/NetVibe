export type FloorSummary = {
    id: number;
    code: string;
};

/** A floor as listed in the switcher, with what an upload needs to know. */
export type FloorListItem = FloorSummary & {
    items_count: number;
    image: { width: number; height: number } | null;
};

/** x and y are fractions of the floor plan image size (0–1). */
export type FloorItem = {
    device_id: number | null;
    rack_id: number | null;
    x: number;
    y: number;
};

export type FloorImage = { url: string; width: number; height: number };

export type Floor = FloorSummary & {
    revision: number;
    image: FloorImage | null;
    items: FloorItem[];
};

export type FloorDevice = {
    id: number;
    name: string;
    model: string | null;
    manufacturer: string | null;
    ips: string[];
    rack: { id: number; name: string } | null;
};

export type FloorRack = {
    id: number;
    name: string;
    floor: string | null;
    height_u: number;
    devices_count: number;
};

export type FloorPlacement = {
    device_id: number | null;
    rack_id: number | null;
    floor: string;
};

export type FloorMarker = {
    key: string;
    kind: 'device' | 'rack';
    label: string;
    x: number;
    y: number;
};

export const itemKey = (item: Pick<FloorItem, 'device_id' | 'rack_id'>) =>
    item.rack_id ? `r-${item.rack_id}` : `d-${item.device_id}`;
