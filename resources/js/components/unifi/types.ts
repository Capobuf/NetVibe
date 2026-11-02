import type { Company } from '@/types/company';

export type UnifiDevice = {
    id: string;
    name: string | null;
    model: string | null;
    mac_address: string | null;
    ip_address: string | null;
    state: string | null;
};

export type UnifiNetwork = {
    id: string;
    name: string | null;
    vlan_id: number | null;
    subnet: string | null;
    ip_address: string | null;
    management: string | null;
    default: boolean;
};

export type UnifiSite = {
    id: number;
    console_id: string;
    console_name: string;
    external_site_id: string;
    name: string;
    version: string | null;
    discovered_at: string | null;
    available: boolean;
    preview: {
        devices: UnifiDevice[];
        networks: UnifiNetwork[] | null;
        networks_status: 'ok' | 'unsupported';
    } | null;
    site: { id: number; name: string | null; company: Company } | null;
    confirmed_at: string | null;
};

export type UnifiConnection = {
    id: number;
    name: string;
    mode: 'cloud' | 'direct';
    server_url?: string | null;
    has_key?: boolean;
    allow_unverified_certificate?: boolean;
    revision?: string;
    enabled: boolean;
    checked_at: string | null;
    check_status: string | null;
    check_message: string | null;
    discovered_at: string | null;
    hosts?: { id: string; name: string }[];
    selected_console_ids?: string[];
    sites: UnifiSite[];
};

export type UnifiCompany = Company & {
    sites: { id: number; name: string | null }[];
};
