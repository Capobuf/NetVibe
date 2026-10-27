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
