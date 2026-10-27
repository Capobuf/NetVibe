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
    id: number;
    ip: string;
    description: string | null;
    hostname: string | null;
    mac_address: string | null;
};

export type ExcludedRange = {
    id: number;
    start_ip: string;
    end_ip: string;
};
