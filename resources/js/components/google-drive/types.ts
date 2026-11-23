import type { IntegrationState } from '@/components/integration-header';
import type { PickerConfig } from '@/lib/google-picker';
import type { Company } from '@/types/company';

export type DriveAccount = { id: number; email: string; syncs_count: number };

export type DriveSync = {
    id: number;
    folder_id: string;
    folder_name: string;
    interval: number;
    file_url: string | null;
    synced_at: string | null;
    error: string | null;
    company: Company;
    account: { id: number; email: string } | null;
};

export type DriveProject = {
    client_id: string | null;
    project_number: string | null;
    configured: boolean;
    has_secret: boolean;
    has_api_key: boolean;
    origin: string;
    redirect_uri: string;
};

export type EditorData = {
    accounts: DriveAccount[];
    intervals: number[];
    picker: PickerConfig | null;
};

export type Editing = { sync: DriveSync } | { companies: Company[] };

export const capitalize = (text: string) =>
    text.charAt(0).toUpperCase() + text.slice(1);

export function syncState(sync: DriveSync): IntegrationState {
    if (sync.error) return 'error';
    if (!sync.account) return 'suspended';
    return sync.synced_at ? 'active' : 'pending';
}
