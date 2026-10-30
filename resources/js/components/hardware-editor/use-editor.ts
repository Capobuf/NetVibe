import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { parseCustomColors } from '@/lib/hardware-editor/colors';
import {
    arrangePorts,
    connectorTypes,
    arrangementUnits,
    faceLayers,
    reorderLayers,
    imageBounds,
    cropImage,
    type Bounds,
    type LayerState,
    type LayerOrderAction,
    blankDocument,
    duplicatePorts,
    pastePorts,
    labelConflict,
    newId,
    nextNumber,
    updatePorts,
    type Face,
    type PanelDocument,
    type PanelImage,
    type Port,
    type Point,
} from '@/lib/hardware-editor/document';
import {
    decodeImage,
    loadDraft,
    saveDraft,
    type Assets,
} from '@/lib/hardware-editor/storage';

type History = {
    past: PanelDocument[];
    current: PanelDocument;
    future: PanelDocument[];
};
type Session = { before: PanelDocument; steps: PanelDocument[] };
export type Selection = {
    kind: 'ports' | 'shell' | 'image' | 'canvas';
    ids: string[];
};
export function useEditor(userId: number) {
    const [history, setHistory] = useState<History>(() => ({
        past: [],
        current: blankDocument(),
        future: [],
    }));
    const [saved, setSaved] = useState<PanelDocument | null>(null);
    const [assets, setAssets] = useState<Assets>({});
    const [images, setImages] = useState<Record<string, HTMLImageElement>>({});
    const [face, setFace] = useState<Face>('front');
    const [selection, setSelection] = useState<Selection>({
        kind: 'canvas',
        ids: [],
    });
    const [enteredGroup, setEnteredGroup] = useState<string | null>(null);
    const [session, setSession] = useState<Session | null>(null);
    const [loading, setLoading] = useState(true);
    const [importing, setImporting] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [crop, setCrop] = useState<{
        image: PanelImage;
        frame: Bounds;
    } | null>(null);
    const [opacityPreview, setOpacityPreview] = useState<number | null>(null);
    const [borderColorPreview, setBorderColorPreview] = useState<string | null>(
        null,
    );
    const savingRef = useRef(false);
    const [customColors, setCustomColors] = useState<string[]>([]);
    const [paletteReady, setPaletteReady] = useState(false);
    const [clipboard, setClipboard] = useState<Port[] | null>(null);
    const pastePoint = useRef<Point | undefined>(undefined);
    const doc = history.current;
    const dirty = saved !== doc;
    const faceDoc = doc.faces[face];
    const ports = faceDoc.ports;
    const layers = faceLayers(faceDoc);
    const shellState = faceDoc.shellState;
    const portLocked = (p: Port) =>
        !!p.locked ||
        !!(
            p.group &&
            p.group !== enteredGroup &&
            ports.some((member) => member.group === p.group && member.locked)
        );
    const selected = ports.filter((p) => selection.ids.includes(p.id));
    const group =
        selected.length > 0 &&
        selected[0].group &&
        selected.every((p) => p.group === selected[0].group) &&
        selected.length ===
            ports.filter((p) => p.group === selected[0].group).length
            ? selected[0].group
            : null;

    useEffect(() => {
        setClipboard(null);
        pastePoint.current = undefined;
        let cancelled = false;
        async function restore() {
            try {
                const draft = await loadDraft(userId);
                const decoded: Record<string, HTMLImageElement> = {};
                if (draft)
                    for (const [id, blob] of Object.entries(draft.assets))
                        decoded[id] = await decodeImage(blob);
                if (cancelled) return;
                const document = draft?.document ?? blankDocument();
                setHistory({ past: [], current: document, future: [] });
                setSaved(document);
                setAssets(draft?.assets ?? {});
                setImages(decoded);
            } catch (e) {
                if (!cancelled) {
                    setLoadFailed(true);
                    setError(
                        (e instanceof Error
                            ? e.message
                            : 'Bozza non leggibile.') +
                            ' Ricarica per riprovare oppure scegli Bozza → Nuova bozza vuota.',
                    );
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        }
        void restore();
        return () => {
            cancelled = true;
        };
    }, [userId]);

    useEffect(() => {
        setPaletteReady(false);
        setCustomColors([]);
        try {
            setCustomColors(
                parseCustomColors(
                    localStorage.getItem(`netvibe:port-colors:${userId}`),
                ),
            );
            setPaletteReady(true);
        } catch {
            toast.error(
                'Impossibile leggere i colori salvati. Verifica i dati del sito nel browser e ricarica.',
            );
        }
    }, [userId]);
    function saveCustomColors(colors: string[]) {
        if (!paletteReady) return;
        try {
            const next = parseCustomColors(JSON.stringify(colors));
            localStorage.setItem(
                `netvibe:port-colors:${userId}`,
                JSON.stringify(next),
            );
            setCustomColors(next);
        } catch {
            toast.error(
                'Impossibile salvare la palette. Consenti il salvataggio dei dati del sito nel browser.',
            );
        }
    }

    useEffect(() => {
        pastePoint.current = undefined;
    }, [face]);

    useEffect(() => {
        const documents = [
            ...history.past,
            history.current,
            ...history.future,
            ...(session ? [session.before, ...session.steps] : []),
        ];
        const used = new Set(
            documents.flatMap((d) =>
                Object.values(d.faces).flatMap((f) =>
                    f.image ? [f.image.assetId] : [],
                ),
            ),
        );
        setAssets((current) =>
            Object.keys(current).some((id) => !used.has(id))
                ? Object.fromEntries(
                      Object.entries(current).filter(([id]) => used.has(id)),
                  )
                : current,
        );
        setImages((current) =>
            Object.keys(current).some((id) => !used.has(id))
                ? Object.fromEntries(
                      Object.entries(current).filter(([id]) => used.has(id)),
                  )
                : current,
        );
    }, [history, session]);

    function commit(next: PanelDocument) {
        next = { ...next, faces: { ...next.faces } };
        for (const side of ['front', 'rear'] as const)
            next.faces[side] = {
                ...next.faces[side],
                order: faceLayers(next.faces[side]).flatMap(
                    (layer) => layer.ids,
                ),
            };
        const conflict = labelConflict(next);
        if (conflict) {
            toast.error(
                `Etichetta «${conflict}» già assegnata. Scegli un altro valore.`,
            );
            return false;
        }
        if (JSON.stringify(next) === JSON.stringify(doc)) return true;
        setHistory((h) => ({
            past: [...h.past, h.current].slice(-100),
            current: next,
            future: [],
        }));
        return true;
    }
    function replace(next: PanelDocument) {
        pastePoint.current = undefined;
        commit(next);
        setSelection({ kind: 'canvas', ids: [] });
        setEnteredGroup(null);
        setFace('front');
        setError(null);
        setLoadFailed(false);
    }
    async function save(document = doc) {
        if (savingRef.current || loadFailed) return false;
        savingRef.current = true;
        setSaving(true);
        try {
            await saveDraft(userId, document, assets);
            setSaved(document);
            setError(null);
            toast.success('Bozza salvata in questo browser');
            return true;
        } catch (e) {
            const message =
                e instanceof Error
                    ? e.message
                    : 'Spazio del browser non disponibile.';
            setError(
                `Salvataggio fallito. ${message} Riprova senza chiudere l’editor.`,
            );
            return false;
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    }
    function undo() {
        if (session) {
            if (!session.steps.length) return;
            setHistory((h) => ({ ...h, current: session.steps.at(-1)! }));
            setSession({ ...session, steps: session.steps.slice(0, -1) });
            return;
        }
        setHistory((h) =>
            !h.past.length
                ? h
                : {
                      past: h.past.slice(0, -1),
                      current: h.past.at(-1)!,
                      future: [h.current, ...h.future],
                  },
        );
    }
    function redo() {
        if (!session)
            setHistory((h) =>
                !h.future.length
                    ? h
                    : {
                          past: [...h.past, h.current],
                          current: h.future[0],
                          future: h.future.slice(1),
                      },
            );
    }
    function setPortList(list: Port[]) {
        return commit(
            updatePorts(
                doc,
                face,
                list.map((p) =>
                    faceDoc.image &&
                    !p.opacityCustomized &&
                    !ports.some((old) => old.id === p.id)
                        ? { ...p, opacity: 0.8 }
                        : p,
                ),
            ),
        );
    }
    function patchPorts(patch: Partial<Port>) {
        const geometry = ['x', 'y', 'scale', 'rotation'].some(
            (key) => key in patch,
        );
        return setPortList(
            ports.map((p) =>
                selection.ids.includes(p.id) && (!geometry || !portLocked(p))
                    ? {
                          ...p,
                          ...patch,
                          opacityCustomized:
                              patch.opacity !== undefined ||
                              p.opacityCustomized,
                          poe: p.type === 'rj45' ? (patch.poe ?? p.poe) : false,
                      }
                    : p,
            ),
        );
    }
    function setRj45Function(type: 'rj45' | 'console') {
        setPortList(
            ports.map((p) =>
                selection.ids.includes(p.id) &&
                (p.type === 'rj45' || p.type === 'console')
                    ? {
                          ...p,
                          type,
                          poe: type === 'rj45' && p.poe,
                          borderColor:
                              p.borderColor === connectorTypes[p.type].color
                                  ? connectorTypes[type].color
                                  : p.borderColor,
                      }
                    : p,
            ),
        );
    }
    function patchImage(patch: Partial<PanelImage>) {
        const image = doc.faces[face].image;
        if (image)
            commit({
                ...doc,
                faces: {
                    ...doc.faces,
                    [face]: {
                        ...doc.faces[face],
                        image: { ...image, ...patch },
                    },
                },
            });
    }
    function fitImage() {
        const i = doc.faces[face].image;
        if (!i || i.locked) return;
        const scale = Math.min(
            doc.shell.width / i.crop.width,
            doc.shell.height / i.crop.height,
        );
        patchImage({
            scale,
            x:
                doc.shell.x +
                (doc.shell.width - i.crop.width * scale) / 2 -
                i.crop.x * scale,
            y:
                doc.shell.y +
                (doc.shell.height - i.crop.height * scale) / 2 -
                i.crop.y * scale,
        });
    }
    function remove() {
        if (selection.kind === 'ports') {
            setPortList(ports.filter((p) => !selection.ids.includes(p.id)));
            setSelection({ kind: 'canvas', ids: [] });
        }
        if (selection.kind === 'image') {
            commit({
                ...doc,
                faces: {
                    ...doc.faces,
                    [face]: { ...doc.faces[face], image: null },
                },
            });
            setSelection({ kind: 'canvas', ids: [] });
        }
    }
    function duplicate() {
        const copies = duplicatePorts(selected).map((p) => ({
            ...p,
            x: p.x + 24,
            y: p.y + 24,
        }));
        setPortList([...ports, ...copies]);
        setSelection({ kind: 'ports', ids: copies.map((p) => p.id) });
    }
    function copy() {
        if (!selected.length) return false;
        setClipboard(selected.map((port) => ({ ...port })));
        pastePoint.current = undefined;
        return true;
    }
    function cut() {
        if (!selected.length) return false;
        if (selected.some(portLocked)) {
            toast.error('Sblocca gli elementi selezionati prima di tagliarli.');
            return true;
        }
        if (
            !setPortList(
                ports.filter((port) => !selection.ids.includes(port.id)),
            )
        )
            return false;
        copy();
        setSelection({ kind: 'canvas', ids: [] });
        setEnteredGroup(null);
        return true;
    }
    function paste() {
        if (!clipboard) return false;
        const copies = pastePorts(doc, face, clipboard, pastePoint.current);
        if (!commit(updatePorts(doc, face, [...ports, ...copies])))
            return false;
        pastePoint.current = undefined;
        setEnteredGroup(null);
        setSelection({ kind: 'ports', ids: copies.map((port) => port.id) });
        return true;
    }
    function setPastePoint(point: Point) {
        pastePoint.current = point;
    }
    function groupPorts(ids = selection.ids) {
        if (ids.length < 2) return;
        const group = newId();
        if (
            !setPortList(
                ports.map((p) => (ids.includes(p.id) ? { ...p, group } : p)),
            )
        )
            return;
        setSelection({ kind: 'ports', ids });
        setEnteredGroup(null);
    }
    function ungroup() {
        const groups = new Set(selected.map((p) => p.group).filter(Boolean));
        setPortList(
            ports.map((p) =>
                p.group && groups.has(p.group) ? { ...p, group: null } : p,
            ),
        );
        setEnteredGroup(null);
    }
    const arrangementCount = arrangementUnits(selected, enteredGroup).length;
    function canArrange(action: string) {
        return (
            !selected.some(portLocked) &&
            arrangementCount >= (action.startsWith('distribute-') ? 3 : 2)
        );
    }
    function arrange(action: string, gap?: number) {
        if (!canArrange(action)) return;
        const arranged = new Map(
            arrangePorts(selected, action, gap, enteredGroup).map((p) => [
                p.id,
                p,
            ]),
        );
        setPortList(ports.map((p) => arranged.get(p.id) ?? p));
    }
    function rotate(degrees: 90 | 180 = 90) {
        setPortList(
            ports.map((p) =>
                selection.ids.includes(p.id) && !portLocked(p)
                    ? { ...p, rotation: (p.rotation + degrees) % 360 }
                    : p,
            ),
        );
    }
    function startNumbering(start: number, prefix: string) {
        setSession({ before: doc, steps: [] });
        setHistory((h) => ({
            ...h,
            current: { ...h.current, numbering: { next: start, prefix } },
        }));
    }
    function numberPort(id: string) {
        if (!session || savingRef.current) return;
        const label = `${doc.numbering.prefix}${doc.numbering.next}`;
        const next = updatePorts(
            doc,
            face,
            ports.map((p) => (p.id === id ? { ...p, label } : p)),
        );
        const conflict = labelConflict(next);
        if (conflict) {
            toast.error(
                `«${conflict}» è già assegnata. Cambia il prossimo numero.`,
            );
            return;
        }
        next.numbering = { ...doc.numbering, next: doc.numbering.next + 1 };
        setSession({ ...session, steps: [...session.steps, doc] });
        setHistory((h) => ({ ...h, current: next }));
        setSelection({ kind: 'ports', ids: [id] });
    }
    async function finishNumbering(discard: boolean) {
        if (!session || savingRef.current) return false;
        if (discard) setHistory((h) => ({ ...h, current: session.before }));
        else {
            if (!(await save())) return false;
            setHistory((h) => ({
                past: [...h.past, session.before].slice(-100),
                current: h.current,
                future: [],
            }));
        }
        setSession(null);
        return true;
    }
    function setNextNumber(next: number) {
        setHistory((h) => ({
            ...h,
            current: {
                ...h.current,
                numbering: { ...h.current.numbering, next },
            },
        }));
    }
    function suggestedNumber(prefix: string) {
        return nextNumber(
            doc,
            prefix,
            prefix === doc.numbering.prefix ? doc.numbering.next : 1,
        );
    }
    async function importImage(file: File) {
        setImporting(true);
        try {
            const image = await decodeImage(file);
            const id = newId();
            const scale = Math.min(
                doc.shell.width / image.naturalWidth,
                doc.shell.height / image.naturalHeight,
            );
            const panelImage: PanelImage = {
                assetId: id,
                width: image.naturalWidth,
                height: image.naturalHeight,
                scale,
                x:
                    doc.shell.x +
                    (doc.shell.width - image.naturalWidth * scale) / 2,
                y:
                    doc.shell.y +
                    (doc.shell.height - image.naturalHeight * scale) / 2,
                opacity: 1,
                locked: false,
                hidden: false,
                crop: {
                    x: 0,
                    y: 0,
                    width: image.naturalWidth,
                    height: image.naturalHeight,
                },
                clip: true,
            };
            setAssets((a) => ({ ...a, [id]: file }));
            setImages((a) => ({ ...a, [id]: image }));
            commit({
                ...doc,
                faces: {
                    ...doc.faces,
                    [face]: {
                        ...doc.faces[face],
                        image: panelImage,
                        ports: ports.map((p) => ({
                            ...p,
                            opacity: p.opacityCustomized ? p.opacity : 0.8,
                        })),
                        order: faceDoc.image
                            ? faceDoc.order
                            : [
                                  'image',
                                  ...layers.flatMap((layer) => layer.ids),
                              ],
                    },
                },
            });
            setSelection({ kind: 'image', ids: [] });
        } catch (e) {
            toast.error(
                e instanceof Error
                    ? e.message
                    : 'Importazione fallita. Riprova con un’altra immagine.',
            );
        } finally {
            setImporting(false);
        }
    }
    function patchShellState(patch: Partial<LayerState>) {
        commit({
            ...doc,
            faces: {
                ...doc.faces,
                [face]: { ...faceDoc, shellState: { ...shellState, ...patch } },
            },
        });
    }
    function patchLayer(selection: Selection, patch: Partial<LayerState>) {
        if (selection.kind === 'shell') patchShellState(patch);
        if (selection.kind === 'image') patchImage(patch);
        if (selection.kind === 'ports')
            setPortList(
                ports.map((p) =>
                    selection.ids.includes(p.id) ? { ...p, ...patch } : p,
                ),
            );
    }
    function layerIds(selection: Selection) {
        return selection.kind === 'image' ? ['image'] : selection.ids;
    }
    function canReorder(action: LayerOrderAction, target = selection) {
        return (
            reorderLayers(layers, layerIds(target), action).join(',') !==
            layers.flatMap((layer) => layer.ids).join(',')
        );
    }
    function reorder(action: LayerOrderAction, target = selection) {
        const order = reorderLayers(layers, layerIds(target), action);
        commit({
            ...doc,
            faces: { ...doc.faces, [face]: { ...faceDoc, order } },
        });
    }
    const canResetCrop =
        !!faceDoc.image &&
        (faceDoc.image.crop.x !== 0 ||
            faceDoc.image.crop.y !== 0 ||
            faceDoc.image.crop.width !== faceDoc.image.width ||
            faceDoc.image.crop.height !== faceDoc.image.height);
    function resetCrop() {
        if (faceDoc.image && !faceDoc.image.locked)
            patchImage({
                crop: {
                    x: 0,
                    y: 0,
                    width: faceDoc.image.width,
                    height: faceDoc.image.height,
                },
            });
    }
    function startCrop() {
        if (!faceDoc.image || faceDoc.image.locked || faceDoc.image.hidden)
            return;
        setSelection({ kind: 'image', ids: [] });
        setCrop({ image: faceDoc.image, frame: imageBounds(faceDoc.image) });
    }
    function finishCrop(apply: boolean) {
        if (apply && crop) patchImage(cropImage(crop.image, crop.frame));
        setCrop(null);
    }
    return {
        doc,
        layers,
        shellState,
        portLocked,
        patchShellState,
        patchLayer,
        reorder,
        canReorder,
        crop,
        setCrop,
        startCrop,
        resetCrop,
        canResetCrop,
        finishCrop,
        opacityPreview,
        setOpacityPreview,
        borderColorPreview,
        customColors,
        paletteReady,
        saveCustomColors,
        setBorderColorPreview,
        face,
        setFace,
        selection,
        setSelection,
        enteredGroup,
        setEnteredGroup,
        selected,
        group,
        ports,
        images,
        loading: loading || importing,
        initialLoading: loading,
        loadFailed,
        saving,
        dirty,
        error,
        session,
        commit,
        replace,
        save,
        undo,
        redo,
        setPortList,
        patchPorts,
        setRj45Function,
        patchImage,
        fitImage,
        remove,
        duplicate,
        copy,
        cut,
        paste,
        canPaste: clipboard !== null,
        setPastePoint,
        groupPorts,
        ungroup,
        arrange,
        canArrange,
        rotate,
        startNumbering,
        numberPort,
        finishNumbering,
        suggestedNumber,
        setNextNumber,
        importImage,
        canUndo: session ? !!session.steps.length : !!history.past.length,
        canRedo: !session && !!history.future.length,
    };
}
export type Editor = ReturnType<typeof useEditor>;
