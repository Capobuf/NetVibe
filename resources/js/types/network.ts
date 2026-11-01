import type { DeviceInterface } from './device';

export type Vlan = {
    id: number;
    tag: number;
    name: string | null;
};

export type Subnet = {
    id: number;
    cidr: string;
    name: string | null;
    description: string | null;
    vlan_id: number | null;
};

export type IpAddress = {
    mikrotik_edit_mode?: 'planned' | 'manual' | null;
    mikrotik_matched_at?: string | null;
    id: number;
    ip: string;
    description: string | null;
    hostname: string | null;
    mac_address: string | null;
    assignment_label?: string | null;
    device_id?: number | null;
    device_interface_id?: number | null;
    device?: { id: number; name: string } | null;
    device_interface?: DeviceInterface | null;
    status: IpStatus | null;
    tags: Tag[];
};

export type IpStatus = { id: number; name: string };
export type SubnetSummaryEntry = { id: number; name: string; values: string[] };
export type Tag = { id: number; name: string; color: string };

export type ExcludedRange = {
    id: number;
    start_ip: string;
    end_ip: string;
};
