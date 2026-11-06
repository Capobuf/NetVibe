import { Form, router, usePage } from '@inertiajs/react';
import { ArrowRight, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxSeparator,
} from '@/components/ui/combobox';
import { Field, FieldLabel } from '@/components/ui/field';
import { InputGroupAddon, InputGroupButton } from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { index, suggestions } from '@/routes/companies/sites/search';
import { show } from '@/routes/companies/sites/subnets';

type Suggestion = {
    id: number | null;
    ip: string;
    hostname: string | null;
    mac_address: string | null;
    assignment_label: string | null;
    site_id: number;
    site: string | null;
    subnet_id: number;
    subnet: string;
};
type Option = Suggestion | { all: number };
type Loaded = {
    term: string;
    results: Suggestion[];
    total: number;
    failed?: boolean;
};

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
            companyName={
                companyNavigation.companies.find(({ id }) => id === company)
                    ?.name ?? ''
            }
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
    companyName,
    search,
    view,
}: {
    company: number;
    site: number;
    companyName: string;
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
                    const data: Omit<Loaded, 'term'> = await response.json();
                    setLoaded({ term, ...data });
                })
                .catch(() => {
                    if (!controller.signal.aborted)
                        setLoaded({
                            term,
                            results: [],
                            total: 0,
                            failed: true,
                        });
                });
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, company, site]);

    // Previous suggestions stay visible while the next ones load.
    const results = term === '' ? [] : (loaded?.results ?? []);
    const total = term === '' ? 0 : (loaded?.total ?? 0);
    const options: Option[] =
        total > results.length ? [...results, { all: total }] : results;

    function showAll() {
        router.get(
            index.url({ company, site }, { query: { ...view, search: term } }),
        );
    }

    return (
        <Form
            {...index.form({ company, site })}
            role="search"
            aria-label="Ricerca IP nell’azienda"
            options={{ preserveState: true }}
            className="w-full min-w-0 xl:w-72"
        >
            {({ processing, errors }) => (
                <Field data-invalid={Boolean(errors.search)}>
                    <FieldLabel htmlFor="global-search" className="sr-only">
                        Cerca IPv4, MAC o hostname nelle sedi dell’azienda
                    </FieldLabel>
                    <Combobox<Option>
                        items={options}
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
                        itemToStringLabel={(option) =>
                            'ip' in option ? option.ip : term
                        }
                        onValueChange={(option) => {
                            if (!option) return;
                            if ('all' in option) return showAll();
                            router.visit(
                                show.url(
                                    {
                                        company,
                                        site: option.site_id,
                                        subnet: option.subnet_id,
                                    },
                                    {
                                        query: {
                                            search: option.ip,
                                            ip_address: option.id ?? undefined,
                                        },
                                    },
                                ),
                            );
                        }}
                    >
                        <ComboboxInput
                            id="global-search"
                            type="search"
                            placeholder="Cerca IP, MAC, hostname…"
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
                            className="w-[max(var(--anchor-width),24rem)]"
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
                            <ComboboxList>
                                {(option: Option) =>
                                    'all' in option ? (
                                        <div key="all">
                                            <ComboboxSeparator />
                                            <ComboboxItem
                                                value={option}
                                                className="text-muted-foreground"
                                            >
                                                <ArrowRight />
                                                Mostra tutti i{' '}
                                                {option.all.toLocaleString(
                                                    'it-IT',
                                                )}{' '}
                                                risultati
                                            </ComboboxItem>
                                        </div>
                                    ) : (
                                        <ComboboxItem
                                            key={`${option.subnet_id}:${option.ip}`}
                                            value={option}
                                            className="items-start gap-3 pr-2"
                                        >
                                            <div className="flex min-w-0 flex-1 flex-col">
                                                <span className="font-medium tabular-nums">
                                                    {option.ip}
                                                </span>
                                                {option.hostname ||
                                                option.assignment_label ||
                                                option.mac_address ? (
                                                    <span className="truncate text-xs text-muted-foreground">
                                                        {[
                                                            option.hostname,
                                                            option.assignment_label,
                                                            option.mac_address,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="flex max-w-[45%] shrink-0 flex-col text-right text-xs text-muted-foreground">
                                                <span className="truncate">
                                                    {option.site ?? companyName}
                                                </span>
                                                <span className="tabular-nums">
                                                    {option.subnet}
                                                </span>
                                            </div>
                                        </ComboboxItem>
                                    )
                                }
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
