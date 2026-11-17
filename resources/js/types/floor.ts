import type { TopologyEdge, TopologyObservation } from '@/types/topology';

export type FloorSummary = {
    id: number;
    code: string;
};

/** A floor as listed in the switcher, with what an upload needs to know. */
export type FloorListItem = FloorSummary & {
    items_count: number;
    image: { width: number; height: number } | null;
};

/** x and y are fractions of the floor plan image size (0–1); width and height are a footprint in metres. */
export type FloorItem = {
    device_id: number | null;
    rack_id: number | null;
    x: number;
    y: number;
    hidden: boolean;
    locked: boolean;
    width: number | null;
    height: number | null;
};

/** Visible part of the plan, as fractions of the uploaded image. */
export type FloorCrop = { x: number; y: number; width: number; height: number };

export type FloorImage = { url: string; width: number; height: number };

export type Floor = FloorSummary & {
    revision: number;
    /** Image pixels per metre, when a known distance has been measured. */
    scale: number | null;
    crop: FloorCrop | null;
    image: FloorImage | null;
    items: FloorItem[];
};

export type FloorDevice = {
    id: number;
    name: string;
    model_id: number | null;
    /** Model type, which groups the layers. */
    type: string | null;
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
    locked: boolean;
    /** Footprint in image pixels, when the item has one and the floor a scale. */
    footprint: { width: number; height: number } | null;
};

/** A catalog model a new element can be made of. */
export type FloorModel = {
    id: number;
    name: string;
    manufacturer: { id: number; name: string };
    model_type: { id: number; name: string } | null;
};

/** The site's documented cables and observations, as the topology computes them, hidden models included. */
export type FloorLinks = {
    edges: TopologyEdge[];
    /** Labels of neighbors that integrations report but the inventory does not hold. */
    neighbors: Record<string, string>;
    /** MACs seen beyond the ports of each device. */
    observed: Record<number, TopologyObservation[]>;
};

export const itemKey = (item: Pick<FloorItem, 'device_id' | 'rack_id'>) =>
    item.rack_id ? `r-${item.rack_id}` : `d-${item.device_id}`;
