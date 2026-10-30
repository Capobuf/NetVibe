import { blankDocument, type PanelDocument } from './document';
import { validateDraft, type Assets } from './storage';
import { update } from '@/routes/manufacturers/models/panel';
import type { DeviceModel, Manufacturer } from '@/types/device';

export type CatalogModel = DeviceModel & {
    manufacturer: Manufacturer;
    assetUrls: Record<string, string>;
};
export async function loadModel(model: CatalogModel) {
    const assets: Assets = {};
    await Promise.all(
        Object.entries(model.assetUrls).map(async ([id, url]) => {
            const response = await fetch(url, {
                headers: { Accept: 'image/*' },
            });
            if (
                !response.ok ||
                !response.headers.get('content-type')?.startsWith('image/')
            )
                throw new Error(
                    'Immagine non disponibile. Ricarica il modello.',
                );
            assets[id] = await response.blob();
        }),
    );
    const draft = {
        document: model.panel ?? { ...blankDocument(), name: model.name },
        assets,
    };
    validateDraft(draft);
    return draft;
}
export async function saveModel(
    model: CatalogModel,
    revision: number,
    document: PanelDocument,
    assets: Assets,
): Promise<number> {
    validateDraft({ document, assets });
    const body = new FormData();
    body.append('document', JSON.stringify(document));
    body.append('revision', String(revision));
    for (const side of ['front', 'rear'] as const) {
        const picture = document.faces[side].image;
        if (picture)
            body.append(
                `images[${side}]`,
                assets[picture.assetId],
                `${side}.${assets[picture.assetId].type.split('/')[1]}`,
            );
    }
    const token = window.document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    const response = await fetch(
        update.url({ manufacturer: model.manufacturer_id, model: model.id }),
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
            },
            body,
        },
    );
    if (!response.ok) {
        const result = await response.json().catch(() => null);
        throw new Error(
            result?.errors
                ? Object.values(result.errors).flat().join(' ')
                : 'Salvataggio non riuscito. Verifica la sessione e riprova.',
        );
    }
    return (await response.json()).revision;
}
