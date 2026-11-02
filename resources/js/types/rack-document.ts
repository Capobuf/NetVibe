import type { Cable } from '@/lib/rack-cabling';
import type { Company, Site } from './company';
import type { DeviceModel } from './device';
import type { Rack, RackItem } from './rack';

export type DocumentRack = Pick<
    Rack,
    'id' | 'name' | 'height_u' | 'numbering' | 'items'
>;
export type DocumentDevice = {
    id: number;
    name: string;
    manufacturer: string | null;
    serial_number: string | null;
    asset_tag: string | null;
    model: DeviceModel | null;
    assetUrls: Record<string, string>;
    rack: Omit<DocumentRack, 'items'> | null;
    placement: RackItem | null;
    supports: RackItem[];
};
export type DocumentCableEnd = {
    device_id: number | null;
    device: string | null;
    port: string | null;
};
export type DocumentCable = Pick<
    Cable,
    'id' | 'name' | 'type' | 'color' | 'purpose'
> & {
    a?: DocumentCableEnd;
    b?: DocumentCableEnd;
};
export type RackDocument = {
    generatedAt: string;
    racks: DocumentRack[];
    devices: DocumentDevice[];
    cables: DocumentCable[];
};
export type RackDocumentProps = {
    company: Company;
    site: Site;
    rack: Pick<Rack, 'id' | 'name'> | null;
    document: RackDocument;
};
