import { ZoomControls } from '@/components/zoom-controls';
import { useLeaveGuard } from '@/hooks/use-leave-guard';
import { SaveButton } from '@/components/save-button';
import { CatalogForm } from '@/components/devices/catalog-form';
import type { CatalogModel } from '@/lib/hardware-editor/catalog-storage';
import { index as modelIndex } from '@/routes/device-models';
import { update as updateModel } from '@/routes/manufacturers/models';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Crop,
    X,
    Check,
    PanelsTopLeft,
    PanelRight,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { LabelDialog } from '@/components/hardware-editor/editor-dialogs';
import { BlockPanel } from '@/components/hardware-editor/block-panel';
import {
    EditorCanvas,
    type CanvasApi,
} from '@/components/hardware-editor/editor-canvas';
import { EditorContextMenu } from '@/components/hardware-editor/editor-context-menu';
import { EditorLayers } from '@/components/hardware-editor/editor-layers';
import { EditorInspector } from '@/components/hardware-editor/editor-inspector';
import { EditorToolbar } from '@/components/hardware-editor/editor-toolbar';
import { NumberingPanel } from '@/components/hardware-editor/numbering-panel';
import { IntegrationPortsPanel } from '@/components/hardware-editor/integration-ports-panel';
import { useEditor } from '@/components/hardware-editor/use-editor';
import { IconButton } from '@/components/icon-button';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { UnifiMark } from '@/components/unifi-mark';
import { ErrorNotification } from '@/components/error-notification';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    labelConflict,
    makePort,
    numberingLabels,
    previewBlock,
    type BlockOptions,
    updatePorts,
    type ConnectorType,
    type Numbering,
    type Point,
} from '@/lib/hardware-editor/document';
import { cn } from '@/lib/utils';

export default function HardwareEditor({
    catalogModel,
}: {
    catalogModel: CatalogModel;
}) {
    const e = useEditor(catalogModel);
    const renameUrl = updateModel.form({
        manufacturer: catalogModel.manufacturer_id,
        model: catalogModel.id,
    }).action;
    const mikrotik =
        catalogModel.manufacturer.name.trim().toLowerCase() === 'mikrotik';
    const unifi =
        catalogModel.manufacturer.name.trim().toLowerCase() === 'ubiquiti';
    const currentDocument = useRef(e.doc);
    currentDocument.current = e.doc;
    // Return to the page that opened the editor; only same-origin paths are accepted.
    const from = new URLSearchParams(window.location.search).get('from');
    const returnTo = from && /^\/(?![/\\])/.test(from) ? from : null;
    const exitRoute =
        returnTo ?? modelIndex({ query: { model: catalogModel.id } });
    const canvas = useRef<CanvasApi>(null);
    const context = useRef<HTMLDivElement>(null);
    const file = useRef<HTMLInputElement>(null);
    const [tool, setTool] = useState('select');
    const [snap, setSnap] = useState(true);
    const [inspector, setInspector] = useState(true);
    const [inspectorTab, setInspectorTab] = useState('properties');
    const [layers, setLayers] = useState(false);
    const [zoom, setZoom] = useState(1);
    const [block, setBlock] = useState<BlockOptions | null>(null);
    const hasReferenceImage = !!e.doc.faces[e.face].image;
    const blockPreview = useMemo(
        () =>
            block
                ? previewBlock(block).map((port) => ({
                      ...port,
                      opacity: hasReferenceImage ? 0.8 : 1,
                  }))
                : undefined,
        [block, hasReferenceImage],
    );
    const [editLabel, setEditLabel] = useState(false);
    const [contextPoint, setContextPoint] = useState<Point>({ x: 0, y: 0 });
    const [contextOpen, setContextOpen] = useState(false);
    const [numberMode, setNumberMode] = useState<'quick' | 'click' | null>(
        null,
    );
    const [numberOptions, setNumberOptions] = useState<Numbering>({
        start: 1,
        prefix: '',
        order: 'rows',
        reverseX: false,
        reverseY: false,
    });
    const [endNumbering, setEndNumbering] = useState(false);
    const [shortcuts, setShortcuts] = useState(false);
    const { leave, setLeave, allowLeave } = useLeaveGuard(
        !e.initialLoading && !!(e.dirty || e.session || e.crop || block),
        {
            // Renaming preserves the editor's unsaved panel and stays on this page.
            ignore: (visit) =>
                visit.method === 'post' &&
                visit.preserveState === true &&
                visit.url.href ===
                    new URL(renameUrl, window.location.origin).href,
            onBlocked: () => {
                if (e.session) setEndNumbering(true);
            },
        },
    );
    const preview =
        numberMode === 'quick'
            ? numberingLabels(e.selected, numberOptions)
            : undefined;
    const numberedDoc = preview
        ? updatePorts(
              e.doc,
              e.face,
              e.ports.map((p) =>
                  preview.has(p.id) ? { ...p, label: preview.get(p.id)! } : p,
              ),
          )
        : e.doc;
    const conflict = preview ? labelConflict(numberedDoc) : null;
    const blocked = !!e.session || !!numberMode || !!e.crop || !!block;
    const saveDisabled =
        e.loading ||
        e.loadFailed ||
        e.saving ||
        !!preview ||
        !!e.crop ||
        !!block;
    function exitEditor() {
        allowLeave.current = true;
        router.visit(exitRoute, {
            onFinish: () => {
                allowLeave.current = false;
            },
        });
    }
    async function saveAndExit(afterSave = exitEditor) {
        if (saveDisabled) return;
        const document = e.doc;
        const succeeded = e.session
            ? await e.finishNumbering(false)
            : await e.save();
        if (!succeeded) return;
        setEndNumbering(false);
        setNumberMode(null);
        // Keep edits made while the save was in flight in the editor.
        if (currentDocument.current !== document) return;
        allowLeave.current = true;
        setLeave(null);
        afterSave();
    }
    function openBlock() {
        setInspectorTab('properties');
        setBlock({
            ...(canvas.current?.center() ?? {
                x: e.doc.shell.x + e.doc.shell.width / 2,
                y: e.doc.shell.y + e.doc.shell.height / 2,
            }),
            type: 'rj45',
            count: 24,
            columns: 12,
            gapX: 16,
            gapY: 32,
            rotation: 0,
            columnFirst: false,
            scale: 1,
        });
        toggleInspector(true);
        setTool('select');
        e.setSelection({ kind: 'canvas', ids: [] });
        e.setEnteredGroup(null);
    }
    function insertBlock() {
        if (!blockPreview || !e.setPortList([...e.ports, ...blockPreview]))
            return;
        e.setEnteredGroup(null);
        e.setSelection({ kind: 'ports', ids: blockPreview.map((p) => p.id) });
        setBlock(null);
    }
    function toggleInspector(open: boolean) {
        setInspector(open);
        if (open && window.innerWidth < 1280) setLayers(false);
    }
    function toggleLayers(open: boolean) {
        setLayers(open);
        if (open && window.innerWidth < 1280) setInspector(false);
    }
    const properties = () => {
        setInspectorTab('properties');
        toggleInspector(true);
    };
    const image = () => {
        file.current?.click();
    };
    const fit = () => canvas.current?.fit();
    const label = () => {
        setEditLabel(true);
        setContextOpen(false);
    };
    function add(
        type: ConnectorType,
        point = canvas.current?.center() ?? {
            x: e.doc.shell.x + e.doc.shell.width / 2,
            y: e.doc.shell.y + e.doc.shell.height / 2,
        },
    ) {
        const port = makePort(type, point);
        e.setPortList([...e.ports, port]);
        e.setSelection({ kind: 'ports', ids: [port.id] });
        setTool('select');
    }
    function openNumbering(mode: 'quick' | 'click') {
        const prefix = e.doc.numbering.prefix;
        setInspectorTab('properties');
        setNumberOptions({
            ...numberOptions,
            start: e.suggestedNumber(prefix),
            prefix,
        });
        setNumberMode(mode);
        toggleInspector(true);
        setTool('select');
    }
    function closeNumbering() {
        if (e.session) setEndNumbering(true);
        else setNumberMode(null);
    }
    function cancelEndNumbering() {
        setEndNumbering(false);
        setLeave(null);
    }
    async function finishNumbering(discard = false) {
        if (!(await e.finishNumbering(discard))) return;
        setEndNumbering(false);
        setNumberMode(null);
        setLeave(null);
        leave?.();
    }
    function applyNumbering() {
        if (numberMode === 'quick') {
            if (
                e.commit({
                    ...numberedDoc,
                    numbering: {
                        prefix: numberOptions.prefix,
                        next: numberOptions.start + e.selected.length,
                    },
                })
            )
                setNumberMode(null);
        } else e.startNumbering(numberOptions.start, numberOptions.prefix);
    }
    function openContextKeyboard() {
        if (blocked || !context.current) return;
        const rect = context.current.getBoundingClientRect();
        context.current.dispatchEvent(
            new MouseEvent('contextmenu', {
                bubbles: true,
                cancelable: true,
                clientX: rect.left + rect.width / 2,
                clientY: rect.top + rect.height / 2,
                button: 2,
            }),
        );
        setContextPoint(canvas.current?.center() ?? { x: 0, y: 0 });
    }
    useEffect(() => {
        const key = (event: KeyboardEvent) => {
            if (
                event.defaultPrevented ||
                e.loading ||
                e.saving ||
                (event.target as HTMLElement).closest(
                    'input,textarea,select,[contenteditable="true"],[role="dialog"],[role="menu"],[role="listbox"]',
                )
            )
                return;
            if (contextOpen) return;
            const mod = event.ctrlKey || event.metaKey;
            if (block) {
                if (mod && event.key.toLowerCase() === 's')
                    event.preventDefault();
                if (event.key === 'Escape') {
                    event.preventDefault();
                    setBlock(null);
                }
                return;
            }
            if (e.crop) {
                if (event.key === 'Escape' || event.key === 'Enter') {
                    event.preventDefault();
                    e.finishCrop(event.key === 'Enter');
                }
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                if (e.session) setEndNumbering(true);
                else if (numberMode) setNumberMode(null);
                else {
                    e.setSelection({ kind: 'canvas', ids: [] });
                    e.setEnteredGroup(null);
                }
                return;
            }
            if (e.session) {
                if (
                    event.key === 'Backspace' ||
                    (mod && event.key.toLowerCase() === 'z' && !event.shiftKey)
                ) {
                    event.preventDefault();
                    e.undo();
                }
                return;
            }
            if (numberMode) return;
            if (
                event.key === 'ContextMenu' ||
                (event.shiftKey && event.key === 'F10')
            ) {
                event.preventDefault();
                openContextKeyboard();
                return;
            }
            if (mod && event.key.toLowerCase() === 's') {
                event.preventDefault();
                void e.save();
            } else if (
                mod &&
                ['c', 'x', 'v'].includes(event.key.toLowerCase())
            ) {
                const handled =
                    event.key.toLowerCase() === 'c'
                        ? e.copy()
                        : event.key.toLowerCase() === 'x'
                          ? e.cut()
                          : e.paste();
                if (handled) event.preventDefault();
            } else if (mod && event.key.toLowerCase() === 'z') {
                event.preventDefault();
                if (event.shiftKey) e.redo();
                else e.undo();
            } else if (mod && event.key.toLowerCase() === 'g') {
                event.preventDefault();
                if (event.repeat) return;
                if (e.group) e.ungroup();
                else if (e.selected.length > 1) e.groupPorts();
            } else if (
                mod &&
                event.key.toLowerCase() === 'd' &&
                e.selected.length
            ) {
                event.preventDefault();
                e.duplicate();
            } else if (
                mod &&
                event.key.toLowerCase() === 'a' &&
                context.current?.contains(event.target as Node)
            ) {
                event.preventDefault();
                e.setSelection({
                    kind: 'ports',
                    ids: e.ports.filter((p) => !p.hidden).map((p) => p.id),
                });
            } else if (
                event.key === 'Delete' &&
                (e.selected.length || e.selection.kind === 'image')
            ) {
                event.preventDefault();
                e.remove();
            } else if (
                event.key.startsWith('Arrow') &&
                e.selected.length &&
                !e.selected.some(e.portLocked)
            ) {
                event.preventDefault();
                const d = event.shiftKey ? 10 : 1;
                e.setPortList(
                    e.ports.map((p) =>
                        e.selection.ids.includes(p.id)
                            ? {
                                  ...p,
                                  x:
                                      p.x +
                                      (event.key === 'ArrowRight'
                                          ? d
                                          : event.key === 'ArrowLeft'
                                            ? -d
                                            : 0),
                                  y:
                                      p.y +
                                      (event.key === 'ArrowDown'
                                          ? d
                                          : event.key === 'ArrowUp'
                                            ? -d
                                            : 0),
                              }
                            : p,
                    ),
                );
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    });

    const inspectorPanel = e.loading ? (
        <Skeleton className="m-4 h-64" />
    ) : block ? (
        <BlockPanel
            options={block}
            onChange={setBlock}
            onInsert={insertBlock}
            onClose={() => setBlock(null)}
        />
    ) : numberMode ? (
        <NumberingPanel
            editor={e}
            mode={numberMode}
            options={numberOptions}
            setOptions={setNumberOptions}
            conflict={conflict}
            onApply={applyNumbering}
            onClose={closeNumbering}
            onFinish={() => void finishNumbering()}
        />
    ) : (
        <fieldset
            disabled={!!e.crop}
            className="h-full min-w-0 disabled:opacity-50"
        >
            <EditorInspector
                key={`${e.selection.kind}-${e.selected.map((p) => p.id).join(',')}`}
                editor={e}
                onImage={image}
                onClose={() => setInspector(false)}
            />
        </fieldset>
    );
    const inspectorTabsDisabled =
        e.loading ||
        e.saving ||
        !!e.session ||
        !!block ||
        !!e.crop ||
        !!numberMode;

    return (
        <>
            <Head title={`Editor · ${catalogModel.name}`} />
            <ErrorNotification error={e.error} />
            <div className="flex h-[calc(100dvh-5rem)] min-h-150 min-w-0 flex-col overflow-hidden">
                <header className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div className="flex min-w-0 flex-1 items-center gap-3">
                        <IconButton
                            label={returnTo ? 'Indietro' : 'Catalogo'}
                            variant="ghost"
                            asChild
                        >
                            <Link href={exitRoute}>
                                <ArrowLeft />
                            </Link>
                        </IconButton>
                        <div className="min-w-0 space-y-1">
                            <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                <PanelsTopLeft className="size-3.5" />
                                Editor modello
                            </p>
                            <div className="flex min-w-0 items-center gap-2">
                                <h1 className="truncate text-xl font-semibold tracking-tight sm:text-2xl">
                                    {catalogModel.name}
                                </h1>
                                <CatalogForm
                                    title="Rinomina modello"
                                    item={catalogModel}
                                    action={{
                                        action: renameUrl,
                                        method: 'post',
                                    }}
                                />
                            </div>
                            <p className="truncate text-sm text-muted-foreground">
                                {catalogModel.manufacturer.name}
                            </p>
                        </div>
                    </div>
                    <SaveButton
                        type="button"
                        processing={e.saving}
                        saved={
                            !e.dirty &&
                            !e.session &&
                            !e.loading &&
                            !e.loadFailed
                        }
                        disabled={saveDisabled}
                        onClick={() => {
                            if (e.session) void finishNumbering();
                            else void e.save();
                        }}
                    />
                </header>
                <EditorToolbar
                    editor={e}
                    blocked={blocked}
                    previewing={!!preview || !!block}
                    tool={tool}
                    setTool={setTool}
                    snap={snap}
                    setSnap={setSnap}
                    layers={layers}
                    setLayers={toggleLayers}
                    add={add}
                    image={image}
                    onBlock={openBlock}
                    onNumbering={openNumbering}
                />
                <div className="relative flex min-h-0 min-w-0 flex-1">
                    {layers && (
                        <aside
                            aria-label="Livelli della faccia"
                            className="absolute inset-y-0 left-0 z-10 w-80 max-w-[90%] border-r bg-background shadow-lg xl:relative xl:z-auto xl:shrink-0 xl:shadow-none"
                        >
                            <EditorLayers
                                editor={e}
                                onClose={() => setLayers(false)}
                                disabled={blocked || e.loading}
                            />
                        </aside>
                    )}
                    <div className="relative flex min-w-0 flex-1 flex-col">
                        <div className="flex items-center justify-between gap-2 border-b px-3 py-2">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={e.face}
                                disabled={e.loading || !!e.crop || !!block}
                                onValueChange={(value) => {
                                    if (!value) return;
                                    if (e.session) {
                                        setEndNumbering(true);
                                        return;
                                    }
                                    setNumberMode(null);
                                    e.setFace(value as 'front' | 'rear');
                                    e.setSelection({ kind: 'canvas', ids: [] });
                                    e.setEnteredGroup(null);
                                }}
                                aria-label="Faccia del pannello"
                            >
                                <ToggleGroupItem value="front" className="px-4">
                                    Frontale
                                </ToggleGroupItem>
                                <ToggleGroupItem value="rear" className="px-4">
                                    Retro
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <div className="flex items-center gap-1">
                                <Badge
                                    variant="secondary"
                                    className="hidden sm:inline-flex"
                                >
                                    {e.ports.length} porte
                                </Badge>
                                <IconButton
                                    aria-expanded={inspector}
                                    aria-controls="editor-inspector"
                                    label={
                                        inspector
                                            ? 'Chiudi pannello laterale'
                                            : 'Apri pannello laterale'
                                    }
                                    variant="ghost"
                                    onClick={() => toggleInspector(!inspector)}
                                >
                                    <PanelRight />
                                </IconButton>
                            </div>
                        </div>
                        {e.crop && (
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-background px-3 py-2">
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    <Crop className="size-4" />
                                    Ritaglio immagine
                                </span>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="ghost"
                                        onClick={() => e.finishCrop(false)}
                                    >
                                        <X />
                                        Annulla
                                    </Button>
                                    <Button onClick={() => e.finishCrop(true)}>
                                        <Check />
                                        Applica ritaglio
                                    </Button>
                                </div>
                            </div>
                        )}
                        {e.loading ? (
                            <Skeleton className="m-4 flex-1" />
                        ) : (
                            <ContextMenu
                                disabled={blocked}
                                open={contextOpen}
                                onOpenChange={(open) => {
                                    if (!blocked) setContextOpen(open);
                                }}
                            >
                                <ContextMenuTrigger
                                    ref={context}
                                    className="min-h-0 flex-1"
                                    onContextMenu={(event) => {
                                        if (blocked) {
                                            event.preventDefault();
                                            event.stopPropagation();
                                        }
                                    }}
                                >
                                    <EditorCanvas
                                        editor={e}
                                        pan={tool === 'pan'}
                                        snap={snap}
                                        preview={preview}
                                        blockPreview={blockPreview}
                                        blockOptions={block}
                                        onChangeBlock={setBlock}
                                        api={canvas}
                                        onZoom={setZoom}
                                        onEditLabel={label}
                                        onImageSelect={properties}
                                        onContext={(point) =>
                                            setContextPoint(point)
                                        }
                                    />
                                </ContextMenuTrigger>
                                <EditorContextMenu
                                    editor={e}
                                    add={(type) => add(type, contextPoint)}
                                    paste={() => {
                                        e.setPastePoint(contextPoint);
                                        e.paste();
                                    }}
                                    properties={properties}
                                    label={label}
                                    image={image}
                                    fit={fit}
                                />
                            </ContextMenu>
                        )}
                        <div className="flex items-center justify-end border-t bg-background px-3 py-2">
                            <div className="flex items-center gap-1">
                                <ZoomControls
                                    zoom={zoom}
                                    step={1.2}
                                    onZoom={(factor) => canvas.current?.zoom(factor)}
                                    onFit={fit}
                                    fitLabel="Adatta alla vista"
                                />
                            </div>
                        </div>
                    </div>
                    {inspector && (
                        <aside
                            id="editor-inspector"
                            aria-label="Proprietà del pannello"
                            className={cn(
                                'absolute inset-y-0 right-0 max-w-[95%] border-l bg-background shadow-lg lg:relative lg:max-w-none lg:shrink-0 lg:shadow-none',
                                (mikrotik || unifi) && inspectorTab === 'ports'
                                    ? 'w-96'
                                    : 'w-72',
                            )}
                        >
                            {mikrotik || unifi ? (
                                <Tabs
                                    value={inspectorTab}
                                    onValueChange={setInspectorTab}
                                    className="h-full min-h-0 gap-0"
                                >
                                    <TabsList
                                        className="m-3 self-stretch"
                                        aria-label="Pannello modello"
                                    >
                                        <TabsTrigger
                                            value="properties"
                                            disabled={inspectorTabsDisabled}
                                        >
                                            Proprietà
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="ports"
                                            disabled={inspectorTabsDisabled}
                                        >
                                            {unifi ? (
                                                <UnifiMark />
                                            ) : (
                                                <MikrotikMark />
                                            )}
                                            Porte
                                        </TabsTrigger>
                                    </TabsList>
                                    <TabsContent
                                        value="properties"
                                        className="min-h-0"
                                    >
                                        {inspectorPanel}
                                    </TabsContent>
                                    <TabsContent
                                        value="ports"
                                        className="min-h-0"
                                    >
                                        <IntegrationPortsPanel
                                            editor={e}
                                            model={catalogModel}
                                            vendor={unifi ? 'unifi' : 'mikrotik'}
                                        />
                                    </TabsContent>
                                </Tabs>
                            ) : (
                                inspectorPanel
                            )}
                        </aside>
                    )}
                </div>
                <footer className="flex items-center justify-between gap-3 border-t px-4 py-1.5 text-xs text-muted-foreground">
                    <span>
                        {`${catalogModel.manufacturer.name} · ${catalogModel.name}`}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setShortcuts(true)}
                    >
                        Scorciatoie
                    </Button>
                </footer>
            </div>
            <input
                ref={file}
                type="file"
                className="sr-only"
                aria-label="Importa immagine"
                accept="image/png,image/jpeg,image/webp"
                onChange={(event) => {
                    const selected = event.target.files?.[0];
                    if (selected) void e.importImage(selected);
                    event.target.value = '';
                }}
            />
            {editLabel && e.selected.length === 1 && (
                <LabelDialog
                    key={e.selected[0].id}
                    editor={e}
                    onClose={() => setEditLabel(false)}
                />
            )}
            {leave && !e.session && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !e.saving) setLeave(null);
                    }}
                >
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Salvare prima di uscire?</DialogTitle>
                            <DialogDescription>
                                Uscendo senza salvare, le modifiche di questo
                                pannello andranno perse.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                variant="destructive"
                                disabled={e.saving}
                                onClick={leave}
                            >
                                Scarta
                            </Button>
                            <SaveButton
                                type="button"
                                processing={e.saving}
                                disabled={saveDisabled}
                                onClick={() => void saveAndExit(leave)}
                            >
                                Salva ed Esci
                            </SaveButton>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
            <Dialog
                open={endNumbering}
                onOpenChange={(open) => {
                    if (!e.saving && !open) cancelEndNumbering();
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {leave
                                ? 'Salvare prima di uscire?'
                                : 'Terminare la numerazione?'}
                        </DialogTitle>
                        <DialogDescription>
                            {leave
                                ? 'Se scarti, le modifiche non salvate del pannello andranno perse.'
                                : `${e.session?.steps.length ?? 0} assegnazioni in questa sessione.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="flex-row flex-wrap justify-between sm:justify-between">
                        {!leave && (
                            <Button
                                variant="outline"
                                disabled={e.saving}
                                onClick={cancelEndNumbering}
                            >
                                Annulla
                            </Button>
                        )}
                        <div className="ml-auto flex gap-2">
                            <Button
                                variant="destructive"
                                disabled={e.saving}
                                onClick={() => void finishNumbering(true)}
                            >
                                <Trash2 data-icon="inline-start" />
                                Scarta
                            </Button>
                            <SaveButton
                                type="button"
                                processing={e.saving}
                                onClick={() => void finishNumbering()}
                            >
                                {leave ? 'Salva ed Esci' : 'Salva'}
                            </SaveButton>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog open={shortcuts} onOpenChange={setShortcuts}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>Scorciatoie dell’editor</DialogTitle>
                    </DialogHeader>
                    <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-3 text-sm">
                        {[
                            ['Aggiungi / rimuovi selezione', 'Ctrl/Cmd + clic'],
                            ['Duplica trascinando', 'Alt + trascina'],
                            ['Duplica selezione', 'Ctrl/Cmd + D'],
                            ['Copia selezione', 'Ctrl/Cmd + C'],
                            ['Taglia selezione', 'Ctrl/Cmd + X'],
                            ['Incolla al clic o con offset', 'Ctrl/Cmd + V'],
                            ['Raggruppa / sciogli gruppo', 'Ctrl/Cmd + G'],
                            ['Seleziona tutte le porte', 'Ctrl/Cmd + A'],
                            ['Sposta di 1 / 10', 'Frecce / Shift + frecce'],
                            [
                                'Sposta / ridimensiona con precisione',
                                'Ctrl + trascina',
                            ],
                            ['Elimina selezione', 'Canc'],
                            [
                                'Annulla / ripristina',
                                'Ctrl/Cmd + Z / Shift + Z',
                            ],
                            ['Salva', 'Ctrl/Cmd + S'],
                            [
                                'Sposta vista',
                                'Spazio / clic centrale + trascina',
                            ],
                            ['Conferma / annulla ritaglio', 'Invio / Esc'],
                            ['Menu contestuale', 'Shift + F10'],
                            ['Modifica etichetta', 'Doppio clic'],
                            ['Annulla ultima numerazione', 'Backspace'],
                            ['Termina numerazione', 'Esc'],
                        ].map(([name, shortcut]) => (
                            <div key={name} className="contents">
                                <dt>{name}</dt>
                                <dd className="text-right text-muted-foreground">
                                    {shortcut}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <DialogFooter>
                        <Button onClick={() => setShortcuts(false)}>
                            <Check />
                            Chiudi
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
