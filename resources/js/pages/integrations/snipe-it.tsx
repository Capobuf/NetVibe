import { SaveButton } from '@/components/save-button';
import { cn, formatDateTime } from '@/lib/utils';
import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useState, type ReactNode } from 'react';
import {
    Building2,
    ExternalLink,
    Link2,
    Server,
    RefreshCw,
    SlidersHorizontal,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
import { IntegrationTabs } from '@/components/integration-tabs';
import {
    IntegrationHeader,
    SectionTabs,
} from '@/components/integration-header';
import { SnipeItMark } from '@/components/snipe-it-mark';
import {
    SnipeItCompanyHeader,
    SnipeItSections,
} from '@/components/snipe-it-company';
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

/**
 * One configuration row: label on the left, current value in the middle and
 * its action on the right; the editor opens below the row, inside the card.
 */
function SettingRow({
    icon: Icon,
    label,
    locked = false,
    action,
    editor,
    children,
}: {
    icon: LucideIcon;
    label: string;
    locked?: boolean;
    action?: ReactNode;
    editor?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            aria-label={label}
            className={cn(
                'flex flex-col gap-4 px-5 py-4 sm:px-6',
                locked && 'text-muted-foreground',
            )}
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3 sm:flex-nowrap">
                <div className="flex w-full items-center gap-3 sm:w-48 sm:shrink-0">
                    <span
                        aria-hidden="true"
                        className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-muted/50 [&_svg]:size-4"
                    >
                        <Icon />
                    </span>
                    <span className="text-sm font-medium">{label}</span>
                </div>
                <div className="min-w-0 flex-1 text-sm">{children}</div>
                {action ? <div className="shrink-0">{action}</div> : null}
            </div>
            {editor ? (
                <div className="rounded-lg border bg-muted/30 p-4">
                    {editor}
                </div>
            ) : null}
        </section>
    );
}

function StatusLine({
    tone,
    children,
}: {
    tone: 'ok' | 'error' | 'warning' | 'muted';
    children: ReactNode;
}) {
    return (
        <span className="flex items-center gap-1.5 text-muted-foreground">
            <span
                aria-hidden="true"
                className={cn(
                    'size-1.5 shrink-0 rounded-full',
                    tone === 'ok' && 'bg-emerald-500',
                    tone === 'error' && 'bg-destructive',
                    tone === 'warning' && 'bg-amber-500',
                    tone === 'muted' && 'bg-muted-foreground',
                )}
            />
            {children}
        </span>
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
    const [showFields, setShowFields] = useState(false);
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
    const confirmed = settings.association?.confirmed ?? false;
    const connectionStep: StepState =
        settings.connection && !editConnection ? 'done' : 'current';
    const companyStep: StepState =
        connectionStep !== 'done'
            ? 'locked'
            : confirmed && !editCompany
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

    const checkLabel = lastCheck?.checked_at
        ? `Verificata il ${formatDateTime(lastCheck.checked_at)} · ${
              ('status' in lastCheck
                  ? lastCheck.status
                  : lastCheck.check_status) === 'ok'
                  ? 'riuscita'
                  : 'non riuscita'
          }`
        : 'Mai verificata';
    const checkOk =
        !!lastCheck &&
        ('status' in lastCheck ? lastCheck.status : lastCheck.check_status) ===
            'ok';

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
                        <IntegrationHeader
                            mark={<SnipeItMark className="size-5" />}
                            title="Snipe-IT"
                            state={
                                !settings.connection
                                    ? 'unconfigured'
                                    : lastCheck &&
                                        ('status' in lastCheck
                                            ? lastCheck.status
                                            : lastCheck.check_status) !==
                                            'ok' &&
                                        lastCheck.checked_at
                                      ? 'error'
                                      : 'active'
                            }
                            description={
                                settings.connection
                                    ? `${settings.connection.server_url} · ${checkLabel}`
                                    : 'Connessione globale non configurata'
                            }
                        />
                        <SectionTabs
                            label="Snipe-IT"
                            value={settings.connection ? tab : 'connection'}
                            onChange={onTabChange}
                            sections={[
                                {
                                    value: 'connection',
                                    label: 'Connessione',
                                    icon: Link2,
                                },
                                {
                                    value: 'fields',
                                    label: 'Mappatura campi',
                                    icon: SlidersHorizontal,
                                    disabled:
                                        !settings.connection || busy || changed,
                                },
                            ]}
                        />
                        {errorAlert}
                        {tab === 'fields' && settings.connection ? (
                            <SnipeItFields
                                revision={settings.revision}
                                globalRevision={settings.global_revision}
                                inherited={false}
                                disabled={busy || changed}
                                active
                            />
                        ) : (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    save();
                                }}
                            >
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Connessione globale
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-6">
                                        {connectionFields}
                                    </CardContent>
                                    <CardFooter className="flex flex-wrap gap-3">
                                        {connectionButtons}
                                    </CardFooter>
                                </Card>
                            </form>
                        )}
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
                    <SnipeItCompanyHeader
                        company={company.id}
                        state={{
                            connected: !!settings.connection,
                            association: settings.association
                                ? {
                                      name: settings.association
                                          .external_company_name,
                                      confirmed,
                                  }
                                : null,
                            sync,
                        }}
                    />
                    <SnipeItSections
                        company={company.id}
                        value="settings"
                        pending={counts?.pending}
                    />
                    <div className="flex max-w-3xl flex-col gap-4">
                        {errorAlert}
                        <Card className="gap-0 divide-y overflow-hidden py-0">
                            <SettingRow
                                icon={Server}
                                label="Connessione"
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
                                editor={
                                    connectionStep === 'current' ? (
                                        <form
                                            className="flex flex-col gap-5"
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
                                    ) : null
                                }
                            >
                                {settings.connection ? (
                                    <div className="flex flex-col gap-1">
                                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                                            <a
                                                href={
                                                    settings.connection
                                                        .server_url
                                                }
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex min-w-0 items-center gap-1.5 font-medium break-all text-foreground no-underline transition-colors hover:text-primary focus-visible:rounded-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                            >
                                                {settings.connection.server_url.replace(
                                                    /^https?:\/\//,
                                                    '',
                                                )}
                                                <ExternalLink
                                                    aria-hidden="true"
                                                    className="size-3.5 shrink-0 text-muted-foreground"
                                                />
                                                <span className="sr-only">
                                                    (apre Snipe-IT in una nuova
                                                    scheda)
                                                </span>
                                            </a>
                                            <Badge variant="outline">
                                                {settings.use_global
                                                    ? 'Globale'
                                                    : 'Dedicato'}
                                            </Badge>
                                        </div>
                                        <StatusLine
                                            tone={
                                                !lastCheck?.checked_at
                                                    ? 'muted'
                                                    : checkOk
                                                      ? 'ok'
                                                      : 'error'
                                            }
                                        >
                                            {!lastCheck?.checked_at
                                                ? 'Mai verificata'
                                                : `${checkOk ? 'Verificata' : 'Verifica non riuscita'} · ${formatDateTime(lastCheck.checked_at)}`}
                                        </StatusLine>
                                    </div>
                                ) : (
                                    <span className="text-muted-foreground">
                                        Non configurata
                                    </span>
                                )}
                            </SettingRow>
                            <SettingRow
                                icon={Building2}
                                label="Azienda Snipe-IT"
                                locked={companyStep === 'locked'}
                                action={
                                    companyStep === 'done' ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={busy}
                                            onClick={() => setEditCompany(true)}
                                        >
                                            Cambia
                                        </Button>
                                    ) : null
                                }
                                editor={
                                    companyStep === 'current' ? (
                                        <div className="flex flex-col gap-4">
                                            {companyPicker}
                                            <div className="flex flex-wrap gap-3">
                                                <Button
                                                    type="button"
                                                    disabled={
                                                        busy ||
                                                        !options ||
                                                        !selected
                                                    }
                                                    onClick={submitAssociation}
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
                                    ) : null
                                }
                            >
                                {settings.association ? (
                                    <div className="flex flex-col gap-1">
                                        <span className="font-medium text-foreground">
                                            {
                                                settings.association
                                                    .external_company_name
                                            }
                                        </span>
                                        {confirmed ? (
                                            settings.association
                                                .confirmed_at ? (
                                                <StatusLine tone="ok">
                                                    Associata ·{' '}
                                                    {formatDateTime(
                                                        settings.association
                                                            .confirmed_at,
                                                    )}
                                                </StatusLine>
                                            ) : null
                                        ) : (
                                            <StatusLine tone="warning">
                                                Da riconfermare
                                            </StatusLine>
                                        )}
                                    </div>
                                ) : (
                                    <span className="text-muted-foreground">
                                        Non associata
                                    </span>
                                )}
                            </SettingRow>
                            {settings.connection && !settings.use_global ? (
                                <SettingRow
                                    icon={SlidersHorizontal}
                                    label="Mappatura campi"
                                    action={
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            aria-expanded={showFields}
                                            onClick={() =>
                                                setShowFields(!showFields)
                                            }
                                        >
                                            {showFields ? 'Chiudi' : 'Modifica'}
                                        </Button>
                                    }
                                    editor={
                                        showFields ? (
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
                                        ) : null
                                    }
                                >
                                    <span className="text-muted-foreground">
                                        Server dedicato
                                    </span>
                                </SettingRow>
                            ) : null}
                        </Card>
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
