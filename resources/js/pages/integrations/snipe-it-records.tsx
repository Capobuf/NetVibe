import { Head, Link, router, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { ArrowLeft, Check, ChevronDown, RefreshCw, Search } from 'lucide-react';
import {
    SnipeItPicker,
    type SnipeOption,
} from '@/components/devices/snipe-it-picker';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import type { TableSort } from '@/lib/table-sort';
import { cn } from '@/lib/utils';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Pagination, PaginationContent, PaginationItem, PaginationLink, PaginationPrevious, PaginationNext, PaginationEllipsis } from '@/components/ui/pagination';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    confirm,
    edit,
    records,
    sync as refresh,
} from '@/routes/companies/settings/integrations/snipe-it';
import type { Company, Site } from '@/types/company';

type View = 'devices' | 'sites' | 'excluded';
type Column = 'local' | 'name' | 'model' | 'serial' | 'state';
type Row = {
    id: number;
    snapshot: {
        name: string | null;
        manufacturer?: string | null;
        category?: string | null;
        serial?: string | null;
        asset_tag?: string | null;
    };
    state: 'linked' | 'excluded' | 'review' | 'candidate' | 'new';
    problem: string | null;
    present: boolean;
    local: SnipeOption | null;
    candidates: SnipeOption[];
    model: { name: string | null; manufacturer: string | null; category: string | null } | null;
    site_id: number | null;
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
    filters: { q?: string; sort?: Column; direction?: 'asc' | 'desc'; per_page?: number; group?: 'category' | 'none' };
    rows: {
        data: Row[];
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
        from: number | null;
        to: number | null;
        links: { url: string | null; label: string; active: boolean }[];
    };
    sites: Site[];
};
const titles: Record<View, string> = {
    devices: 'Collega dispositivi',
    sites: 'Da assegnare',
    excluded: 'Esclusi',
};
const states = {
    linked: 'Già collegato',
    excluded: 'Escluso',
    review: 'Da verificare',
    candidate: 'Corrispondenza proposta',
    new: 'Da creare',
};

function SitePicker({
    sites,
    value,
    onChange,
    label,
    disabled,
}: {
    sites: Site[];
    value: number | null;
    onChange: (id: number | null) => void;
    label: string;
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
            <ComboboxInput aria-label={label} placeholder="Sede NetVibe…" />
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

export default function SnipeItRecords(props: Props) {
    return (
        <Records
            key={`${props.company.id}:${props.view}:${props.rows.current_page}:${props.filters.q}:${props.filters.sort}:${props.filters.direction}:${props.filters.group}:${props.rows.per_page}:${props.sites.length}:${props.sync?.revision}`}
            {...props}
        />
    );
}

function Records({ company, sync, active, view, filters, rows, sites }: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [selected, setSelected] = useState<number[]>([]);
    const [choices, setChoices] = useState<Record<number, Partial<Decision>>>(
        {},
    );
    const [targets, setTargets] = useState<Record<number, SnipeOption | null>>(
        {},
    );
    const [models, setModels] = useState<Record<number, SnipeOption | null>>({});
    const [bulkSite, setBulkSite] = useState<number | null>(null);
    const [review, setReview] = useState(false);
    const form = useForm({
        sync_id: sync?.id ?? 0,
        revision: sync?.revision ?? 0,
        view,
        decisions: [] as Decision[],
        activate: false,
    });
    const acquire = useForm({});
    const busy = form.processing || acquire.processing;
    const siteView = view === 'sites';
    const multipleSites = sites.length > 1;
    const grouped = (filters.group ?? 'category') === 'category';
    const defaults: TableColumn<Column>[] = siteView
        ? [
              { id: 'name', label: 'Dispositivo', visible: true },
              { id: 'model', label: 'Produttore / Modello', visible: true },
              { id: 'serial', label: 'Seriale', visible: true },
              ...(multipleSites ? [{ id: 'local' as const, label: 'Sede NetVibe', visible: true }] : []),
              { id: 'state', label: 'Esito', visible: true },
          ]
        : [
              { id: 'local', label: 'NetVibe', visible: true },
              { id: 'name', label: 'Snipe-IT', visible: true },
              { id: 'model', label: 'Produttore / Modello', visible: true },
              { id: 'serial', label: 'Seriale', visible: true },
              { id: 'state', label: 'Esito', visible: true },
          ];
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences(`snipe-it-${view}`, defaults);
    const visible = columns.filter((column) => column.visible);
    const actionable = rows.data.filter(
        (row) => row.state !== 'linked' && (row.present || view === 'excluded'),
    );
    const sort: TableSort<Column> =
        filters.sort && filters.direction
            ? { column: filters.sort, direction: filters.direction }
            : null;
    const error = [
        ...Object.values(form.errors),
        ...Object.values(acquire.errors),
        sync?.error,
    ].find((message): message is string => typeof message === 'string' && message.length > 0);

    function visit(changes: Record<string, string | number | null>) {
        router.get(
            records.url(company.id),
            { view, ...filters, ...changes },
            { preserveScroll: true },
        );
    }
    function decision(row: Row): Decision {
        return {
            id: row.id,
            model_id: null,
            change_model: false,
            action:
                view === 'excluded'
                    ? 'restore'
                    : row.candidates.length
                      ? 'link'
                      : 'create',
            target_id:
                row.candidates.length === 1 ? row.candidates[0].id : null,
            site_id: sites.length === 1 ? sites[0].id : null,
            ...choices[row.id],
        };
    }
    function choose(row: Row, change: Partial<Decision>) {
        setChoices((previous) => ({
            ...previous,
            [row.id]: { ...previous[row.id], ...change },
        }));
        setSelected((previous) =>
            previous.includes(row.id) ? previous : [...previous, row.id],
        );
    }
    function applyBulk(change: Partial<Decision>) {
        setChoices((previous) => ({
            ...previous,
            ...Object.fromEntries(rows.data.filter((row) => selected.includes(row.id)).map((row) => [
                row.id,
                { ...decision(row), ...change },
            ])),
        }));
    }
    function confirmSelection() {
        form.transform((data) => ({
            ...data,
            decisions: rows.data
                .filter((row) => selected.includes(row.id))
                .map(decision),
        }));
        form.submit(confirm(company.id), {
            preserveScroll: true,
            onSuccess: () => {
                setReview(false);
                setSelected([]);
            },
            onError: () => setReview(false),
        });
    }
    function cell(row: Row, column: Column) {
        const name =
            row.snapshot.name || '—';
        const choice = decision(row);
        if (column === 'name')
            return (
                <div className="flex flex-col gap-1">
                    <span className="font-medium">{name}</span>
                    {!grouped ? <span className="text-xs text-muted-foreground">{row.model?.category || 'Senza tipo'}</span> : null}
                </div>
            );
        if (column === 'model') {
            const target = choice.action === 'link'
                ? (targets[row.id] ?? row.candidates.find((item) => item.id === choice.target_id))
                : null;
            const model = models[row.id] ?? row.model_local;
            const different = !!target && (!target.model || target.model.id !== model?.id);
            const editable = !row.local && view !== 'excluded' && choice.action !== 'exclude';
            return (
                <div className="flex min-w-48 flex-col gap-2">
                    <span className="font-medium">{row.model?.name || '—'}</span>
                    <span className="text-xs text-muted-foreground">{row.model?.manufacturer || '—'}</span>
                    {row.model_problem ? (
                        <div className="flex flex-col gap-1 text-xs">
                            <p className="text-destructive">{row.model_problem}</p>
                            <span>Snipe-IT · {row.model?.category || 'Senza categoria'}</span>
                            <span>NetVibe · {row.model_local?.name} · {row.model_local?.detail || 'Senza tipologia'}</span>
                        </div>
                    ) : null}
                    {editable && !row.model_linked && (row.model_ambiguous || different || !!models[row.id]) ? (
                        <Field>
                            <FieldLabel>Modello NetVibe</FieldLabel>
                            <SnipeItPicker
                                company={company.id}
                                kind="models"
                                label={`Modello NetVibe per ${name}`}
                                value={model}
                                disabled={busy || !active}
                                onChange={(value) => {
                                    setModels((previous) => ({ ...previous, [row.id]: value }));
                                    choose(row, { model_id: value?.id ?? null, change_model: false });
                                }}
                            />
                        </Field>
                    ) : null}
                    {editable && different ? (
                        <Field orientation="horizontal">
                            <Checkbox
                                id={`change-model-${row.id}`}
                                checked={choice.change_model}
                                disabled={busy || !active}
                                onCheckedChange={(checked) => choose(row, { change_model: checked === true })}
                            />
                            <FieldLabel htmlFor={`change-model-${row.id}`}>
                                Cambia modello da {target?.model?.name ?? 'nessun modello'} a {model?.name ?? row.model?.name}
                            </FieldLabel>
                        </Field>
                    ) : null}
                </div>
            );
        }
        if (column === 'serial')
            return (
                <span className="tabular-nums">{row.snapshot.serial || '—'}</span>
            );
        if (column === 'state')
            return (
                <div className="flex flex-col items-start gap-2">
                    <Badge
                        variant={
                            row.state === 'linked' ? 'secondary' : 'outline'
                        }
                    >
                        {!row.present ? 'Non più presente' : selected.includes(row.id) && choice.action === 'exclude' ? 'Da escludere' : states[row.state]}
                    </Badge>
                    {row.problem ? (
                        <p className="text-xs text-muted-foreground">
                            {row.problem}
                        </p>
                    ) : null}
                </div>
            );
        if (siteView && choice.action === 'exclude')
            return <Badge variant="outline">Da escludere</Badge>;
        if (siteView)
            return (
                <SitePicker
                    sites={sites}
                    value={choice.site_id}
                    onChange={(site_id) => choose(row, { site_id })}
                    label={`Sede per ${name}`}
                    disabled={busy || !active}
                />
            );
        if (row.local)
            return (
                <div className="flex flex-col gap-1">
                    <TextLink href={row.local.url!}>{row.local.name}</TextLink>
                    <span className="text-xs text-muted-foreground">
                        {row.local.detail}
                    </span>
                </div>
            );
        if (view === 'excluded')
            return (
                <span className="text-muted-foreground">
                    {selected.includes(row.id)
                        ? 'Da riammettere'
                        : 'Escluso dall’importazione'}
                </span>
            );
        return (
            <div className="flex min-w-56 flex-col gap-3">
                <Select
                    value={choice.action}
                    onValueChange={(action: Decision['action']) =>
                        choose(row, { action })
                    }
                    disabled={busy || !active}
                >
                    <SelectTrigger aria-label={`Azione per ${name}`}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="link">
                                Associa esistente
                            </SelectItem>
                            <SelectItem value="create">Crea nuovo</SelectItem>
                            <SelectItem value="exclude">Escludi</SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                {choice.action === 'link' ? (
                    <SnipeItPicker
                        company={company.id}
                        kind="devices"
                        label={`Record NetVibe per ${name}`}
                        disabled={busy || !active}
                        value={
                            targets[row.id] === undefined
                                ? (row.candidates.find(
                                      (item) => item.id === choice.target_id,
                                  ) ?? null)
                                : targets[row.id]
                        }
                        onChange={(value) => {
                            setTargets((previous) => ({
                                ...previous,
                                [row.id]: value,
                            }));
                            choose(row, { target_id: value?.id ?? null, change_model: false });
                        }}
                    />
                ) : null}
                {choice.action === 'create' && multipleSites ? (
                    <SitePicker
                        sites={sites}
                        value={choice.site_id}
                        onChange={(site_id) => choose(row, { site_id })}
                        label={`Sede per ${name}`}
                        disabled={busy || !active}
                    />
                ) : null}
            </div>
        );
    }
    return (
        <>
            <Head title={`${titles[view]} · Snipe-IT · ${company.name}`} />
            <div className="flex min-w-0 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={edit(company.id)}>
                            <ArrowLeft data-icon="inline-start" />
                            Snipe-IT
                        </Link>
                    </Button>
                </div>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <p className="text-sm text-muted-foreground">
                            {company.name} · Snipe-IT
                        </p>
                        <h1 className="text-2xl font-medium tracking-tight">
                            {titles[view]}
                        </h1>
                        {sync?.synced_at ? (
                            <p className="text-sm text-muted-foreground">
                                Ultimo aggiornamento:{' '}
                                {new Date(sync.synced_at).toLocaleString(
                                    'it-IT',
                                )}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={busy || (!!sync && !active)}
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
                            {sync?.synced_at
                                ? 'Sincronizza ora'
                                : 'Acquisisci anteprima'}
                        </Button>
                    </div>
                </header>
                {sync && !active ? (
                    <Alert>
                        <AlertDescription>
                            Sincronizzazione sospesa. Riconferma l’azienda nelle
                            impostazioni Snipe-IT.
                        </AlertDescription>
                    </Alert>
                ) : null}
                {error ? (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                ) : null}
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        className="w-full max-w-sm"
                        onSubmit={(event) => {
                            event.preventDefault();
                            visit({ q: search, page: 1 });
                        }}
                    >
                        <InputGroup>
                            <InputGroupAddon>
                                <Search />
                            </InputGroupAddon>
                            <InputGroupInput
                                aria-label="Cerca nell’intero elenco"
                                placeholder="Cerca nell’elenco…"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <InputGroupAddon align="inline-end">
                                <Button size="sm" variant="ghost" type="submit">
                                    Cerca
                                </Button>
                            </InputGroupAddon>
                        </InputGroup>
                    </form>
                    <Select
                        value={filters.group ?? 'category'}
                        onValueChange={(group) => visit({ group, page: 1 })}
                        disabled={busy}
                    >
                        <SelectTrigger aria-label="Raggruppa dispositivi" className="w-52">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem value="category">Per tipo Snipe-IT</SelectItem>
                                <SelectItem value="none">Senza raggruppamento</SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <span className="ml-auto text-sm text-muted-foreground">
                        {rows.total} risultati
                    </span>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    {selected.length > 0 ? (
                        <span className="text-sm text-muted-foreground" role="status">
                            {selected.length} selezionati nella pagina corrente
                        </span>
                    ) : null}
                    {selected.length > 1 && view !== 'excluded' ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" disabled={busy || !active}>
                                    Azioni massive <ChevronDown data-icon="inline-end" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuGroup>
                                    <DropdownMenuItem onSelect={() => applyBulk({ action: 'create', change_model: false })}>
                                        Crea nuovi
                                    </DropdownMenuItem>
                                    {!siteView ? (
                                        <DropdownMenuItem onSelect={() => applyBulk({ action: 'link', change_model: false })}>
                                            Associa esistenti
                                        </DropdownMenuItem>
                                    ) : null}
                                    <DropdownMenuItem onSelect={() => applyBulk({ action: 'exclude' })}>
                                        Escludi dall’importazione
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null}
                    {selected.length > 1 && multipleSites && view !== 'excluded' ? (
                        <div className="w-64">
                            <SitePicker
                                sites={sites}
                                value={bulkSite}
                                onChange={(site_id) => {
                                    setBulkSite(site_id);
                                    applyBulk({ site_id });
                                }}
                                label="Sede per i selezionati"
                                disabled={busy || !active}
                            />
                        </div>
                    ) : null}
                    <Button
                        className="ml-auto"
                        disabled={!active || busy || (!selected.length && (view !== 'devices' || !!sync?.activated_at))}
                        onClick={() => setReview(true)}
                    >
                        <Check data-icon="inline-start" />
                        {view === 'excluded' ? 'Riammetti selezionati' : 'Conferma scelte'}
                    </Button>
                </div>
                <div className="overflow-hidden rounded-xl border">
                    <Table
                        className={cn(
                            'max-lg:[&_tbody]:block max-lg:[&_td]:block max-lg:[&_td]:min-w-48 max-lg:[&_td]:flex-1 max-lg:[&_tr]:flex max-lg:[&_tr]:flex-wrap',
                            separators &&
                                'lg:[&_td+td]:border-l lg:[&_th+th]:border-l',
                        )}
                    >
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12">
                                    <Checkbox
                                        aria-label="Seleziona la pagina corrente"
                                        disabled={
                                            !actionable.length ||
                                            busy ||
                                            !active
                                        }
                                        checked={
                                            actionable.length > 0 &&
                                            actionable.every((row) =>
                                                selected.includes(row.id),
                                            )
                                                ? true
                                                : selected.length
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked
                                                    ? actionable.map(
                                                          (row) => row.id,
                                                      )
                                                    : [],
                                            )
                                        }
                                    />
                                </TableHead>
                                {visible.map((column) => (
                                    <TableSortHead
                                        key={column.id}
                                        column={column}
                                        sort={sort}
                                        onSort={(value) =>
                                            visit({
                                                sort: value?.column ?? null,
                                                direction:
                                                    value?.direction ?? null,
                                                page: 1,
                                            })
                                        }
                                    />
                                ))}
                                <TableHead className="w-16 text-right">
                                    <TableColumns
                                        columns={columns}
                                        onChange={setColumns}
                                        separators={separators}
                                        onSeparatorsChange={setSeparators}
                                    />
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.data.map((row, index) => {
                                const category = row.model?.category ?? null;
                                const startsGroup = grouped && (index === 0 || category !== (rows.data[index - 1].model?.category ?? null));
                                const groupIds = startsGroup
                                    ? actionable.filter((item) => (item.model?.category ?? null) === category).map((item) => item.id)
                                    : [];
                                const selectedInGroup = groupIds.filter((id) => selected.includes(id)).length;
                                return (
                                <Fragment key={row.id}>
                                    {startsGroup ? (
                                        <TableRow className="bg-muted/50 hover:bg-muted/50">
                                            <TableHead colSpan={visible.length + 2} scope="rowgroup" className="h-auto py-3 font-medium text-foreground max-lg:w-full">
                                                <div className="flex items-center gap-3">
                                                    <Checkbox
                                                        id={`snipe-group-${row.id}`}
                                                        aria-label={`Seleziona ${row.model?.category || 'Senza tipo'} nella pagina corrente`}
                                                        disabled={!groupIds.length || busy || !active}
                                                        checked={groupIds.length > 0 && selectedInGroup === groupIds.length
                                                            ? true
                                                            : selectedInGroup > 0 ? 'indeterminate' : false}
                                                        onCheckedChange={(checked) => setSelected((previous) => checked === true
                                                            ? [...new Set([...previous, ...groupIds])]
                                                            : previous.filter((id) => !groupIds.includes(id)))}
                                                    />
                                                    <label htmlFor={`snipe-group-${row.id}`}>
                                                        {row.model?.category || 'Senza tipo'}
                                                    </label>
                                                </div>
                                            </TableHead>
                                        </TableRow>
                                    ) : null}
                                <TableRow
                                    data-state={
                                        selected.includes(row.id)
                                            ? 'selected'
                                            : undefined
                                    }
                                >
                                    <TableCell className="max-lg:basis-full">
                                        <Checkbox
                                            aria-label={`Seleziona ${row.snapshot.name || row.snapshot.serial || row.id}`}
                                            disabled={
                                                row.state === 'linked' ||
                                                (!row.present &&
                                                    view !== 'excluded') ||
                                                busy ||
                                                !active
                                            }
                                            checked={selected.includes(row.id)}
                                            onCheckedChange={(checked) =>
                                                setSelected((previous) =>
                                                    checked
                                                        ? [...previous, row.id]
                                                        : previous.filter(
                                                              (id) =>
                                                                  id !== row.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </TableCell>
                                    {visible.map((column) => (
                                        <TableCell
                                            key={column.id}
                                            className="max-w-lg py-4 align-top break-words whitespace-normal"
                                        >
                                            <span className="mb-2 block text-xs text-muted-foreground lg:hidden">
                                                {column.label}
                                            </span>
                                            {cell(row, column.id)}
                                        </TableCell>
                                    ))}
                                    <TableCell className="max-lg:hidden" />
                                </TableRow>
                                </Fragment>
                                );
                            })}
                            {!rows.data.length ? (
                                <TableRow>
                                    <TableCell colSpan={visible.length + 2}>
                                        <Empty>
                                            <EmptyHeader>
                                                <EmptyTitle>
                                                    {sync?.synced_at
                                                        ? 'Nessun elemento in questa vista'
                                                        : 'Acquisisci gli asset per iniziare'}
                                                </EmptyTitle>
                                            </EmptyHeader>
                                        </Empty>
                                    </TableCell>
                                </TableRow>
                            ) : null}
                        </TableBody>
                    </Table>
                </div>
                <div className="grid items-center gap-4 lg:grid-cols-[1fr_auto_1fr]">
                    <span className="text-center text-sm tabular-nums text-muted-foreground lg:text-left">
                        {rows.from ?? 0}–{rows.to ?? 0} di {rows.total}
                    </span>
                    <Pagination>
                        <PaginationContent>
                            <PaginationItem>
                                <PaginationPrevious
                                    disabled={rows.current_page <= 1 || busy}
                                    onClick={() => visit({ page: rows.current_page - 1 })}
                                />
                            </PaginationItem>
                            {rows.links.slice(1, -1).map((link, index) => (
                                <PaginationItem key={`${link.label}-${index}`}>
                                    {link.url ? (
                                        <PaginationLink
                                            isActive={link.active}
                                            aria-label={`Pagina ${link.label}`}
                                            disabled={busy}
                                            onClick={() => visit({ page: Number(link.label) })}
                                        >
                                            {link.label}
                                        </PaginationLink>
                                    ) : <PaginationEllipsis />}
                                </PaginationItem>
                            ))}
                            <PaginationItem>
                                <PaginationNext
                                    disabled={rows.current_page >= rows.last_page || busy}
                                    onClick={() => visit({ page: rows.current_page + 1 })}
                                />
                            </PaginationItem>
                        </PaginationContent>
                    </Pagination>
                    <div className="flex items-center justify-center gap-2 lg:justify-end">
                        <label htmlFor="snipe-page-size" className="text-sm text-muted-foreground">Voci per pagina</label>
                        <Select
                            value={String(rows.per_page)}
                            onValueChange={(value) => visit({ per_page: Number(value), page: 1 })}
                            disabled={busy}
                        >
                            <SelectTrigger id="snipe-page-size" className="w-20">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {[25, 50, 100].map((size) => <SelectItem key={size} value={String(size)}>{size}</SelectItem>)}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </div>
            <Dialog
                open={review}
                onOpenChange={(open) => {
                    if (!busy) setReview(open);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Conferma {selected.length} scelte
                        </DialogTitle>
                        <DialogDescription>
                            {view === 'excluded'
                                  ? 'Gli asset selezionati torneranno in attesa di una scelta esplicita.'
                                  : 'Le associazioni aggiornano nome, seriale e modello. Sede e documentazione tecnica dei dispositivi esistenti restano conservate.'}
                        </DialogDescription>
                    </DialogHeader>
                    {view === 'devices' && !sync?.activated_at ? (
                        <Field orientation="horizontal">
                            <Checkbox
                                id="activate-snipe"
                                checked={form.data.activate}
                                onCheckedChange={(checked) =>
                                    form.setData('activate', checked === true)
                                }
                                disabled={busy}
                            />
                            <FieldLabel htmlFor="activate-snipe">
                                Attiva la sincronizzazione automatica ogni ora
                            </FieldLabel>
                        </Field>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setReview(false)}
                        >
                            Annulla
                        </Button>
                        <Button
                            disabled={
                                busy ||
                                (!selected.length && !form.data.activate)
                            }
                            onClick={confirmSelection}
                        >
                            {form.processing ? <Spinner /> : null}Conferma
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
