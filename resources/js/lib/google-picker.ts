export type PickerConfig = { api_key: string; app_id: string };
export type DriveFolder = { id: string; name: string };

type PickerData = { action: string; docs?: DriveFolder[] };
type Builder = {
    addView(view: unknown): Builder;
    setOAuthToken(token: string): Builder;
    setDeveloperKey(key: string): Builder;
    setAppId(id: string): Builder;
    setLocale(locale: string): Builder;
    setTitle(title: string): Builder;
    setCallback(callback: (data: PickerData) => void): Builder;
    build(): { setVisible(visible: boolean): void };
};
type DocsView = {
    setIncludeFolders(value: boolean): DocsView;
    setSelectFolderEnabled(value: boolean): DocsView;
    setMimeTypes(types: string): DocsView;
};
type PickerApi = {
    DocsView: new (viewId: string) => DocsView;
    PickerBuilder: new () => Builder;
    ViewId: { FOLDERS: string };
    Action: { PICKED: string; CANCEL: string };
};

declare global {
    interface Window {
        gapi?: {
            load(
                api: string,
                options: { callback: () => void; onerror: () => void },
            ): void;
        };
        google?: { picker?: PickerApi };
    }
}

let loading: Promise<void> | null = null;

// Google's script is loaded only when an administrator chooses a folder.
function load(): Promise<void> {
    loading ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://apis.google.com/js/api.js';
        script.async = true;
        script.onload = () =>
            window.gapi?.load('picker', { callback: resolve, onerror: reject });
        script.onerror = () => {
            loading = null;
            reject(new Error('Google Picker non raggiungibile.'));
        };
        document.head.appendChild(script);
    });

    return loading;
}

/**
 * Opens the Google folder picker for one account. With the drive.file scope,
 * picking the folder is what lets NetVibe write into it.
 */
export async function pickFolder(
    tokenUrl: string,
    config: PickerConfig,
): Promise<DriveFolder | null> {
    const [response] = await Promise.all([
        fetch(tokenUrl, { headers: { Accept: 'application/json' } }),
        load(),
    ]);
    const body = (await response.json()) as {
        access_token?: string;
        message?: string;
    };
    const picker = window.google?.picker;
    if (!response.ok || !body.access_token || !picker) {
        throw new Error(body.message ?? 'Accesso a Google Drive non riuscito.');
    }

    return new Promise((resolve) => {
        const view = new picker.DocsView(picker.ViewId.FOLDERS)
            .setIncludeFolders(true)
            .setSelectFolderEnabled(true)
            .setMimeTypes('application/vnd.google-apps.folder');
        new picker.PickerBuilder()
            .addView(view)
            .setOAuthToken(body.access_token!)
            .setDeveloperKey(config.api_key)
            .setAppId(config.app_id)
            .setLocale('it')
            .setTitle('Scegli la cartella')
            .setCallback((data) => {
                if (data.action === picker.Action.PICKED && data.docs?.[0]) {
                    resolve({ id: data.docs[0].id, name: data.docs[0].name });
                } else if (data.action === picker.Action.CANCEL) {
                    resolve(null);
                }
            })
            .build()
            .setVisible(true);
    });
}
