import { SaveButton } from '@/components/save-button';
import { cn, formatDateTime } from '@/lib/utils';
import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useState, type ReactNode } from 'react';
import {
    Check,
    ChevronDown,
    Link2,
    RefreshCw,
    SlidersHorizontal,
    Trash2,
} from 'lucide-react';
import { IntegrationTabs } from '@/components/integration-tabs';
import { SnipeItTabs } from '@/components/snipe-it-tabs';
import { index as administration } from '@/routes/administration';
import { index as integrationIndex } from '@/routes/administration/integrations';
import PasswordInput from '@/components/password-input';
import { SnipeItFields } from '@/components/devices/snipe-it-fields';
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
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
type Sync = {
    id: number;
    active: boolean;
    activated_at: string | null;
    synced_at: string | null;
    error: string | null;
    counts: {
        pending: number;
        linked: number;
        ipam: number;
        excluded: number;
        problems: number;
    };
};
type Props = {
    company: Company | null;
    settings: Settings;
    affectedCompanies: Company[];
    sync: Sync | null;
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

export default function SnipeIt(props: Props) {
    const [tab, setTab] = useState('connection');
    // Reset transient form/list state after a save or a change of company; secrets never enter history state.
    return (
        <SnipeItForm
            key={`${props.company?.id ?? 'global'}:${props.settings.revision}:${props.settings.global_revision}`}
            {...props}
            tab={tab}
            onTabChange={setTab}
        />
    );
}

type StepState = 'done' | 'current' | 'locked';

function Step({
    number,
    title,
    state,
    summary,
    action,
    last,
    children,
}: {
    number: number;
    title: string;
    state: StepState;
    summary?: ReactNode;
    action?: ReactNode;
    last?: boolean;
    children?: ReactNode;
}) {
    return (
        <li className="relative flex gap-4">
            {!last ? (
                <span
                    aria-hidden="true"
                    className="absolute top-9 bottom-0 left-4 w-px -translate-x-1/2 bg-border"
                />
            ) : null}
            <span
                aria-hidden="true"
                className={cn(
                    'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-medium tabular-nums',
                    state === 'done' &&
                        'border-primary bg-primary text-primary-foreground',
                    state === 'current' && 'border-primary text-primary',
                    state === 'locked' && 'text-muted-foreground',
                )}
            >
                {state === 'done' ? <Check className="size-4" /> : number}
            </span>
            <div
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-3',
                    !last && 'pb-8',
                )}
            >
                <div className="flex min-h-8 flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <div className="flex min-w-0 flex-col">
                        <h3
                            className={cn(
                                'font-medium',
                                state === 'locked' && 'text-muted-foreground',
                            )}
                        >
                            <span className="sr-only">Passo {number}: </span>
                            {title}
                            {state === 'done' ? (
                                <span className="sr-only"> (completato)</span>
                            ) : null}
                        </h3>
                        {summary ? (
                            <div className="text-sm text-muted-foreground">
                                {summary}
                            </div>
                        ) : null}
                    </div>
                    {action}
                </div>
                {children}
            </div>
        </li>
    );
}

function SnipeItForm({
    company,
    settings,
    affectedCompanies,
    sync,
    tab,
    onTabChange,
}: Props & {
    tab: string;
    onTabChange: (tab: string) => void;
}) {
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
    const acquire = useForm<{ sync?: string }>({});
    const [savedCheck, setSavedCheck] = useState<
        TestResult | Connection | null
    >(settings.connection);
    const [result, setResult] = useState<TestResult | null>(null);
    const [options, setOptions] = useState<CompanyList | null>(null);
    const [selected, setSelected] = useState<Company | null>(null);
    const [error, setError] = useState('');
    const [confirm, setConfirm] = useState<'save' | 'remove' | null>(null);
    const [editConnection, setEditConnection] = useState(false);
    const [editCompany, setEditCompany] = useState(false);
    const busy =
        form.processing ||
        test.processing ||
        list.processing ||
        association.processing ||
        acquire.processing;
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
        acquire.errors.sync ||
        error;
    const urlError = form.errors.server_url || test.errors.server_url;
    const keyError = form.errors.api_key || test.errors.api_key;
    const lastCheck = savedCheck;
    const confirmed = settings.association?.confirmed ?? false;
    const connectionStep: StepState =
        settings.connection && !editConnection ? 'done' : 'current';
    const companyStep: StepState =
        connectionStep !== 'done'
            ? 'locked'
            : confirmed && !editCompany
              ? 'done'
              : 'current';
    const syncStep: StepState =
        companyStep !== 'done'
            ? 'locked'
            : sync?.synced_at
              ? 'done'
              : 'current';

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
                : (data.suggested_id ??
                  settings.association?.external_company_id);
            setSelected(
                data.companies.find((item) => item.id === current) ?? null,
            );
        } catch {
            setError('Elenco non caricato. Verifica la connessione e riprova.');
        }
    }

    // The company list is only useful once the step opens: load it then, once.
    const loadOnOpen = useEffectEvent(() => void loadCompanies());
    const companyStepOpen = !!company && companyStep === 'current';
    useEffect(() => {
        if (companyStepOpen) loadOnOpen();
    }, [companyStepOpen]);

    function synchronize() {
        acquire.submit(companyRoutes.sync(company!.id), {
            preserveScroll: true,
        });
    }

    const checkLabel = lastCheck?.checked_at
        ? `Verificata il ${formatDateTime(lastCheck.checked_at)} · ${
              ('status' in lastCheck
                  ? lastCheck.status
                  : lastCheck.check_status) === 'ok'
                  ? 'riuscita'
                  : 'non riuscita'
          }`
        : 'Mai verificata';

    const errorAlert = connectionError ? (
        <Alert variant="destructive">
            <AlertDescription>{connectionError}</AlertDescription>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => router.reload()}
            >
                Ricarica pagina
            </Button>
        </Alert>
    ) : null;

    const connectionFields = (
        <>
            <FieldSet disabled={busy}>
                <FieldGroup>
                    {company ? (
                        <Field>
                            <FieldLabel id="source-label">Server</FieldLabel>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={usesGlobal ? 'global' : 'dedicated'}
                                aria-labelledby="source-label"
                                className="w-fit flex-wrap"
                                disabled={busy}
                                onValueChange={(value) => {
                                    if (value) {
                                        clearFeedback();
                                        form.setData(
                                            'use_global',
                                            value === 'global',
                                        );
                                    }
                                }}
                            >
                                <ToggleGroupItem value="global">
                                    Connessione globale
                                </ToggleGroupItem>
                                <ToggleGroupItem value="dedicated">
                                    Server dedicato
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </Field>
                    ) : null}
                    {usesGlobal ? (
                        <Field>
                            <FieldLabel>URL server</FieldLabel>
                            <p className="text-sm break-all">
                                {settings.global_connection?.server_url ??
                                    'Connessione globale non configurata'}
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="w-fit"
                            >
                                <Link href={globalRoutes.edit()}>
                                    Gestisci connessione globale
                                </Link>
                            </Button>
                        </Field>
                    ) : (
                        <>
                            <Field data-invalid={!!urlError}>
                                <FieldLabel htmlFor="server_url">
                                    URL server
                                </FieldLabel>
                                <Input
                                    id="server_url"
                                    type="url"
                                    name="server_url"
                                    placeholder="https://inventario.example.it"
                                    value={form.data.server_url}
                                    onChange={(event) => {
                                        clearFeedback();
                                        form.setData(
                                            'server_url',
                                            event.target.value,
                                        );
                                    }}
                                    required
                                    maxLength={2048}
                                    autoComplete="off"
                                    aria-invalid={!!urlError}
                                    aria-describedby="server-url-error"
                                />
                                <FieldError id="server-url-error">
                                    {urlError}
                                </FieldError>
                            </Field>
                            <Field data-invalid={!!keyError}>
                                <FieldLabel htmlFor="api_key">
                                    {selectedConnection?.has_key
                                        ? 'Nuova API key'
                                        : 'API key'}
                                </FieldLabel>
                                <PasswordInput
                                    id="api_key"
                                    name="api_key"
                                    hasSavedValue={selectedConnection?.has_key}
                                    value={form.data.api_key}
                                    onChange={(event) => {
                                        clearFeedback();
                                        form.setData(
                                            'api_key',
                                            event.target.value,
                                        );
                                    }}
                                    autoComplete="new-password"
                                    maxLength={8192}
                                    disabled={busy}
                                    aria-invalid={!!keyError}
                                    aria-describedby="key-hint key-error"
                                />
                                {selectedConnection?.has_key ? (
                                    <FieldDescription id="key-hint">
                                        Chiave salvata. Lascia vuoto per
                                        conservarla sullo stesso server.
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
                    variant={result.status === 'ok' ? 'default' : 'destructive'}
                    aria-live="polite"
                >
                    <AlertTitle>
                        {result.draft
                            ? 'Verifica della bozza · non salvata'
                            : 'Verifica della connessione salvata'}
                    </AlertTitle>
                    <AlertDescription>
                        {result.message} · {formatDateTime(result.checked_at)}
                    </AlertDescription>
                </Alert>
            ) : null}
        </>
    );

    const connectionButtons = (
        <>
            <SaveButton
                processing={form.processing}
                saved={!!settings.connection && !changed}
                disabled={busy || !changed}
            />
            <Button
                type="button"
                variant="outline"
                disabled={busy || (usesGlobal && !selectedConnection)}
                onClick={() => void verify()}
            >
                {test.processing ? <Spinner /> : <RefreshCw />}
                Verifica connessione
            </Button>
            {settings.own_connection && (!company || !settings.use_global) ? (
                <Button
                    type="button"
                    variant="ghost"
                    disabled={busy}
                    onClick={() => setConfirm('remove')}
                >
                    <Trash2 />
                    {company
                        ? 'Rimuovi server dedicato'
                        : 'Rimuovi connessione'}
                </Button>
            ) : null}
        </>
    );

    const companyPicker = options ? (
        <Field data-invalid={!!association.errors.external_company_id}>
            <FieldLabel htmlFor="external-company">Azienda Snipe-IT</FieldLabel>
            <Combobox
                items={options.companies}
                value={selected}
                onValueChange={setSelected}
                itemToStringLabel={(item: Company) => item.name}
                isItemEqualToValue={(a: Company, b: Company) => a.id === b.id}
                disabled={busy}
            >
                <ComboboxInput
                    id="external-company"
                    placeholder="Cerca azienda…"
                    aria-invalid={!!association.errors.external_company_id}
                    aria-describedby="company-error company-suggestion"
                />
                <ComboboxContent>
                    <ComboboxEmpty>Nessuna azienda trovata</ComboboxEmpty>
                    <ComboboxList>
                        {(item: Company) => (
                            <ComboboxItem key={item.id} value={item}>
                                {item.name}
                            </ComboboxItem>
                        )}
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
            {options.suggested_id &&
            !confirmed &&
            selected?.id === options.suggested_id ? (
                <FieldDescription id="company-suggestion">
                    Stesso nome dell’azienda NetVibe.
                </FieldDescription>
            ) : null}
            <FieldError id="company-error">
                {association.errors.external_company_id}
            </FieldError>
        </Field>
    ) : list.processing ? (
        <p
            role="status"
            className="flex items-center gap-2 text-sm text-muted-foreground"
        >
            <Spinner />
            Caricamento aziende Snipe-IT…
        </p>
    ) : null;

    function submitAssociation() {
        association.transform((data) => ({
            ...data,
            connection_id: options!.connection_id,
            external_company_id: selected!.id,
        }));
        association.submit(companyRoutes.associate(company!.id), {
            preserveScroll: true,
        });
    }

    const dialog = (
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
                            ? 'Il server dedicato verrà rimosso. L’associazione con l’azienda Snipe-IT dovrà essere riconfermata; i dati applicativi saranno conservati.'
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
                    {confirm === 'save' ? (
                        <SaveButton
                            type="button"
                            processing={form.processing}
                            disabled={busy}
                            onClick={() => save(true)}
                        >
                            Cambia server
                        </SaveButton>
                    ) : (
                        <Button
                            variant="destructive"
                            disabled={busy}
                            onClick={() => {
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
                            Rimuovi connessione
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );

    if (!company) {
        return (
            <>
                <Head title="Snipe-IT · Amministrazione" />
                <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                    <h1 className="text-2xl font-medium tracking-tight">
                        Integrazioni
                    </h1>
                    <IntegrationTabs value="snipe-it" company={null}>
                        <Tabs
                            value={settings.connection ? tab : 'connection'}
                            onValueChange={onTabChange}
                            className="gap-6"
                        >
                            <TabsList aria-label="Impostazioni Snipe-IT">
                                <TabsTrigger value="connection">
                                    <Link2 aria-hidden="true" />
                                    Connessione
                                </TabsTrigger>
                                <TabsTrigger
                                    value="fields"
                                    disabled={
                                        !settings.connection || busy || changed
                                    }
                                >
                                    <SlidersHorizontal aria-hidden="true" />
                                    Mappatura Campi
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent
                                value="connection"
                                forceMount
                                className="flex flex-col gap-6 data-[state=inactive]:hidden"
                            >
                                {errorAlert}
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        save();
                                    }}
                                >
                                    <Card>
                                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                                            <CardTitle className="flex items-center gap-3">
                                                Connessione globale
                                                {!settings.connection ? (
                                                    <Badge variant="outline">
                                                        Non configurata
                                                    </Badge>
                                                ) : null}
                                            </CardTitle>
                                            <span className="text-sm text-muted-foreground">
                                                {checkLabel}
                                            </span>
                                        </CardHeader>
                                        <CardContent className="flex flex-col gap-6">
                                            {connectionFields}
                                        </CardContent>
                                        <CardFooter className="flex flex-wrap gap-3">
                                            {connectionButtons}
                                        </CardFooter>
                                    </Card>
                                </form>
                            </TabsContent>
                            <TabsContent
                                value="fields"
                                forceMount
                                className="data-[state=inactive]:hidden"
                            >
                                {settings.connection ? (
                                    <SnipeItFields
                                        revision={settings.revision}
                                        globalRevision={
                                            settings.global_revision
                                        }
                                        inherited={false}
                                        disabled={busy || changed}
                                        active={tab === 'fields'}
                                    />
                                ) : null}
                            </TabsContent>
                        </Tabs>
                    </IntegrationTabs>
                </div>
                {dialog}
            </>
        );
    }

    const counts = sync?.counts;

    return (
        <>
            <Head title={`Snipe-IT · ${company.name}`} />
            <div className="flex min-w-0 flex-col gap-4 p-4 md:p-6">
                <IntegrationTabs value="snipe-it" company={company}>
                    <SnipeItTabs
                        company={company.id}
                        value="settings"
                        pending={counts?.pending}
                    />
                    <div className="flex max-w-3xl flex-col gap-4">
                        {errorAlert}
                        <Card>
                            <CardContent>
                                <ol className="flex flex-col">
                                    <Step
                                        number={1}
                                        title="Connessione"
                                        state={connectionStep}
                                        summary={
                                            connectionStep === 'done' &&
                                            settings.connection ? (
                                                <>
                                                    <span className="break-all">
                                                        {settings.use_global
                                                            ? 'Connessione globale'
                                                            : 'Server dedicato'}{' '}
                                                        ·{' '}
                                                        {
                                                            settings.connection
                                                                .server_url
                                                        }
                                                    </span>
                                                    <br />
                                                    {checkLabel}
                                                </>
                                            ) : null
                                        }
                                        action={
                                            connectionStep === 'done' ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        setEditConnection(true)
                                                    }
                                                >
                                                    Modifica
                                                </Button>
                                            ) : null
                                        }
                                    >
                                        {connectionStep === 'current' ? (
                                            <form
                                                className="flex flex-col gap-5 rounded-lg border p-4"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    save();
                                                }}
                                            >
                                                {connectionFields}
                                                <div className="flex flex-wrap gap-3">
                                                    {connectionButtons}
                                                    {settings.connection ? (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            disabled={busy}
                                                            onClick={() => {
                                                                form.reset();
                                                                clearFeedback();
                                                                setEditConnection(
                                                                    false,
                                                                );
                                                            }}
                                                        >
                                                            Annulla
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </form>
                                        ) : null}
                                    </Step>
                                    <Step
                                        number={2}
                                        title="Azienda Snipe-IT"
                                        state={companyStep}
                                        summary={
                                            companyStep === 'done'
                                                ? settings.association
                                                      ?.external_company_name
                                                : settings.association &&
                                                    !confirmed
                                                  ? `${settings.association.external_company_name} · da riconfermare`
                                                  : null
                                        }
                                        action={
                                            companyStep === 'done' ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        setEditCompany(true)
                                                    }
                                                >
                                                    Cambia
                                                </Button>
                                            ) : null
                                        }
                                    >
                                        {companyStep === 'current' ? (
                                            <div className="flex flex-col gap-4 rounded-lg border p-4">
                                                {companyPicker}
                                                <div className="flex flex-wrap gap-3">
                                                    <Button
                                                        type="button"
                                                        disabled={
                                                            busy ||
                                                            !options ||
                                                            !selected
                                                        }
                                                        onClick={
                                                            submitAssociation
                                                        }
                                                    >
                                                        {association.processing ? (
                                                            <Spinner />
                                                        ) : (
                                                            <Link2 />
                                                        )}
                                                        Associa
                                                    </Button>
                                                    {!options &&
                                                    !list.processing ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            disabled={busy}
                                                            onClick={() =>
                                                                void loadCompanies()
                                                            }
                                                        >
                                                            <RefreshCw />
                                                            Carica aziende
                                                        </Button>
                                                    ) : null}
                                                    {confirmed ? (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            disabled={busy}
                                                            onClick={() =>
                                                                setEditCompany(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Annulla
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ) : null}
                                    </Step>
                                    <Step
                                        number={3}
                                        title="Sincronizzazione"
                                        state={syncStep}
                                        last
                                        summary={
                                            syncStep === 'done' &&
                                            sync?.synced_at ? (
                                                <>
                                                    {sync.error ? (
                                                        <span className="text-destructive">
                                                            {sync.error}
                                                        </span>
                                                    ) : (
                                                        'Attiva · ogni ora'
                                                    )}
                                                    <br />
                                                    Ultima lettura{' '}
                                                    {formatDateTime(
                                                        sync.synced_at,
                                                    )}
                                                </>
                                            ) : null
                                        }
                                        action={
                                            syncStep !== 'locked' ? (
                                                <Button
                                                    variant={
                                                        syncStep === 'done'
                                                            ? 'outline'
                                                            : 'default'
                                                    }
                                                    size={
                                                        syncStep === 'done'
                                                            ? 'sm'
                                                            : 'default'
                                                    }
                                                    disabled={busy}
                                                    onClick={synchronize}
                                                >
                                                    {acquire.processing ? (
                                                        <Spinner />
                                                    ) : (
                                                        <RefreshCw />
                                                    )}
                                                    {syncStep === 'done'
                                                        ? 'Sincronizza ora'
                                                        : 'Avvia sincronizzazione'}
                                                </Button>
                                            ) : null
                                        }
                                    >
                                        {syncStep === 'done' && counts ? (
                                            <div className="flex flex-wrap items-center gap-3">
                                                <span className="text-sm text-muted-foreground">
                                                    {counts.pending} da decidere
                                                    · {counts.linked} apparati ·{' '}
                                                    {counts.ipam} client IPAM
                                                </span>
                                            </div>
                                        ) : null}
                                    </Step>
                                </ol>
                            </CardContent>
                        </Card>
                        {settings.connection && !settings.use_global ? (
                            <Collapsible>
                                <CollapsibleTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="group self-start"
                                    >
                                        <SlidersHorizontal data-icon="inline-start" />
                                        Mappatura campi del server dedicato
                                        <ChevronDown
                                            data-icon="inline-end"
                                            className="transition-transform group-data-[state=open]:rotate-180"
                                        />
                                    </Button>
                                </CollapsibleTrigger>
                                <CollapsibleContent className="pt-2">
                                    <SnipeItFields
                                        company={company.id}
                                        revision={settings.revision}
                                        globalRevision={
                                            settings.global_revision
                                        }
                                        inherited={false}
                                        disabled={busy || changed}
                                        active
                                    />
                                </CollapsibleContent>
                            </Collapsible>
                        ) : null}
                    </div>
                </IntegrationTabs>
            </div>
            {dialog}
        </>
    );
}

SnipeIt.layout = ({ company }: Props) => ({
    breadcrumbs: company
        ? []
        : [
              { title: 'Amministrazione', href: administration() },
              { title: 'Integrazioni', href: integrationIndex() },
              { title: 'Snipe-IT', href: globalRoutes.edit() },
          ],
});
