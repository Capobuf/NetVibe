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
    inventory?: {
        activated_at: string | null;
        last_synced_at: string | null;
        status: string | null;
        devices: UnifiInventoryDevice[];
    } | null;
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

export type UnifiPort = {
    idx: number;
    connector: string | null;
    state: string | null;
    speed_mbps: number | null;
    max_speed_mbps: number | null;
    poe: {
        standard?: string | null;
        type?: number | null;
        enabled?: boolean | null;
        state?: string | null;
    } | null;
    device_interface_id?: number | null;
    missing?: boolean;
};

export type UnifiObservedDevice = Omit<UnifiDevice, 'id'> & {
    features: string[];
    firmware_version: string | null;
    ports: UnifiPort[];
};

export type UnifiInventoryDevice = {
    id: number;
    external_id: string;
    status: 'pending' | 'linked' | 'ignored' | 'deleted';
    present: boolean;
    synced_at: string | null;
    observed: UnifiObservedDevice;
    device: { id: number; name: string; device_model_id: number | null } | null;
    candidates: {
        id: number;
        name: string;
        site_id: number;
        current_site: boolean;
        linked_source: string | null;
        requires_relink: boolean;
    }[];
    problem: string | null;
};

export type UnifiDeviceDetail = {
    id: number;
    connection_id: number;
    revision: string | null;
    source_name: string;
    site_name: string;
    synced_at: string | null;
    stale: boolean;
    observed: UnifiObservedDevice;
    ports: UnifiPort[];
    differences: {
        field: 'name' | 'model';
        local: string | null;
        observed: string | null;
        maintained: boolean;
        can_update: boolean;
    }[];
    snipe_owned: boolean;
};

export function unifiState(state: string | null | undefined, stale = false) {
    if (stale) return 'Non aggiornato';
    return (
        (
            {
                ONLINE: 'Online',
                OFFLINE: 'Offline',
                UP: 'Attiva',
                DOWN: 'Inattiva',
                UNKNOWN: 'Non disponibile',
            } as Record<string, string>
        )[state ?? ''] ??
        state ??
        'Non disponibile'
    );
}

export function unifiType(features: string[]) {
    return features
        .map(
            (feature) =>
                (
                    {
                        gateway: 'Gateway',
                        switching: 'Switch',
                        accessPoint: 'Access point',
                    } as Record<string, string>
                )[feature] ?? feature,
        )
        .join(', ');
}
