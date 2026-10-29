import { Form, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import InputError from '@/components/input-error';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/companies/sites/search';

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
                    <InputGroup>
                        <InputGroupInput
                            key={`${component}:${search}`}
                            id="global-search"
                            name="search"
                            type="search"
                            placeholder="Cerca IP, MAC, hostname…"
                            defaultValue={search}
                            maxLength={255}
                            autoComplete="off"
                            aria-invalid={Boolean(errors.search)}
                            aria-describedby={
                                errors.search
                                    ? 'global-search-error'
                                    : undefined
                            }
                        />
                        <InputGroupAddon align="inline-end">
                            <InputGroupButton
                                type="submit"
                                size="icon-sm"
                                aria-label="Cerca nelle sedi dell’azienda"
                                disabled={processing}
                            >
                                {processing ? <Spinner /> : <Search />}
                            </InputGroupButton>
                        </InputGroupAddon>
                    </InputGroup>
                    {searching ? (
                        <>
                            <input
                                type="hidden"
                                name="group"
                                value={query.get('group') ?? 'none'}
                            />
                            <input
                                type="hidden"
                                name="sort"
                                value={query.get('sort') ?? ''}
                            />
                            <input
                                type="hidden"
                                name="direction"
                                value={query.get('direction') ?? 'asc'}
                            />
                        </>
                    ) : null}
                    <InputError
                        id="global-search-error"
                        message={errors.search}
                    />
                </Field>
            )}
        </Form>
    );
}
