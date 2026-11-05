import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertTriangle,
    ArrowUpRight,
    Ban,
    Download,
    Link2,
    MoreHorizontal,
    RefreshCw,
    RotateCcw,
} from 'lucide-react';
import { IntegrationTabs } from '@/components/integration-tabs';
import { SnipeItTabs } from '@/components/snipe-it-tabs';
import { SnipeItModelCell } from '@/components/devices/snipe-it-model-cell';
import {
    SnipeItPicker,
    type SnipeOption,
} from '@/components/devices/snipe-it-picker';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { TablePagination } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { TableSortHead } from '@/components/table-sort-head';
import TextLink from '@/components/text-link';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import type { TableSort } from '@/lib/table-sort';
import { formatDateTime } from '@/lib/utils';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Combobox,
    ComboboxInput,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    bulk,
    confirm,
    records,
    sync as refresh,
} from '@/routes/companies/settings/integrations/snipe-it';
import type { Company, Site } from '@/types/company';

type View = 'pending' | 'linked' | 'ipam' | 'excluded';
type Column = 'name' | 'category' | 'model' | 'serial' | 'ip' | 'local';
type Row = {
    id: number;
    snapshot: {
        name: string | null;
        serial?: string | null;
        asset_tag?: string | null;
        network?: { ip: string | null; mac: string | null };
    };
    state: View;
    detached: boolean;
    url: string | null;
    ipam_url: string | null;
    problem: string | null;
    present: boolean;
    local: SnipeOption | null;
    candidates: SnipeOption[];
    model: {
        name: string | null;
        manufacturer: string | null;
        category: string | null;
    } | null;
    model_local: SnipeOption | null;
    model_linked: boolean;
    model_ambiguous: boolean;
    model_problem: string | null;
};
type Decision = {
    id: number;
    action: 'link' | 'create' | 'exclude' | 'restore';
    model_id: number | null;
    change_model: boolean;
    target_id: number | null;
    site_id: number | null;
};
type Props = {
    company: Company;
    sync: {
        id: number;
        revision: number;
        activated_at: string | null;
        synced_at: string | null;
        error: string | null;
    } | null;
    active: boolean;
    view: View;
    filters: {
        q?: string;
        problem?: string | boolean | null;
        category?: string | null;
        sort?: Column;
        direction?: 'asc' | 'desc';
        per_page?: number;
    };
    counts: Record<View | 'problems', number> & {
        problems_by_view: Partial<Record<View, number>>;
    };
    categories: { name: string | null; total: number }[];
    rows: {
        data: Row[];
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
        from: number | null;
        to: number | null;
    };
    sites: Site[];
};

const views: { value: View; label: string }[] = [
    { value: 'pending', label: 'Da decidere' },
    { value: 'linked', label: 'Apparati' },
    { value: 'ipam', label: 'Client IPAM' },
    { value: 'excluded', label: 'Esclusi' },
];
// Must match SnipeItSyncController::NO_CATEGORY.
const NO_CATEGORY = '__none';
const ALL_CATEGORIES = '__all';

function assetName(row: Row) {
    return row.snapshot.name || row.snapshot.asset_tag || '—';
}

function SitePicker({
    sites,
    value,
    onChange,
    disabled,
}: {
    sites: Site[];
    value: number | null;
    onChange: (id: number | null) => void;
    disabled?: boolean;
}) {
    return (
        <Combobox
            items={sites}
            value={sites.find((site) => site.id === value) ?? null}
            onValueChange={(site: Site | null) => onChange(site?.id ?? null)}
            itemToStringLabel={(site: Site) => site.name ?? ''}
            isItemEqualToValue={(a: Site, b: Site) => a.id === b.id}
            disabled={disabled}
        >
            <ComboboxInput id="asset-site" placeholder="Scegli la sede…" />
            <ComboboxContent>
                <ComboboxEmpty>Nessuna sede trovata</ComboboxEmpty>
                <ComboboxList>
                    {(site: Site) => (
                        <ComboboxItem key={site.id} value={site}>
                            {site.name}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}

function defaultDecision(row: Row, sites: Site[]): Decision {
    return {
        id: row.id,
        action:
            row.state === 'excluded'
                ? 'restore'
                : row.candidates.length
                  ? 'link'
                  : 'create',
        target_id: row.candidates.length === 1 ? row.candidates[0].id : null,
        site_id: sites.length === 1 ? sites[0].id : null,
        model_id: null,
        change_model: false,
    };
}

export default function SnipeItRecords(props: Props) {
    // The search text is excluded so typing keeps focus; other changes reset local choices.
    return (
        <Records
            key={`${props.view}:${props.rows.current_page}:${props.filters.category}:${props.filters.problem}:${props.filters.sort}:${props.filters.direction}:${props.rows.per_page}:${props.sync?.revision}`}
            {...props}
        />
    );
}

function Records({
    company,
    sync,
    active,
    view,
    filters,
    counts,
    categories,
    rows,
    sites,
}: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [selected, setSelected] = useState<number[]>([]);
    const [allMatching, setAllMatching] = useState(false);
    const [openRow, setOpenRow] = useState<number | null>(null);
    const [draft, setDraft] = useState<Decision | null>(null);
    const [target, setTarget] = useState<SnipeOption | null>(null);
    const [model, setModel] = useState<SnipeOption | null>(null);
    const [pendingBulk, setPendingBulk] = useState<
        'create' | 'link' | 'exclude' | 'restore' | null
    >(null);
    const [bulkSite, setBulkSite] = useState<number | null>(
        sites.length === 1 ? sites[0].id : null,
    );
    const form = useForm({
        sync_id: sync?.id ?? 0,
        revision: sync?.revision ?? 0,
        decisions: [] as Decision[],
        activate: false,
    });
    const massForm = useForm({
        sync_id: sync?.id ?? 0,
        revision: sync?.revision ?? 0,
        view,
        action: 'exclude' as 'exclude' | 'restore',
        q: filters.q ?? null,
        problem: filters.problem ? 1 : null,
        category: filters.category ?? null,
    });
    const acquire = useForm({});
    const busy = form.processing || massForm.processing || acquire.processing;
    const multipleSites = sites.length > 1;
    const editable = active && !busy;
    const problemOnly = !!filters.problem && filters.problem !== '0';
    const defaults: TableColumn<Column>[] = [
        { id: 'name', label: 'Asset', visible: true },
        { id: 'category', label: 'Tipo', visible: true },
        { id: 'model', label: 'Modello', visible: true },
        { id: 'serial', label: 'Seriale', visible: true },
        { id: 'ip', label: 'IP / MAC', visible: true },
        { id: 'local', label: 'In NetVibe', visible: true },
    ];
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences('snipe-it-assets', defaults);
    const visible = columns.filter((column) => column.visible);
    const selectable = rows.data.filter(
        (row) => row.state !== 'linked' && row.present,
    );
    const selectedRows = rows.data.filter((row) => selected.includes(row.id));
    const linkable = selectedRows.filter((row) => row.candidates.length === 1);
    const sort: TableSort<Column> =
        filters.sort && filters.direction
            ? { column: filters.sort, direction: filters.direction }
            : null;
    const current = rows.data.find((row) => row.id === openRow) ?? null;
    const error = [
        ...Object.values(form.errors),
        ...Object.values(massForm.errors),
        ...Object.values(acquire.errors),
        sync?.error,
    ].find(
        (message): message is string =>
            typeof message === 'string' && message.length > 0,
    );
    const pageSelected =
        selectable.length > 0 &&
        selectable.every((row) => selected.includes(row.id));
    const bulkCount = allMatching ? rows.total : selected.length;

    function visit(changes: Record<string, string | number | null>) {
        setSelected([]);
        setAllMatching(false);
        router.get(
            records.url(company.id),
            {
                view,
                ...filters,
                q: search || null,
                problem: problemOnly ? 1 : null,
                page: 1,
                ...changes,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function submit(decisions: Decision[], onSuccess?: () => void) {
        form.transform((data) => ({ ...data, decisions }));
        form.submit(confirm(company.id), {
            preserveScroll: true,
            onSuccess: () => {
                setSelected([]);
                setPendingBulk(null);
                onSuccess?.();
            },
            onError: () => setPendingBulk(null),
        });
    }

    function openSheet(row: Row, action?: Decision['action']) {
        const decision = defaultDecision(row, sites);
        setOpenRow(row.id);
        setDraft(action ? { ...decision, action } : decision);
        setTarget(
            row.candidates.find((item) => item.id === decision.target_id) ??
                null,
        );
        setModel(null);
    }

    function applyBulk() {
        if (!pendingBulk) return;
        if (
            allMatching &&
            (pendingBulk === 'exclude' || pendingBulk === 'restore')
        ) {
            massForm.transform((data) => ({ ...data, action: pendingBulk }));
            massForm.submit(bulk(company.id), {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setAllMatching(false);
                    setPendingBulk(null);
                },
                onError: () => setPendingBulk(null),
            });
            return;
        }
        const source = pendingBulk === 'link' ? linkable : selectedRows;
        submit(
            source.map((row) => ({
                ...defaultDecision(row, sites),
                action: pendingBulk,
                ...(pendingBulk === 'create' ? { site_id: bulkSite } : {}),
            })),
        );
    }

    function rowMenu(row: Row) {
        const name = assetName(row);
        return (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Azioni per ${name}`}
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuGroup>
                        {(row.state === 'pending' || row.state === 'ipam') &&
                        row.present ? (
                            <>
                                <DropdownMenuItem
                                    disabled={!editable}
                                    onSelect={() => openSheet(row, 'create')}
                                >
                                    <Download />
                                    Importa come apparato…
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    disabled={!editable}
                                    onSelect={() => openSheet(row, 'link')}
                                >
                                    <Link2 />
                                    Collega a dispositivo esistente…
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    disabled={!editable}
                                    onSelect={() =>
                                        submit([
                                            {
                                                ...defaultDecision(row, sites),
                                                action: 'exclude',
                                            },
                                        ])
                                    }
                                >
                                    <Ban />
                                    Escludi
                                </DropdownMenuItem>
                            </>
                        ) : null}
                        {row.state === 'excluded' ? (
                            <DropdownMenuItem
                                disabled={!editable}
                                onSelect={() =>
                                    submit([defaultDecision(row, sites)])
                                }
                            >
                                <RotateCcw />
                                Riammetti
                            </DropdownMenuItem>
                        ) : null}
                        {row.local?.url ? (
                            <DropdownMenuItem asChild>
                                <Link href={row.local.url}>
                                    <ArrowUpRight />
                                    Apri dispositivo
                                </Link>
                            </DropdownMenuItem>
                        ) : null}
                    </DropdownMenuGroup>
                    {row.url ? (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild>
                                <a
                                    href={row.url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ArrowUpRight />
                                    Apri in Snipe-IT
                                </a>
                            </DropdownMenuItem>
                        </>
                    ) : null}
                </DropdownMenuContent>
            </DropdownMenu>
        );
    }

    function netvibeCell(row: Row) {
        if (row.local)
            return (
                <div className="flex flex-col gap-0.5">
                    <TextLink href={row.local.url!}>{row.local.name}</TextLink>
                    {row.local.detail ? (
                        <span className="text-xs text-muted-foreground">
                            {row.local.detail}
                        </span>
                    ) : null}
                </div>
            );
        if (row.state === 'excluded')
            return <span className="text-muted-foreground">Escluso</span>;
        const client = row.ipam_url ? (
            <TextLink href={row.ipam_url}>Client nell’IPAM</TextLink>
        ) : null;
        if (row.candidates.length)
            return (
                <div className="flex flex-col gap-0.5">
                    {client}
                    <button
                        type="button"
                        className="flex items-center gap-1.5 text-left text-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        onClick={() => openSheet(row, 'link')}
                        disabled={!editable}
                    >
                        <Link2 className="size-3.5 shrink-0" />
                        {row.candidates.length === 1
                            ? `Possibile: ${row.candidates[0].name}`
                            : `${row.candidates.length} possibili corrispondenze`}
                    </button>
                </div>
            );
        return client ?? <span className="text-muted-foreground">—</span>;
    }

    function cell(row: Row, column: Column) {
        if (column === 'name')
            return (
                <div className="flex items-start gap-2">
                    <button
                        type="button"
                        className="flex flex-col items-start text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        onClick={() => openSheet(row)}
                    >
                        <span className="font-medium hover:text-primary">
                            {assetName(row)}
                        </span>
                        {row.snapshot.asset_tag &&
                        row.snapshot.asset_tag !== row.snapshot.name ? (
                            <span className="text-xs text-muted-foreground">
                                {row.snapshot.asset_tag}
                            </span>
                        ) : null}
                    </button>
                    {row.problem || row.model_problem || !row.present ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <AlertTriangle
                                    className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-500"
                                    aria-label={
                                        row.problem ??
                                        row.model_problem ??
                                        'Non più presente in Snipe-IT'
                                    }
                                />
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs">
                                {row.problem ??
                                    row.model_problem ??
                                    'Non più presente in Snipe-IT'}
                            </TooltipContent>
                        </Tooltip>
                    ) : null}
                </div>
            );
        if (column === 'category')
            return row.model?.category ? (
                row.model.category
            ) : (
                <span className="text-muted-foreground">Senza tipo</span>
            );
        if (column === 'model')
            return (
                <div className="flex flex-col gap-0.5">
                    <span>{row.model?.name || '—'}</span>
                    {row.model?.manufacturer ? (
                        <span className="text-xs text-muted-foreground">
                            {row.model.manufacturer}
                        </span>
                    ) : null}
                </div>
            );
        if (column === 'serial')
            return (
                <span className="tabular-nums">
                    {row.snapshot.serial || '—'}
                </span>
            );
        if (column === 'ip')
            return (
                <div className="flex flex-col gap-0.5 tabular-nums">
                    <span
                        className={
                            row.snapshot.network?.ip
                                ? undefined
                                : 'text-muted-foreground'
                        }
                    >
                        {row.snapshot.network?.ip || 'Senza IP'}
                    </span>
                    {row.snapshot.network?.mac ? (
                        <span className="text-xs text-muted-foreground">
                            {row.snapshot.network.mac}
                        </span>
                    ) : null}
                </div>
            );
        return netvibeCell(row);
    }

    const bulkLabels = {
        create: `Importare ${bulkCount} asset come apparati?`,
        link: `Collegare ${linkable.length} asset ai dispositivi proposti?`,
        exclude: `Escludere ${bulkCount} asset?`,
        restore: `Riammettere ${bulkCount} asset?`,
    };
    const bulkDescriptions = {
        create: 'Verranno creati come dispositivi in NetVibe con nome, seriale e modello di Snipe-IT.',
        link: 'Nome, seriale e modello dei dispositivi NetVibe saranno aggiornati con i dati di Snipe-IT.',
        exclude:
            'Non compariranno più tra gli asset da decidere. Puoi riammetterli in qualsiasi momento.',
        restore: 'Torneranno tra gli asset da decidere.',
    };
    const selectedNames = (pendingBulk === 'link' ? linkable : selectedRows)
        .slice(0, 6)
        .map(assetName);

    return (
        <>
            <Head title={`Asset Snipe-IT · ${company.name}`} />
            <div className="flex min-w-0 flex-col gap-4 p-4 md:p-6">
                <IntegrationTabs value="snipe-it" company={company}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <SnipeItTabs
                            company={company.id}
                            value="assets"
                            pending={counts.pending}
                        />
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="text-sm text-muted-foreground tabular-nums">
                                {sync?.synced_at
                                    ? `Ultima lettura ${formatDateTime(sync.synced_at)}`
                                    : 'Mai sincronizzata'}
                            </span>
                            <Button
                                variant="outline"
                                disabled={busy || !sync || !active}
                                onClick={() =>
                                    acquire.submit(refresh(company.id), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {acquire.processing ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw data-icon="inline-start" />
                                )}
                                Sincronizza ora
                            </Button>
                        </div>
                    </div>
                    {sync && !active ? (
                        <Alert>
                            <AlertDescription>
                                Sincronizzazione sospesa. Riconferma l’azienda
                                in Configurazione.
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    {error ? (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    ) : null}
                    <Tabs
                        value={view}
                        onValueChange={(value) =>
                            visit({
                                view: value,
                                category: null,
                                problem: null,
                            })
                        }
                    >
                        <TabsList aria-label="Destinazione degli asset">
                            {views.map((item) => (
                                <TabsTrigger
                                    key={item.value}
                                    value={item.value}
                                >
                                    {counts.problems_by_view[item.value] ? (
                                        <AlertTriangle
                                            className="text-amber-600 dark:text-amber-500"
                                            aria-label="Contiene asset da verificare"
                                        />
                                    ) : null}
                                    {item.label}
                                    <Badge
                                        variant={
                                            item.value === 'pending' &&
                                            counts.pending
                                                ? 'default'
                                                : 'secondary'
                                        }
                                        className="tabular-nums"
                                    >
                                        {counts[item.value]}
                                    </Badge>
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                    <Card className="gap-0 overflow-hidden py-0">
                        <CardHeader className="gap-3 py-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <TableSearch
                                    value={search}
                                    maxLength={255}
                                    total={counts[view]}
                                    label="Cerca nome, seriale, modello, IP, MAC"
                                    onChange={(value) => {
                                        setSearch(value);
                                        visit({ q: value || null });
                                    }}
                                />
                                <Select
                                    value={filters.category ?? ALL_CATEGORIES}
                                    onValueChange={(value) =>
                                        visit({
                                            category:
                                                value === ALL_CATEGORIES
                                                    ? null
                                                    : value,
                                        })
                                    }
                                    disabled={busy}
                                >
                                    <SelectTrigger
                                        aria-label="Filtra per tipo"
                                        className="w-52"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            <SelectItem value={ALL_CATEGORIES}>
                                                Tutti i tipi
                                            </SelectItem>
                                        </SelectGroup>
                                        <SelectSeparator />
                                        <SelectGroup>
                                            {categories.map((category) => (
                                                <SelectItem
                                                    key={
                                                        category.name ??
                                                        NO_CATEGORY
                                                    }
                                                    value={
                                                        category.name ??
                                                        NO_CATEGORY
                                                    }
                                                >
                                                    {category.name ??
                                                        'Senza tipo'}
                                                    <span className="ml-auto text-muted-foreground tabular-nums">
                                                        {category.total}
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                {counts.problems_by_view[view] ||
                                problemOnly ? (
                                    <Toggle
                                        variant="outline"
                                        pressed={problemOnly}
                                        onPressedChange={(pressed) =>
                                            visit({
                                                problem: pressed ? 1 : null,
                                            })
                                        }
                                        disabled={busy}
                                    >
                                        <AlertTriangle />
                                        Da verificare
                                        <span className="tabular-nums">
                                            {counts.problems_by_view[view] ?? 0}
                                        </span>
                                    </Toggle>
                                ) : null}
                            </div>
                            {selected.length ? (
                                <div
                                    className="flex flex-wrap items-center gap-2 rounded-lg bg-muted/60 px-3 py-2"
                                    role="region"
                                    aria-label="Azioni sugli asset selezionati"
                                >
                                    <span
                                        className="text-sm font-medium tabular-nums"
                                        role="status"
                                    >
                                        {allMatching
                                            ? `Tutti i ${rows.total} asset selezionati`
                                            : `${selected.length} selezionati`}
                                    </span>
                                    {pageSelected &&
                                    !allMatching &&
                                    rows.total > selected.length &&
                                    view !== 'linked' ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-primary"
                                            onClick={() => setAllMatching(true)}
                                        >
                                            Seleziona tutti i {rows.total}
                                        </Button>
                                    ) : null}
                                    <div className="ml-auto flex flex-wrap items-center gap-2">
                                        {view === 'excluded' ? (
                                            <Button
                                                size="sm"
                                                disabled={!editable}
                                                onClick={() =>
                                                    setPendingBulk('restore')
                                                }
                                            >
                                                <RotateCcw data-icon="inline-start" />
                                                Riammetti
                                            </Button>
                                        ) : (
                                            <>
                                                {!allMatching ? (
                                                    <Button
                                                        size="sm"
                                                        disabled={!editable}
                                                        onClick={() =>
                                                            setPendingBulk(
                                                                'create',
                                                            )
                                                        }
                                                    >
                                                        <Download data-icon="inline-start" />
                                                        Importa come apparati
                                                    </Button>
                                                ) : null}
                                                {!allMatching &&
                                                linkable.length ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={!editable}
                                                        onClick={() =>
                                                            setPendingBulk(
                                                                'link',
                                                            )
                                                        }
                                                    >
                                                        <Link2 data-icon="inline-start" />
                                                        Collega ai proposti (
                                                        {linkable.length})
                                                    </Button>
                                                ) : null}
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={!editable}
                                                    onClick={() =>
                                                        setPendingBulk(
                                                            'exclude',
                                                        )
                                                    }
                                                >
                                                    <Ban data-icon="inline-start" />
                                                    Escludi
                                                </Button>
                                            </>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setSelected([]);
                                                setAllMatching(false);
                                            }}
                                        >
                                            Annulla selezione
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </CardHeader>
                        <CardContent className="px-0">
                            <Table
                                className="text-left data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                                data-separators={separators}
                                aria-label="Asset Snipe-IT"
                            >
                                <TableHeader className="bg-muted/50">
                                    <TableRow>
                                        <TableHead className="w-12" scope="col">
                                            <Checkbox
                                                aria-label="Seleziona la pagina"
                                                disabled={
                                                    !selectable.length ||
                                                    !editable
                                                }
                                                checked={
                                                    pageSelected
                                                        ? true
                                                        : selected.length
                                                          ? 'indeterminate'
                                                          : false
                                                }
                                                onCheckedChange={(checked) => {
                                                    setAllMatching(false);
                                                    setSelected(
                                                        checked
                                                            ? selectable.map(
                                                                  (row) =>
                                                                      row.id,
                                                              )
                                                            : [],
                                                    );
                                                }}
                                            />
                                        </TableHead>
                                        {visible.map((column) => (
                                            <TableSortHead
                                                key={column.id}
                                                column={column}
                                                sort={sort}
                                                onSort={(value) =>
                                                    visit({
                                                        sort:
                                                            value?.column ??
                                                            null,
                                                        direction:
                                                            value?.direction ??
                                                            null,
                                                    })
                                                }
                                            />
                                        ))}
                                        <TableHead className="w-12 text-right">
                                            <TableColumns
                                                columns={columns}
                                                onChange={setColumns}
                                                separators={separators}
                                                onSeparatorsChange={
                                                    setSeparators
                                                }
                                            />
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.data.map((row) => (
                                        <TableRow
                                            key={row.id}
                                            data-state={
                                                selected.includes(row.id)
                                                    ? 'selected'
                                                    : undefined
                                            }
                                            className={
                                                row.present
                                                    ? undefined
                                                    : 'opacity-60'
                                            }
                                        >
                                            <TableCell>
                                                <Checkbox
                                                    aria-label={`Seleziona ${assetName(row)}`}
                                                    disabled={
                                                        row.state ===
                                                            'linked' ||
                                                        !row.present ||
                                                        !editable
                                                    }
                                                    checked={selected.includes(
                                                        row.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) => {
                                                        setAllMatching(false);
                                                        setSelected(
                                                            (previous) =>
                                                                checked
                                                                    ? [
                                                                          ...previous,
                                                                          row.id,
                                                                      ]
                                                                    : previous.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              row.id,
                                                                      ),
                                                        );
                                                    }}
                                                />
                                            </TableCell>
                                            {visible.map((column) => (
                                                <TableCell
                                                    key={column.id}
                                                    className="max-w-xs py-2.5 align-top break-words whitespace-normal"
                                                >
                                                    {cell(row, column.id)}
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-right align-top">
                                                {rowMenu(row)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {!rows.data.length ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={visible.length + 2}
                                                className="h-40 text-center whitespace-normal"
                                            >
                                                <Empty>
                                                    <EmptyHeader>
                                                        <EmptyTitle>
                                                            {!sync
                                                                ? 'Snipe-IT non configurato per questa azienda'
                                                                : !sync.synced_at
                                                                  ? 'Sincronizza per caricare gli asset'
                                                                  : search ||
                                                                      filters.category ||
                                                                      problemOnly
                                                                    ? 'Nessun asset trovato'
                                                                    : view ===
                                                                        'pending'
                                                                      ? 'Nessun asset da decidere'
                                                                      : 'Nessun asset in questa vista'}
                                                        </EmptyTitle>
                                                        {search ||
                                                        filters.category ||
                                                        problemOnly ? (
                                                            <EmptyDescription>
                                                                Modifica la
                                                                ricerca o i
                                                                filtri.
                                                            </EmptyDescription>
                                                        ) : null}
                                                    </EmptyHeader>
                                                </Empty>
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                </TableBody>
                            </Table>
                        </CardContent>
                        {rows.total > 10 ? (
                            <TablePagination
                                summary={`${rows.from ?? 0}–${rows.to ?? 0} di ${rows.total} asset`}
                                page={rows.current_page}
                                pageCount={rows.last_page}
                                pageSize={rows.per_page}
                                onPageChange={(page) => visit({ page })}
                                onPageSizeChange={(per_page) =>
                                    visit({ per_page })
                                }
                            />
                        ) : null}
                    </Card>
                </IntegrationTabs>
            </div>

            <Sheet
                open={current !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setOpenRow(null);
                }}
            >
                {current && draft ? (
                    <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                        <SheetHeader>
                            <SheetTitle>{assetName(current)}</SheetTitle>
                            <SheetDescription>
                                {[
                                    current.model?.category ?? 'Senza tipo',
                                    [
                                        current.model?.manufacturer,
                                        current.model?.name,
                                    ]
                                        .filter(Boolean)
                                        .join(' '),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </SheetDescription>
                        </SheetHeader>
                        <div className="flex flex-col gap-6 px-4">
                            {current.problem ||
                            current.model_problem ||
                            !current.present ? (
                                <Alert>
                                    <AlertTriangle />
                                    <AlertDescription>
                                        {current.problem ??
                                            current.model_problem ??
                                            'Non più presente in Snipe-IT'}
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border text-sm">
                                <div className="flex items-center justify-between gap-2 bg-muted px-3 py-2 font-medium">
                                    Snipe-IT
                                    {current.url ? (
                                        <a
                                            href={current.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            aria-label="Apri in Snipe-IT (nuova scheda)"
                                        >
                                            <ArrowUpRight className="size-4" />
                                        </a>
                                    ) : null}
                                </div>
                                <div className="bg-muted px-3 py-2 font-medium">
                                    NetVibe
                                </div>
                                {(
                                    [
                                        [
                                            'Nome',
                                            assetName(current),
                                            current.local?.name ??
                                                (current.state === 'ipam'
                                                    ? 'Client nell’IPAM'
                                                    : '—'),
                                        ],
                                        [
                                            'Seriale',
                                            current.snapshot.serial,
                                            current.local ? undefined : '—',
                                        ],
                                        [
                                            'Modello',
                                            current.model?.name,
                                            current.local?.model?.name ??
                                                current.model_local?.name ??
                                                '—',
                                        ],
                                        [
                                            'IP',
                                            current.snapshot.network?.ip,
                                            current.ipam_url ? (
                                                <TextLink
                                                    href={current.ipam_url}
                                                >
                                                    {
                                                        current.snapshot.network
                                                            ?.ip
                                                    }
                                                </TextLink>
                                            ) : (
                                                '—'
                                            ),
                                        ],
                                        [
                                            'MAC',
                                            current.snapshot.network?.mac,
                                            current.local ||
                                            current.state === 'ipam'
                                                ? undefined
                                                : '—',
                                        ],
                                    ] as const
                                ).map(([label, external, local]) => (
                                    <div key={label} className="contents">
                                        <div className="flex flex-col bg-background px-3 py-2">
                                            <span className="text-xs text-muted-foreground">
                                                {label}
                                            </span>
                                            <span className="break-all">
                                                {external || '—'}
                                            </span>
                                        </div>
                                        <div className="flex flex-col bg-background px-3 py-2">
                                            <span className="text-xs text-muted-foreground">
                                                {label}
                                            </span>
                                            <span className="break-all">
                                                {local ?? (external || '—')}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {(current.state === 'pending' ||
                                current.state === 'ipam') &&
                            current.present ? (
                                <div className="flex flex-col gap-4">
                                    <Field>
                                        <FieldLabel id="asset-action-label">
                                            In NetVibe
                                        </FieldLabel>
                                        <ToggleGroup
                                            type="single"
                                            variant="outline"
                                            aria-labelledby="asset-action-label"
                                            value={draft.action}
                                            onValueChange={(value) => {
                                                if (value)
                                                    setDraft({
                                                        ...draft,
                                                        action: value as Decision['action'],
                                                        change_model: false,
                                                    });
                                            }}
                                            disabled={!editable}
                                            className="w-full"
                                        >
                                            <ToggleGroupItem
                                                value="create"
                                                className="flex-1"
                                            >
                                                <Download />
                                                Importa come apparato
                                            </ToggleGroupItem>
                                            <ToggleGroupItem
                                                value="link"
                                                className="flex-1"
                                            >
                                                <Link2 />
                                                Collega a esistente
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                    </Field>
                                    {draft.action === 'link' ? (
                                        <Field>
                                            <FieldLabel>
                                                Dispositivo NetVibe
                                            </FieldLabel>
                                            {current.candidates.length ? (
                                                <div className="flex flex-col gap-2">
                                                    {current.candidates.map(
                                                        (candidate) => (
                                                            <Button
                                                                key={
                                                                    candidate.id
                                                                }
                                                                type="button"
                                                                variant={
                                                                    target?.id ===
                                                                    candidate.id
                                                                        ? 'secondary'
                                                                        : 'outline'
                                                                }
                                                                aria-pressed={
                                                                    target?.id ===
                                                                    candidate.id
                                                                }
                                                                className="h-auto justify-start py-2 text-left"
                                                                onClick={() => {
                                                                    setTarget(
                                                                        candidate,
                                                                    );
                                                                    setDraft({
                                                                        ...draft,
                                                                        target_id:
                                                                            candidate.id,
                                                                        change_model: false,
                                                                    });
                                                                }}
                                                            >
                                                                <span className="flex flex-col">
                                                                    <span>
                                                                        {
                                                                            candidate.name
                                                                        }
                                                                    </span>
                                                                    <span className="text-xs font-normal text-muted-foreground">
                                                                        {
                                                                            candidate.detail
                                                                        }
                                                                    </span>
                                                                </span>
                                                            </Button>
                                                        ),
                                                    )}
                                                </div>
                                            ) : null}
                                            <SnipeItPicker
                                                company={company.id}
                                                kind="devices"
                                                label={`Cerca un dispositivo NetVibe per ${assetName(current)}`}
                                                disabled={!editable}
                                                value={target}
                                                onChange={(value) => {
                                                    setTarget(value);
                                                    setDraft({
                                                        ...draft,
                                                        target_id:
                                                            value?.id ?? null,
                                                        change_model: false,
                                                    });
                                                }}
                                            />
                                        </Field>
                                    ) : multipleSites ? (
                                        <Field>
                                            <FieldLabel htmlFor="asset-site">
                                                Sede
                                            </FieldLabel>
                                            <SitePicker
                                                sites={sites}
                                                value={draft.site_id}
                                                disabled={!editable}
                                                onChange={(site_id) =>
                                                    setDraft({
                                                        ...draft,
                                                        site_id,
                                                    })
                                                }
                                            />
                                        </Field>
                                    ) : null}
                                    <SnipeItModelCell
                                        company={company.id}
                                        row={current}
                                        name={assetName(current)}
                                        target={
                                            draft.action === 'link'
                                                ? target
                                                : null
                                        }
                                        model={model ?? current.model_local}
                                        modelChosen={!!model}
                                        editable
                                        changeModel={draft.change_model}
                                        disabled={!editable}
                                        onModelChange={(value) => {
                                            setModel(value);
                                            setDraft({
                                                ...draft,
                                                model_id: value?.id ?? null,
                                                change_model: false,
                                            });
                                        }}
                                        onChangeModelChange={(change_model) =>
                                            setDraft({
                                                ...draft,
                                                change_model,
                                            })
                                        }
                                    />
                                </div>
                            ) : null}
                        </div>
                        <SheetFooter className="flex-row flex-wrap">
                            {(current.state === 'pending' ||
                                current.state === 'ipam') &&
                            current.present ? (
                                <>
                                    <Button
                                        disabled={
                                            !editable ||
                                            (draft.action === 'link' &&
                                                !draft.target_id) ||
                                            (draft.action === 'create' &&
                                                !draft.site_id)
                                        }
                                        onClick={() =>
                                            submit([draft], () =>
                                                setOpenRow(null),
                                            )
                                        }
                                    >
                                        {form.processing ? <Spinner /> : null}
                                        {draft.action === 'link'
                                            ? 'Collega'
                                            : 'Importa'}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={!editable}
                                        onClick={() =>
                                            submit(
                                                [
                                                    {
                                                        ...draft,
                                                        action: 'exclude',
                                                    },
                                                ],
                                                () => setOpenRow(null),
                                            )
                                        }
                                    >
                                        <Ban data-icon="inline-start" />
                                        Escludi
                                    </Button>
                                </>
                            ) : current.state === 'excluded' ? (
                                <Button
                                    disabled={!editable}
                                    onClick={() =>
                                        submit(
                                            [defaultDecision(current, sites)],
                                            () => setOpenRow(null),
                                        )
                                    }
                                >
                                    <RotateCcw data-icon="inline-start" />
                                    Riammetti
                                </Button>
                            ) : current.local?.url ? (
                                <Button asChild variant="outline">
                                    <Link href={current.local.url}>
                                        Apri dispositivo
                                        <ArrowUpRight data-icon="inline-end" />
                                    </Link>
                                </Button>
                            ) : null}
                        </SheetFooter>
                    </SheetContent>
                ) : null}
            </Sheet>

            <Dialog
                open={pendingBulk !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setPendingBulk(null);
                }}
            >
                {pendingBulk ? (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{bulkLabels[pendingBulk]}</DialogTitle>
                            <DialogDescription>
                                {bulkDescriptions[pendingBulk]}
                            </DialogDescription>
                        </DialogHeader>
                        {!allMatching ? (
                            <p className="text-sm text-muted-foreground">
                                {selectedNames.join(', ')}
                                {(pendingBulk === 'link'
                                    ? linkable.length
                                    : selectedRows.length) > 6
                                    ? ` e altri ${(pendingBulk === 'link' ? linkable.length : selectedRows.length) - 6}`
                                    : ''}
                            </p>
                        ) : null}
                        {pendingBulk === 'create' && multipleSites ? (
                            <Field>
                                <FieldLabel htmlFor="asset-site">
                                    Sede
                                </FieldLabel>
                                <SitePicker
                                    sites={sites}
                                    value={bulkSite}
                                    disabled={busy}
                                    onChange={setBulkSite}
                                />
                            </Field>
                        ) : null}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setPendingBulk(null)}
                            >
                                Annulla
                            </Button>
                            <Button
                                disabled={
                                    busy ||
                                    (pendingBulk === 'create' && !bulkSite)
                                }
                                onClick={applyBulk}
                            >
                                {busy ? <Spinner /> : null}
                                Conferma
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                ) : null}
            </Dialog>
        </>
    );
}

SnipeItRecords.layout = () => ({ breadcrumbs: [] });
