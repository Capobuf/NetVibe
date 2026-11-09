import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, Link2, Search } from 'lucide-react';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { useSiteContext } from '@/hooks/use-site-context';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Item,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import {
    confirm,
    records,
    sync as syncRoute,
} from '@/routes/companies/settings/integrations/snipe-it';
import type { Company, Site } from '@/types/company';

type ImportAsset = {
    id: number;
    name: string | null;
    serial: string | null;
    ip: string | null;
    model: string | null;
    problem: string | null;
    model_ambiguous: boolean;
    candidates: { id: number; name: string }[];
};

export type SnipeItImportData = {
    sync: { id: number; revision: number };
    assets: ImportAsset[];
};

// Choices that need a comparison are left to the Snipe-IT asset page.
function blocker(asset: ImportAsset) {
    return asset.candidates.length > 1
        ? `${asset.candidates.length} dispositivi corrispondenti`
        : asset.model_ambiguous
          ? 'Più modelli NetVibe corrispondenti'
          : null;
}

/** Sync action of the device list, followed by the choice of the Snipe-IT assets to import into the site. */
export function SnipeItImport({
    company,
    site,
    data,
}: {
    company: Company;
    site: Site;
    data?: SnipeItImportData | null;
}) {
    const context = useSiteContext(company, site);
    const [open, setOpen] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [importing, setImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [newIds, setNewIds] = useState<number[]>([]);
    const [selected, setSelected] = useState<number[]>([]);
    const [search, setSearch] = useState('');
    // Redirects after a visit omit the optional prop: keep the last list received.
    const [list, setList] = useState(data);
    if (data && data !== list) setList(data);
    const assets = list?.assets ?? [];
    const term = search.trim().toLowerCase();
    const shown = assets
        .filter(
            (asset) =>
                !term ||
                [asset.name, asset.serial, asset.ip, asset.model].some(
                    (value) => value?.toLowerCase().includes(term),
                ),
        )
        .sort(
            (a, b) =>
                Number(newIds.includes(b.id)) - Number(newIds.includes(a.id)),
        );
    const selectable = shown.filter((asset) => !blocker(asset));
    const chosen = assets.filter(
        (asset) => selected.includes(asset.id) && !blocker(asset),
    );
    const allShownSelected =
        selectable.length > 0 &&
        selectable.every((asset) => selected.includes(asset.id));

    function synchronize() {
        setOpen(true);
        setSyncing(true);
        setError(null);
        setSearch('');
        router.post(
            syncRoute.url(company.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFlash: (flash) => {
                    const ids =
                        (flash.snipeItNew as number[] | undefined) ?? [];
                    setNewIds(ids);
                    setSelected(ids);
                },
                onError: (errors) =>
                    setError(errors.sync ?? 'Sincronizzazione non riuscita.'),
                // Lists the assets also when the read fails, so earlier ones stay importable.
                onFinish: () =>
                    router.reload({
                        only: ['snipeItImport'],
                        onFinish: () => setSyncing(false),
                    }),
            },
        );
    }

    function importChosen() {
        if (!list) return;
        router.post(
            confirm.url(company.id),
            {
                sync_id: list.sync.id,
                revision: list.sync.revision,
                activate: false,
                decisions: chosen.map((asset) => ({
                    id: asset.id,
                    action: asset.candidates.length ? 'link' : 'create',
                    target_id: asset.candidates[0]?.id ?? null,
                    site_id: site.id,
                    model_id: null,
                    change_model: false,
                })),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setImporting(true);
                    setError(null);
                },
                onSuccess: () => {
                    setOpen(false);
                    setSelected([]);
                },
                onError: (errors) => {
                    setError(
                        Object.values(errors)[0] ??
                            'Importazione non riuscita.',
                    );
                    router.reload({ only: ['snipeItImport'] });
                },
                onFinish: () => setImporting(false),
            },
        );
    }

    function toggle(id: number, checked: boolean) {
        setSelected((previous) =>
            checked
                ? [...previous, id]
                : previous.filter((item) => item !== id),
        );
    }

    function toggleShown(checked: boolean) {
        const ids = selectable.map((asset) => asset.id);
        setSelected((previous) =>
            checked
                ? [...new Set([...previous, ...ids])]
                : previous.filter((id) => !ids.includes(id)),
        );
    }

    return (
        <>
            <Button
                variant="outline"
                disabled={syncing || importing}
                onClick={synchronize}
            >
                {syncing ? <Spinner /> : <SnipeItMark className="size-4" />}
                Sincronizza
            </Button>
            <Sheet
                open={open}
                onOpenChange={(value) => {
                    if (!importing) setOpen(value);
                }}
            >
                <SheetContent className="w-full gap-0 sm:max-w-md">
                    <SheetHeader className="border-b">
                        <SheetTitle className="flex items-center gap-2">
                            <SnipeItMark className="size-5" />
                            Importa da Snipe-IT
                        </SheetTitle>
                        <SheetDescription>{context}</SheetDescription>
                    </SheetHeader>
                    <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-4">
                        {error ? (
                            <Alert variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        ) : null}
                        {syncing ? (
                            <div
                                className="flex flex-1 items-center justify-center gap-2 text-sm text-muted-foreground"
                                role="status"
                            >
                                <Spinner />
                                Sincronizzazione in corso…
                            </div>
                        ) : !assets.length ? (
                            <Empty className="flex-1">
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Nessun asset da importare
                                    </EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <>
                                {assets.length > 8 ? (
                                    <InputGroup>
                                        <InputGroupAddon>
                                            <Search />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            value={search}
                                            onChange={(event) =>
                                                setSearch(event.target.value)
                                            }
                                            placeholder="Cerca nome, seriale, modello, IP"
                                            aria-label="Cerca asset Snipe-IT"
                                        />
                                    </InputGroup>
                                ) : null}
                                {selectable.length > 1 ? (
                                    <label className="flex items-center gap-3 px-4 text-sm text-muted-foreground">
                                        <Checkbox
                                            disabled={importing}
                                            checked={
                                                allShownSelected
                                                    ? true
                                                    : selectable.some((asset) =>
                                                            selected.includes(
                                                                asset.id,
                                                            ),
                                                        )
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            onCheckedChange={(checked) =>
                                                toggleShown(checked === true)
                                            }
                                        />
                                        Seleziona tutti
                                    </label>
                                ) : null}
                                <ItemGroup className="gap-2">
                                    {shown.map((asset) => (
                                        <AssetItem
                                            key={asset.id}
                                            asset={asset}
                                            isNew={newIds.includes(asset.id)}
                                            checked={selected.includes(
                                                asset.id,
                                            )}
                                            disabled={importing}
                                            onCheckedChange={(checked) =>
                                                toggle(asset.id, checked)
                                            }
                                        />
                                    ))}
                                    {!shown.length ? (
                                        <p className="py-6 text-center text-sm text-muted-foreground">
                                            Nessun asset trovato
                                        </p>
                                    ) : null}
                                </ItemGroup>
                            </>
                        )}
                    </div>
                    <SheetFooter className="flex-row flex-wrap border-t">
                        <Button
                            disabled={
                                syncing || importing || !chosen.length || !list
                            }
                            onClick={importChosen}
                        >
                            {importing ? <Spinner /> : null}
                            {chosen.length
                                ? `Importa (${chosen.length})`
                                : 'Importa'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={records(company.id)}>
                                Asset Snipe-IT
                            </Link>
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        </>
    );
}

function AssetItem({
    asset,
    isNew,
    checked,
    disabled,
    onCheckedChange,
}: {
    asset: ImportAsset;
    isNew: boolean;
    checked: boolean;
    disabled: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    const blocked = blocker(asset);
    const id = `snipe-it-import-${asset.id}`;

    return (
        <Item
            asChild
            size="sm"
            variant="outline"
            className={
                blocked
                    ? 'opacity-70'
                    : 'cursor-pointer has-data-[state=checked]:bg-muted/50'
            }
        >
            <label htmlFor={id}>
                <ItemMedia className="self-start pt-0.5">
                    <Checkbox
                        id={id}
                        disabled={!!blocked || disabled}
                        checked={!blocked && checked}
                        onCheckedChange={(value) =>
                            onCheckedChange(value === true)
                        }
                    />
                </ItemMedia>
                <ItemContent className="min-w-0">
                    <ItemTitle className="flex-wrap">
                        {asset.name ?? '—'}
                        {isNew ? <Badge variant="secondary">Nuovo</Badge> : null}
                    </ItemTitle>
                    <ItemDescription className="break-all">
                        {[asset.model, asset.serial, asset.ip]
                            .filter(Boolean)
                            .join(' · ') || '—'}
                    </ItemDescription>
                    {blocked ? (
                        <span className="text-xs text-muted-foreground">
                            {blocked}: scegli in Asset Snipe-IT
                        </span>
                    ) : asset.candidates.length ? (
                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Link2 className="size-3.5 shrink-0" />
                            Collega a {asset.candidates[0].name}
                        </span>
                    ) : null}
                    {asset.problem ? (
                        <span className="flex items-start gap-1.5 text-xs text-amber-600 dark:text-amber-500">
                            <AlertTriangle className="mt-px size-3.5 shrink-0" />
                            {asset.problem}
                        </span>
                    ) : null}
                </ItemContent>
            </label>
        </Item>
    );
}
