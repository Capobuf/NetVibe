import { Form, router, usePage } from '@inertiajs/react';
import { ArrowRight, Layers3, Network, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import {
    Combobox,
    ComboboxCollection,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxLabel,
    ComboboxList,
} from '@/components/ui/combobox';
import { Field, FieldLabel } from '@/components/ui/field';
import { InputGroupAddon, InputGroupButton } from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index, suggestions } from '@/routes/companies/sites/search';

type Row = {
    key: string;
    kind:
        | 'vlan'
        | 'subnet'
        | 'ip'
        | 'all'
        | 'device'
        | 'model'
        | 'rack'
        | 'cable';
    label: string;
    detail: string | null;
    href: string;
    depth: number;
    site: string | null;
};
type Section = { label: string; rows: Row[] };
type Group = { value: string; items: Row[] };
type Loaded = { term: string; groups: Group[]; failed?: boolean };

const icons = { vlan: Layers3, subnet: Network, all: ArrowRight };
const indent = ['pl-2', 'pl-8', 'pl-14'];

export function GlobalSearch() {
    const {
        component,
        url,
        props: { companyNavigation },
    } = usePage();
    const query = new URL(url, window.location.origin).searchParams;
    const searching = component === 'search/index';
    const search = searching ? (query.get('search') ?? '') : '';
    const company = companyNavigation?.currentCompanyId;
    const site =
        companyNavigation?.currentSiteId ?? companyNavigation?.sites[0]?.id;

    if (!company || !site) return null;

    // Keyed per page so the typed text and suggestions reset after every visit.
    return (
        <SearchBox
            key={`${component}:${search}`}
            company={company}
            site={site}
            search={search}
            view={
                searching
                    ? {
                          group: query.get('group') ?? 'none',
                          sort: query.get('sort') ?? '',
                          direction: query.get('direction') ?? 'asc',
                      }
                    : null
            }
        />
    );
}

function SearchBox({
    company,
    site,
    search,
    view,
}: {
    company: number;
    site: number;
    search: string;
    view: Record<string, string> | null;
}) {
    const [text, setText] = useState(search);
    const [open, setOpen] = useState(false);
    const [loaded, setLoaded] = useState<Loaded | null>(null);
    const term = text.trim();
    const loading = term !== '' && loaded?.term !== term;

    useEffect(() => {
        if (term === '') return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(
                suggestions.url({ company, site }, { query: { search: term } }),
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then(async (response) => {
                    if (!response.ok) throw new Error();
                    const sections: Section[] = await response.json();
                    setLoaded({
                        term,
                        groups: sections.map((section) => ({
                            value: section.label,
                            items: section.rows,
                        })),
                    });
                })
                .catch(() => {
                    if (!controller.signal.aborted)
                        setLoaded({ term, groups: [], failed: true });
                });
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, company, site]);

    // Previous suggestions stay visible while the next ones load.
    const groups = term === '' ? [] : (loaded?.groups ?? []);

    return (
        <Form
            {...index.form({ company, site })}
            role="search"
            aria-label="Ricerca nell’azienda"
            options={{ preserveState: true }}
            className="w-full min-w-0 xl:w-72"
        >
            {({ processing, errors }) => (
                <Field data-invalid={Boolean(errors.search)}>
                    <FieldLabel htmlFor="global-search" className="sr-only">
                        Cerca IPv4, subnet, VLAN, dispositivi e modelli
                        nell’azienda
                    </FieldLabel>
                    <Combobox
                        items={groups}
                        filter={null}
                        value={null}
                        inputValue={text}
                        onInputValueChange={(value, { reason }) => {
                            if (
                                reason === 'input-change' ||
                                reason === 'input-clear'
                            )
                                setText(value);
                        }}
                        open={open && term !== ''}
                        onOpenChange={setOpen}
                        itemToStringLabel={(row: Row) => row.label}
                        onValueChange={(row: Row | null) => {
                            if (row) router.visit(row.href);
                        }}
                    >
                        <ComboboxInput
                            id="global-search"
                            type="search"
                            placeholder="Cerca IP, subnet, dispositivi…"
                            maxLength={255}
                            autoComplete="off"
                            className="w-full"
                            showTrigger={false}
                            aria-invalid={Boolean(errors.search)}
                            aria-describedby={
                                errors.search
                                    ? 'global-search-error'
                                    : undefined
                            }
                        >
                            <InputGroupAddon align="inline-end">
                                <InputGroupButton
                                    type="submit"
                                    size="icon-sm"
                                    aria-label="Cerca nelle sedi dell’azienda"
                                    disabled={processing}
                                >
                                    {processing || loading ? (
                                        <Spinner />
                                    ) : (
                                        <Search />
                                    )}
                                </InputGroupButton>
                            </InputGroupAddon>
                        </ComboboxInput>
                        <ComboboxContent
                            align="end"
                            className="max-h-[min(36rem,var(--available-height))] w-[max(var(--anchor-width),26rem)]"
                        >
                            <ComboboxEmpty>
                                {loading ? (
                                    <span className="flex items-center gap-2">
                                        <Spinner />
                                        Ricerca in corso…
                                    </span>
                                ) : loaded?.failed ? (
                                    'Impossibile caricare i risultati.'
                                ) : (
                                    'Nessun risultato'
                                )}
                            </ComboboxEmpty>
                            <ComboboxList className="max-h-[min(36rem,var(--available-height))]">
                                {(group: Group) => (
                                    <ComboboxGroup
                                        key={group.value}
                                        items={group.items}
                                        className="not-first:mt-1 not-first:border-t not-first:pt-1"
                                    >
                                        <ComboboxLabel>
                                            {group.value}
                                        </ComboboxLabel>
                                        <ComboboxCollection>
                                            {(row: Row) => (
                                                <SuggestionItem
                                                    key={row.key}
                                                    row={row}
                                                />
                                            )}
                                        </ComboboxCollection>
                                    </ComboboxGroup>
                                )}
                            </ComboboxList>
                        </ComboboxContent>
                    </Combobox>
                    <input type="hidden" name="search" value={text} />
                    {view
                        ? Object.entries(view).map(([name, value]) => (
                              <input
                                  key={name}
                                  type="hidden"
                                  name={name}
                                  value={value}
                              />
                          ))
                        : null}
                    <InputError
                        id="global-search-error"
                        message={errors.search}
                    />
                </Field>
            )}
        </Form>
    );
}

function SuggestionItem({ row }: { row: Row }) {
    const Icon =
        row.kind in icons ? icons[row.kind as keyof typeof icons] : null;

    return (
        <ComboboxItem
            value={row}
            className={cn(
                'gap-2 pr-2',
                indent[row.depth] ?? indent[0],
                row.kind === 'all' && 'text-muted-foreground',
            )}
        >
            {Icon ? <Icon className="text-muted-foreground" /> : null}
            <span
                className={cn(
                    'shrink-0',
                    row.kind !== 'all' && 'font-medium',
                    ['vlan', 'subnet', 'ip'].includes(row.kind) &&
                        'tabular-nums',
                )}
            >
                {row.label}
            </span>
            {row.detail ? (
                <span className="min-w-0 truncate text-muted-foreground">
                    {row.detail}
                </span>
            ) : null}
            {row.site ? (
                <span className="ml-auto max-w-[40%] shrink-0 truncate pl-2 text-xs text-muted-foreground">
                    {row.site}
                </span>
            ) : null}
        </ComboboxItem>
    );
}
