import {
    connectorTypes,
    labelConflict,
    labelPositions,
    type PanelDocument,
} from './document';

export type Assets = Record<string, Blob>;
type Draft = { document: PanelDocument; assets: Assets };

function openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('netvibe-hardware-editor', 3);
        request.onupgradeneeded = () => {
            const db = request.result;
            // Prototype drafts are discarded instead of converting older document formats.
            if (db.objectStoreNames.contains('drafts'))
                db.deleteObjectStore('drafts');
            db.createObjectStore('drafts');
        };
        request.onerror = () => reject(request.error);
        request.onblocked = () =>
            reject(new Error('Chiudi le altre schede di NetVibe e riprova.'));
        request.onsuccess = () => resolve(request.result);
    });
}
export async function loadDraft(userId: number): Promise<Draft | null> {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const transaction = db.transaction('drafts', 'readonly');
        const request = transaction.objectStore('drafts').get(userId);
        transaction.oncomplete = () => {
            db.close();
            if (!request.result) return resolve(null);
            try {
                validateDraft(request.result);
                resolve(request.result);
            } catch (error) {
                reject(error);
            }
        };
        transaction.onabort = () => {
            db.close();
            reject(transaction.error);
        };
    });
}
export async function saveDraft(
    userId: number,
    document: PanelDocument,
    assets: Assets,
): Promise<void> {
    const currentAssets: Assets = {};
    for (const face of Object.values(document.faces)) {
        if (face.image) {
            const blob = assets[face.image.assetId];
            if (!blob)
                throw new Error(
                    'Immagine mancante: importala nuovamente prima di salvare.',
                );
            currentAssets[face.image.assetId] = blob;
        }
    }
    validateDraft({ document, assets: currentAssets });
    const db = await openDatabase();
    await new Promise<void>((resolve, reject) => {
        const transaction = db.transaction('drafts', 'readwrite');
        transaction
            .objectStore('drafts')
            .put({ document, assets: currentAssets }, userId);
        transaction.oncomplete = () => {
            db.close();
            resolve();
        };
        transaction.onabort = () => {
            db.close();
            reject(transaction.error);
        };
    });
}
export function validateDraft(value: Draft): void {
    const fail = () => {
        throw new Error(
            'Disegno salvato non compatibile o danneggiato. Ricarica per riprovare.',
        );
    };
    const finite = (n: number) => typeof n === 'number' && Number.isFinite(n);
    const stateValid = (state: { hidden: boolean; locked: boolean }) =>
        !!state &&
        typeof state.hidden === 'boolean' &&
        typeof state.locked === 'boolean';
    const d = value?.document;
    if (
        !d ||
        d.version !== 3 ||
        typeof d.name !== 'string' ||
        !d.shell ||
        !d.faces ||
        !d.numbering ||
        !value.assets
    )
        return fail();
    if (
        ![
            d.shell.x,
            d.shell.y,
            d.shell.width,
            d.shell.height,
            d.shell.radius,
            d.shell.border,
            d.numbering.next,
        ].every(finite) ||
        d.shell.width < 40 ||
        d.shell.height < 40 ||
        d.shell.width > 5000 ||
        d.shell.height > 5000 ||
        d.shell.radius < 0 ||
        d.shell.border < 0 ||
        !Number.isSafeInteger(d.numbering.next) ||
        d.numbering.next < 0 ||
        typeof d.numbering.prefix !== 'string'
    )
        return fail();
    const ids = new Set<string>();
    const groups = new Map<string, string>();
    for (const side of ['front', 'rear'] as const) {
        const face = d.faces[side];
        if (!face || !Array.isArray(face.ports)) return fail();
        if (!stateValid(face.shellState)) return fail();
        if (
            !Array.isArray(face.order) ||
            face.order.some(
                (id) =>
                    typeof id !== 'string' ||
                    (id !== 'image' && !face.ports.some((p) => p.id === id)),
            ) ||
            new Set(face.order).size !== face.order.length
        )
            return fail();
        for (const p of face.ports) {
            if (
                !p ||
                !stateValid(p) ||
                !finite(p.opacity) ||
                p.opacity < 0 ||
                p.opacity > 1 ||
                typeof p.opacityCustomized !== 'boolean' ||
                typeof p.borderColor !== 'string' ||
                !/^#[0-9a-fA-F]{6}$/.test(p.borderColor) ||
                typeof p.id !== 'string' ||
                ids.has(p.id) ||
                !Object.hasOwn(connectorTypes, p.type) ||
                !Object.hasOwn(labelPositions, p.labelPosition) ||
                typeof p.label !== 'string' ||
                typeof p.poe !== 'boolean' ||
                ![p.x, p.y, p.scale, p.rotation].every(finite) ||
                p.scale < 0.2 ||
                p.scale > 8 ||
                ![0, 90, 180, 270].includes(p.rotation) ||
                (p.group !== null && typeof p.group !== 'string')
            )
                return fail();
            if (p.group && groups.has(p.group) && groups.get(p.group) !== side)
                return fail();
            if (p.group) groups.set(p.group, side);
            ids.add(p.id);
        }
        if (face.image) {
            const i = face.image;
            if (!stateValid(i)) return fail();
            if (
                !i.crop ||
                !Object.values(i.crop).every(finite) ||
                ![i.crop.x, i.crop.y, i.crop.width, i.crop.height].every(
                    finite,
                ) ||
                i.crop.x < 0 ||
                i.crop.y < 0 ||
                i.crop.width <= 0 ||
                i.crop.height <= 0 ||
                i.crop.x + i.crop.width > i.width + 0.000001 ||
                i.crop.y + i.crop.height > i.height + 0.000001
            )
                return fail();
            if (
                ![i.x, i.y, i.width, i.height, i.scale, i.opacity].every(
                    finite,
                ) ||
                i.width <= 0 ||
                i.height <= 0 ||
                i.width * i.height > 16000000 ||
                i.scale <= 0 ||
                i.opacity < 0 ||
                i.opacity > 1 ||
                typeof i.locked !== 'boolean' ||
                typeof i.clip !== 'boolean' ||
                !(value.assets[i.assetId] instanceof Blob)
            )
                return fail();
        }
    }
    if (labelConflict(d)) return fail();
}

// Read dimensions before decoding: compressed files can expand to enormous bitmaps.
export async function imageDimensions(
    file: Blob,
): Promise<{ width: number; height: number }> {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type))
        throw new Error('Scegli un’immagine PNG, JPEG o WebP.');
    if (file.size > 12 * 1024 * 1024)
        throw new Error('Immagine troppo pesante. Il limite è 12 MB.');
    const b = new Uint8Array(await file.arrayBuffer());
    const v = new DataView(b.buffer);
    const text = (start: number, length: number) =>
        String.fromCharCode(...b.subarray(start, start + length));
    let width = 0,
        height = 0;
    if (b.length >= 24 && b[0] === 137 && text(1, 3) === 'PNG') {
        width = v.getUint32(16);
        height = v.getUint32(20);
    } else if (b.length >= 4 && b[0] === 255 && b[1] === 216) {
        let offset = 2;
        while (offset + 9 < b.length) {
            if (b[offset] !== 255) break;
            const marker = b[offset + 1];
            if (marker === 255) {
                offset++;
                continue;
            }
            if (marker === 217 || marker === 218) break;
            if (marker === 1 || (marker >= 208 && marker <= 215)) {
                offset += 2;
                continue;
            }
            const length = v.getUint16(offset + 2);
            if (length < 2 || offset + 2 + length > b.length) break;
            if (
                [
                    192, 193, 194, 195, 197, 198, 199, 201, 202, 203, 205, 206,
                    207,
                ].includes(marker)
            ) {
                height = v.getUint16(offset + 5);
                width = v.getUint16(offset + 7);
                break;
            }
            offset += length + 2;
        }
    } else if (
        b.length >= 30 &&
        text(0, 4) === 'RIFF' &&
        text(8, 4) === 'WEBP'
    ) {
        const format = text(12, 4);
        if (format === 'VP8X') {
            if (b[20] & 2)
                throw new Error('Scegli un’immagine WebP non animata.');
            width = 1 + b[24] + (b[25] << 8) + (b[26] << 16);
            height = 1 + b[27] + (b[28] << 8) + (b[29] << 16);
        } else if (
            format === 'VP8 ' &&
            b[23] === 157 &&
            b[24] === 1 &&
            b[25] === 42
        ) {
            width = v.getUint16(26, true) & 0x3fff;
            height = v.getUint16(28, true) & 0x3fff;
        } else if (format === 'VP8L' && b[20] === 47) {
            const bits = v.getUint32(21, true);
            width = (bits & 0x3fff) + 1;
            height = ((bits >> 14) & 0x3fff) + 1;
        }
    }
    if (!width || !height)
        throw new Error(
            'Immagine non leggibile. Esportala nuovamente come PNG, JPEG o WebP.',
        );
    if (width * height > 16000000 || width > 8192 || height > 8192)
        throw new Error(
            'Risoluzione troppo alta. Riduci l’immagine a 16 megapixel e massimo 8192 px per lato.',
        );
    return { width, height };
}
export async function decodeImage(blob: Blob): Promise<HTMLImageElement> {
    await imageDimensions(blob);
    const url = URL.createObjectURL(blob);
    const image = new Image();
    try {
        image.src = url;
        await image.decode();
        return image;
    } catch {
        throw new Error(
            'Impossibile decodificare l’immagine. Scegli un altro file.',
        );
    } finally {
        URL.revokeObjectURL(url);
    }
}
