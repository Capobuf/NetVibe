import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { Check, Link2, Plug, RefreshCw, Trash2 } from 'lucide-react';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    CardFooter,
} from '@/components/ui/card';
import {
    Combobox,
    ComboboxInput,
    ComboboxContent,
    ComboboxList,
    ComboboxItem,
    ComboboxEmpty,
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
    Field,
    FieldGroup,
    FieldLabel,
    FieldDescription,
    FieldError,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import * as globalRoutes from '@/routes/administration/integrations/snipe-it';
import * as companyRoutes from '@/routes/companies/settings/integrations/snipe-it';
import type { Company } from '@/types/company';

type Connection = {
    id: number;
    server_url: string;
    has_key: boolean;
    checked_at: string | null;
    check_status: string | null;
};
type Settings = {
    use_global: boolean;
    revision: number;
    global_revision: number;
    own_connection: Connection | null;
    global_connection: Connection | null;
    connection: Connection | null;
    association: {
        external_company_id: number;
        external_company_name: string;
        confirmed: boolean;
        confirmed_at: string | null;
    } | null;
};
type Props = {
    company: Company | null;
    settings: Settings;
    affectedCompanies: Company[];
};
type TestResult = {
    status: string;
    message: string;
    checked_at: string;
    draft: boolean;
};
type CompanyList = {
    companies: Company[];
    connection_id: number;
    suggested_id: number | null;
};

function defaults(settings: Settings) {
    return {
        use_global: settings.use_global,
        server_url: settings.own_connection?.server_url ?? '',
        api_key: '',
        revision: settings.revision,
        global_revision: settings.global_revision,
        confirm_change: false,
        connection: '',
    };
}

function date(value: string) {
    return new Date(value).toLocaleString('it-IT');
}

export default function SnipeIt(props: Props) {
    // Reset transient form/list state after a save or a change of company; secrets never enter history state.
    return (
        <SnipeItForm
            key={`${props.company?.id ?? 'global'}:${props.settings.revision}:${props.settings.global_revision}`}
            {...props}
        />
    );
}

function SnipeItForm({ company, settings, affectedCompanies }: Props) {
    const form = useForm(defaults(settings));
    const test = useHttp<ReturnType<typeof defaults>, TestResult>(
        defaults(settings),
    );
    const list = useHttp<
        { revision: number; global_revision: number; connection: string },
        CompanyList
    >({
        revision: settings.revision,
        global_revision: settings.global_revision,
        connection: '',
    });
    const association = useForm({
        revision: settings.revision,
        global_revision: settings.global_revision,
        connection_id: 0,
        external_company_id: 0,
        connection: '',
    });
    const [savedCheck, setSavedCheck] = useState<
        TestResult | Connection | null
    >(settings.connection);
    const [result, setResult] = useState<TestResult | null>(null);
    const [options, setOptions] = useState<CompanyList | null>(null);
    const [selected, setSelected] = useState<Company | null>(null);
    const [error, setError] = useState('');
    const [confirm, setConfirm] = useState<'save' | 'remove' | null>(null);
    const busy =
        form.processing ||
        test.processing ||
        list.processing ||
        association.processing;
    const usesGlobal = !!company && form.data.use_global;
    const selectedConnection = usesGlobal
        ? settings.global_connection
        : settings.own_connection;
    const changed =
        form.data.use_global !== settings.use_global ||
        (!usesGlobal &&
            (form.data.server_url.replace(/\/+$/, '') !==
                (settings.own_connection?.server_url ?? '') ||
                form.data.api_key !== ''));
    const globalServerChange =
        !company &&
        !!settings.own_connection &&
        form.data.server_url.replace(/\/+$/, '') !==
            settings.own_connection.server_url;
    const connectionError =
        form.errors.connection ||
        test.errors.connection ||
        list.errors.connection ||
        association.errors.connection ||
        error;
    const urlError = form.errors.server_url || test.errors.server_url;
    const keyError = form.errors.api_key || test.errors.api_key;
    const lastCheck = savedCheck;

    function clearFeedback() {
        setResult(null);
        setError('');
        form.clearErrors();
        test.clearErrors();
        list.clearErrors();
        association.clearErrors();
    }

    function save(confirmed = false) {
        if (globalServerChange && !confirmed) {
            setConfirm('save');
            return;
        }
        form.transform((data) => ({ ...data, confirm_change: confirmed }));
        form.submit(
            company ? companyRoutes.update(company.id) : globalRoutes.update(),
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset('api_key');
                    setConfirm(null);
                },
                onError: () => setConfirm(null),
            },
        );
    }

    async function verify() {
        clearFeedback();
        test.transform(() => form.data);
        try {
            const checked = await test.submit(
                company ? companyRoutes.test(company.id) : globalRoutes.test(),
            );
            setResult(checked);
            if (!checked.draft) setSavedCheck(checked);
        } catch {
            setError(
                'Verifica non completata. Controlla i campi o attendi e riprova.',
            );
        }
    }

    async function loadCompanies() {
        setError('');
        setOptions(null);
        setSelected(null);
        try {
            const data = await list.submit(
                companyRoutes.companies(company!.id),
            );
            setOptions(data);
            const current = settings.association?.confirmed
                ? settings.association.external_company_id
                : data.suggested_id;
            setSelected(
                data.companies.find((item) => item.id === current) ?? null,
            );
        } catch {
            setError('Elenco non caricato. Verifica la connessione e riprova.');
        }
    }

    return (
        <>
            <Head title={`Snipe-IT · ${company?.name ?? 'Amministrazione'}`} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <Tabs defaultValue="snipe-it" className="gap-6">
                    <TabsList aria-label="Integrazioni">
                        <TabsTrigger value="snipe-it">
                            <Plug />
                            Snipe-IT
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent
                        value="snipe-it"
                        className="flex flex-col gap-6"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <h2 className="text-lg font-semibold">
                                    Connessione{' '}
                                    {company
                                        ? settings.use_global
                                            ? 'globale'
                                            : 'dedicata'
                                        : 'globale'}
                                </h2>
                                {!settings.connection ? (
                                    <Badge variant="outline">
                                        Non configurata
                                    </Badge>
                                ) : null}
                                {changed ? (
                                    <Badge variant="outline">
                                        Modifiche non salvate
                                    </Badge>
                                ) : null}
                            </div>
                            {lastCheck?.checked_at ? (
                                <p className="text-sm text-muted-foreground">
                                    Ultima verifica:{' '}
                                    {date(lastCheck.checked_at)} ·{' '}
                                    {('status' in lastCheck
                                        ? lastCheck.status
                                        : lastCheck.check_status) === 'ok'
                                        ? 'Riuscita'
                                        : 'Non riuscita'}
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Mai verificata
                                </p>
                            )}
                        </div>
                        {connectionError ? (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {connectionError}
                                </AlertDescription>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.reload()}
                                >
                                    Ricarica pagina
                                </Button>
                            </Alert>
                        ) : null}
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                save();
                            }}
                        >
                            <Card>
                                <CardHeader>
                                    <CardTitle>Connessione</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-6">
                                    <FieldSet disabled={busy}>
                                        <FieldGroup>
                                            {company ? (
                                                <Field>
                                                    <FieldLabel id="source-label">
                                                        Origine
                                                    </FieldLabel>
                                                    <ToggleGroup
                                                        type="single"
                                                        variant="outline"
                                                        value={
                                                            usesGlobal
                                                                ? 'global'
                                                                : 'dedicated'
                                                        }
                                                        aria-labelledby="source-label"
                                                        className="w-fit flex-wrap"
                                                        disabled={busy}
                                                        onValueChange={(
                                                            value,
                                                        ) => {
                                                            if (value) {
                                                                clearFeedback();
                                                                form.setData(
                                                                    'use_global',
                                                                    value ===
                                                                        'global',
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        <ToggleGroupItem value="global">
                                                            Usa connessione
                                                            globale
                                                        </ToggleGroupItem>
                                                        <ToggleGroupItem value="dedicated">
                                                            Connessione dedicata
                                                        </ToggleGroupItem>
                                                    </ToggleGroup>
                                                </Field>
                                            ) : null}
                                            {usesGlobal ? (
                                                <Field>
                                                    <FieldLabel>
                                                        URL server
                                                    </FieldLabel>
                                                    <p className="text-sm break-all">
                                                        {settings
                                                            .global_connection
                                                            ?.server_url ??
                                                            'Connessione globale non configurata'}
                                                    </p>
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                        className="w-fit"
                                                    >
                                                        <Link
                                                            href={globalRoutes.edit()}
                                                        >
                                                            Gestisci connessione
                                                            globale
                                                        </Link>
                                                    </Button>
                                                </Field>
                                            ) : (
                                                <>
                                                    <Field
                                                        data-invalid={
                                                            !!urlError
                                                        }
                                                    >
                                                        <FieldLabel htmlFor="server_url">
                                                            URL server
                                                        </FieldLabel>
                                                        <Input
                                                            id="server_url"
                                                            type="url"
                                                            name="server_url"
                                                            placeholder="https://inventario.example.it"
                                                            value={
                                                                form.data
                                                                    .server_url
                                                            }
                                                            onChange={(
                                                                event,
                                                            ) => {
                                                                clearFeedback();
                                                                form.setData(
                                                                    'server_url',
                                                                    event.target
                                                                        .value,
                                                                );
                                                            }}
                                                            required
                                                            maxLength={2048}
                                                            autoComplete="off"
                                                            aria-invalid={
                                                                !!urlError
                                                            }
                                                            aria-describedby="server-url-error"
                                                        />
                                                        <FieldError id="server-url-error">
                                                            {urlError}
                                                        </FieldError>
                                                    </Field>
                                                    <Field
                                                        data-invalid={
                                                            !!keyError
                                                        }
                                                    >
                                                        <FieldLabel htmlFor="api_key">
                                                            {selectedConnection?.has_key
                                                                ? 'Nuova API key'
                                                                : 'API key'}
                                                        </FieldLabel>
                                                        <PasswordInput
                                                            id="api_key"
                                                            name="api_key"
                                                            hasSavedValue={
                                                                selectedConnection?.has_key
                                                            }
                                                            value={
                                                                form.data
                                                                    .api_key
                                                            }
                                                            onChange={(
                                                                event,
                                                            ) => {
                                                                clearFeedback();
                                                                form.setData(
                                                                    'api_key',
                                                                    event.target
                                                                        .value,
                                                                );
                                                            }}
                                                            autoComplete="new-password"
                                                            maxLength={8192}
                                                            disabled={busy}
                                                            aria-invalid={
                                                                !!keyError
                                                            }
                                                            aria-describedby="key-hint key-error"
                                                        />
                                                        {selectedConnection?.has_key ? (
                                                            <FieldDescription id="key-hint">
                                                                Chiave salvata.
                                                                Lascia vuoto per
                                                                conservarla
                                                                sullo stesso
                                                                server.
                                                            </FieldDescription>
                                                        ) : null}
                                                        <FieldError id="key-error">
                                                            {keyError}
                                                        </FieldError>
                                                    </Field>
                                                </>
                                            )}
                                        </FieldGroup>
                                    </FieldSet>
                                    {result ? (
                                        <Alert
                                            variant={
                                                result.status === 'ok'
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                            aria-live="polite"
                                        >
                                            <AlertTitle>
                                                {result.draft
                                                    ? 'Verifica della bozza · non salvata'
                                                    : 'Verifica della connessione salvata'}
                                            </AlertTitle>
                                            <AlertDescription>
                                                {result.message} ·{' '}
                                                {date(result.checked_at)}
                                            </AlertDescription>
                                        </Alert>
                                    ) : null}
                                </CardContent>
                                <CardFooter className="flex flex-wrap gap-3">
                                    <Button
                                        type="submit"
                                        disabled={busy || !changed}
                                    >
                                        {form.processing ? (
                                            <Spinner />
                                        ) : (
                                            <Check />
                                        )}
                                        Salva
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            busy ||
                                            (usesGlobal && !selectedConnection)
                                        }
                                        onClick={() => void verify()}
                                    >
                                        {test.processing ? (
                                            <Spinner />
                                        ) : (
                                            <RefreshCw />
                                        )}
                                        Verifica connessione
                                    </Button>
                                    {settings.own_connection ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            disabled={busy}
                                            onClick={() => setConfirm('remove')}
                                        >
                                            <Trash2 />
                                            {company
                                                ? 'Rimuovi connessione dedicata'
                                                : 'Rimuovi connessione'}
                                        </Button>
                                    ) : null}
                                </CardFooter>
                            </Card>
                        </form>
                        {company ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Azienda associata</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-5">
                                    {settings.association ? (
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className="text-sm font-medium">
                                                {
                                                    settings.association
                                                        .external_company_name
                                                }
                                            </span>
                                            <Badge
                                                variant={
                                                    settings.association
                                                        .confirmed
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {settings.association.confirmed
                                                    ? 'Associata'
                                                    : 'Da riconfermare'}
                                            </Badge>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Nessuna azienda associata
                                        </p>
                                    )}
                                    {changed ? (
                                        <p className="text-sm text-muted-foreground">
                                            Salva la connessione prima di
                                            scegliere l’azienda.
                                        </p>
                                    ) : options ? (
                                        <Field
                                            data-invalid={
                                                !!association.errors
                                                    .external_company_id
                                            }
                                        >
                                            <FieldLabel htmlFor="external-company">
                                                Azienda Snipe-IT
                                            </FieldLabel>
                                            <Combobox
                                                items={options.companies}
                                                value={selected}
                                                onValueChange={setSelected}
                                                itemToStringLabel={(
                                                    item: Company,
                                                ) => item.name}
                                                isItemEqualToValue={(
                                                    a: Company,
                                                    b: Company,
                                                ) => a.id === b.id}
                                                disabled={busy}
                                            >
                                                <ComboboxInput
                                                    id="external-company"
                                                    placeholder="Cerca azienda…"
                                                    aria-invalid={
                                                        !!association.errors
                                                            .external_company_id
                                                    }
                                                    aria-describedby="company-error company-suggestion"
                                                />
                                                <ComboboxContent>
                                                    <ComboboxEmpty>
                                                        Nessuna azienda trovata
                                                    </ComboboxEmpty>
                                                    <ComboboxList>
                                                        {(item: Company) => (
                                                            <ComboboxItem
                                                                key={item.id}
                                                                value={item}
                                                            >
                                                                {item.name}
                                                            </ComboboxItem>
                                                        )}
                                                    </ComboboxList>
                                                </ComboboxContent>
                                            </Combobox>
                                            {options.suggested_id &&
                                            !settings.association?.confirmed &&
                                            selected?.id ===
                                                options.suggested_id ? (
                                                <FieldDescription id="company-suggestion">
                                                    Nome corrispondente:
                                                    conferma l’associazione.
                                                </FieldDescription>
                                            ) : null}
                                            <FieldError id="company-error">
                                                {
                                                    association.errors
                                                        .external_company_id
                                                }
                                            </FieldError>
                                        </Field>
                                    ) : null}
                                </CardContent>
                                <CardFooter className="flex flex-wrap gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            busy ||
                                            changed ||
                                            !settings.connection
                                        }
                                        onClick={() => void loadCompanies()}
                                    >
                                        {list.processing ? (
                                            <Spinner />
                                        ) : (
                                            <RefreshCw />
                                        )}
                                        {options
                                            ? 'Ricarica aziende'
                                            : 'Carica aziende'}
                                    </Button>
                                    {options ? (
                                        <Button
                                            type="button"
                                            disabled={
                                                busy || changed || !selected
                                            }
                                            onClick={() => {
                                                association.transform(
                                                    (data) => ({
                                                        ...data,
                                                        connection_id:
                                                            options.connection_id,
                                                        external_company_id:
                                                            selected!.id,
                                                    }),
                                                );
                                                association.submit(
                                                    companyRoutes.associate(
                                                        company.id,
                                                    ),
                                                    { preserveScroll: true },
                                                );
                                            }}
                                        >
                                            {association.processing ? (
                                                <Spinner />
                                            ) : (
                                                <Link2 />
                                            )}
                                            Conferma associazione
                                        </Button>
                                    ) : null}
                                </CardFooter>
                            </Card>
                        ) : null}
                    </TabsContent>
                </Tabs>
            </div>
            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setConfirm(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirm === 'remove'
                                ? 'Rimuovere la connessione?'
                                : 'Cambiare il server globale?'}
                        </DialogTitle>
                        <DialogDescription>
                            {company
                                ? 'La connessione dedicata verrà rimossa. L’eventuale associazione che la usa dovrà essere riconfermata; i dati applicativi saranno conservati.'
                                : 'Le aziende che usano la connessione globale dovranno riconfermare l’associazione. I dati applicativi saranno conservati.'}
                        </DialogDescription>
                    </DialogHeader>
                    {!company ? (
                        <div className="flex flex-col gap-2">
                            <p className="text-sm font-medium">
                                Aziende interessate ({affectedCompanies.length})
                            </p>
                            <ul className="max-h-48 overflow-y-auto text-sm">
                                {affectedCompanies.map((item) => (
                                    <li key={item.id}>{item.name}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setConfirm(null)}
                        >
                            Annulla
                        </Button>
                        <Button
                            variant={
                                confirm === 'remove' ? 'destructive' : 'default'
                            }
                            disabled={busy}
                            onClick={() => {
                                if (confirm === 'save') {
                                    save(true);
                                    return;
                                }
                                form.transform((data) => ({
                                    revision: data.revision,
                                    global_revision: data.global_revision,
                                    confirm_change: true,
                                }));
                                form.submit(
                                    company
                                        ? companyRoutes.destroy(company.id)
                                        : globalRoutes.destroy(),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setConfirm(null),
                                        onError: () => setConfirm(null),
                                    },
                                );
                            }}
                        >
                            {busy ? <Spinner /> : null}
                            {confirm === 'remove'
                                ? 'Rimuovi connessione'
                                : 'Cambia server'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
