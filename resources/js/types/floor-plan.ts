export type FloorPlanSummary = {
    id: number;
    name: string;
    floor: string | null;
};

/** x and y are fractions of the image size (0–1). */
export type FloorPlanItem = {
    device_id: number | null;
    rack_id: number | null;
    x: number;
    y: number;
};

export type FloorPlan = FloorPlanSummary & {
    image_width: number;
    image_height: number;
    image_url: string;
    revision: number;
    items: FloorPlanItem[];
};

export type FloorPlanDevice = {
    id: number;
    name: string;
    model: string | null;
    manufacturer: string | null;
    ips: string[];
    rack: { id: number; name: string } | null;
};

export type FloorPlanRack = {
    id: number;
    name: string;
    floor: string | null;
    height_u: number;
    devices_count: number;
};

export type FloorPlanPlacement = {
    device_id: number | null;
    rack_id: number | null;
    plan: string;
};

export type FloorPlanMarker = {
    key: string;
    kind: 'device' | 'rack';
    label: string;
    x: number;
    y: number;
};

export const itemKey = (item: Pick<FloorPlanItem, 'device_id' | 'rack_id'>) =>
    item.rack_id ? `r-${item.rack_id}` : `d-${item.device_id}`;
