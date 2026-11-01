import { Head, useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { index as administration } from '@/routes/administration';
import { edit, update, test as sendTest } from '@/routes/administration/email';

type Settings = {
    host: string;
    port: number;
    security: 'none' | 'starttls' | 'tls';
    username: string | null;
    from_address: string;
    from_name: string;
};

function formData(settings: Settings | null) {
    return {
        host: settings?.host ?? '',
        port: String(settings?.port ?? 587),
        security: settings?.security ?? 'starttls',
        username: settings?.username ?? '',
        password: '',
        from_address: settings?.from_address ?? '',
        from_name: settings?.from_name ?? '',
    };
}

export default function Email({
    settings,
    hasPassword,
}: {
    settings: Settings | null;
    hasPassword: boolean;
}) {
    const form = useForm(formData(settings));
    const testForm = useForm({ recipient: '' });
    const busy = form.processing || testForm.processing;
    const canTest = settings !== null && !form.isDirty && !busy;

    return (
        <>
            <Head title="Email · Amministrazione" />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-medium tracking-tight">
                        Email
                    </h1>
                    <Badge variant="secondary">
                        {settings
                            ? 'SMTP configurato'
                            : 'Configurazione di sistema'}
                    </Badge>
                    {form.isDirty ? (
                        <Badge variant="outline">Modifiche non salvate</Badge>
                    ) : null}
                </div>
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.submit(update(), {
                                preserveScroll: true,
                                onSuccess: (page) => {
                                    const saved = formData(
                                        page.props.settings as Settings,
                                    );
                                    form.setData(saved);
                                    form.setDefaults(saved);
                                },
                            });
                        }}
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h3>Server SMTP</h3>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <FieldSet disabled={busy}>
                                    <FieldGroup>
                                        <Field
                                            data-invalid={!!form.errors.host}
                                        >
                                            <FieldLabel htmlFor="host">
                                                Host
                                            </FieldLabel>
                                            <Input
                                                id="host"
                                                name="host"
                                                value={form.data.host}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'host',
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                                maxLength={253}
                                                autoComplete="off"
                                                placeholder="smtp.example.com"
                                                aria-invalid={
                                                    !!form.errors.host
                                                }
                                                aria-describedby={
                                                    form.errors.host
                                                        ? 'host-error'
                                                        : undefined
                                                }
                                            />
                                            <FieldError id="host-error">
                                                {form.errors.host}
                                            </FieldError>
                                        </Field>
                                        <FieldGroup className="grid gap-5 sm:grid-cols-[8rem_minmax(0,1fr)]">
                                            <Field
                                                data-invalid={
                                                    !!form.errors.port
                                                }
                                            >
                                                <FieldLabel htmlFor="port">
                                                    Porta
                                                </FieldLabel>
                                                <Input
                                                    id="port"
                                                    name="port"
                                                    type="number"
                                                    min={1}
                                                    max={65535}
                                                    required
                                                    value={form.data.port}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'port',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!form.errors.port
                                                    }
                                                    aria-describedby={
                                                        form.errors.port
                                                            ? 'port-error'
                                                            : undefined
                                                    }
                                                />
                                                <FieldError id="port-error">
                                                    {form.errors.port}
                                                </FieldError>
                                            </Field>
                                            <Field
                                                data-invalid={
                                                    !!form.errors.security
                                                }
                                            >
                                                <FieldLabel htmlFor="security">
                                                    Sicurezza
                                                </FieldLabel>
                                                <Select
                                                    value={form.data.security}
                                                    onValueChange={(value) =>
                                                        form.setData(
                                                            'security',
                                                            value as Settings['security'],
                                                        )
                                                    }
                                                    disabled={busy}
                                                >
                                                    <SelectTrigger
                                                        id="security"
                                                        className="w-full"
                                                        aria-invalid={
                                                            !!form.errors
                                                                .security
                                                        }
                                                        aria-describedby={
                                                            form.errors.security
                                                                ? 'security-error'
                                                                : undefined
                                                        }
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            <SelectItem value="none">
                                                                Nessuna
                                                                cifratura
                                                            </SelectItem>
                                                            <SelectItem value="starttls">
                                                                STARTTLS
                                                            </SelectItem>
                                                            <SelectItem value="tls">
                                                                SSL/TLS
                                                            </SelectItem>
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                                <FieldError id="security-error">
                                                    {form.errors.security}
                                                </FieldError>
                                            </Field>
                                        </FieldGroup>
                                        <Separator />
                                        <FieldSet>
                                            <FieldLegend>
                                                Autenticazione
                                            </FieldLegend>
                                            <FieldGroup>
                                                <Field
                                                    data-invalid={
                                                        !!form.errors.username
                                                    }
                                                >
                                                    <FieldLabel htmlFor="username">
                                                        Nome utente
                                                        (facoltativo)
                                                    </FieldLabel>
                                                    <Input
                                                        id="username"
                                                        name="username"
                                                        value={
                                                            form.data.username
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'username',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        maxLength={255}
                                                        autoComplete="off"
                                                        aria-invalid={
                                                            !!form.errors
                                                                .username
                                                        }
                                                        aria-describedby={
                                                            form.errors.username
                                                                ? 'username-error'
                                                                : undefined
                                                        }
                                                    />
                                                    <FieldError id="username-error">
                                                        {form.errors.username}
                                                    </FieldError>
                                                </Field>
                                                <Field
                                                    data-invalid={
                                                        !!form.errors.password
                                                    }
                                                >
                                                    <FieldLabel htmlFor="password">
                                                        {hasPassword
                                                            ? 'Nuova password'
                                                            : 'Password (facoltativa)'}
                                                    </FieldLabel>
                                                    <PasswordInput
                                                        id="password"
                                                        name="password"
                                                        value={
                                                            form.data.password
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'password',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        maxLength={4096}
                                                        autoComplete="new-password"
                                                        aria-invalid={
                                                            !!form.errors
                                                                .password
                                                        }
                                                        aria-describedby="password-hint password-error"
                                                        disabled={busy}
                                                    />
                                                    {hasPassword ? (
                                                        <FieldDescription id="password-hint">
                                                            Password salvata.
                                                            Lascia vuoto per
                                                            conservarla.
                                                        </FieldDescription>
                                                    ) : null}
                                                    <FieldError id="password-error">
                                                        {form.errors.password}
                                                    </FieldError>
                                                </Field>
                                            </FieldGroup>
                                        </FieldSet>
                                        <Separator />
                                        <FieldSet>
                                            <FieldLegend>Mittente</FieldLegend>
                                            <FieldGroup className="grid gap-5 sm:grid-cols-2">
                                                <Field
                                                    data-invalid={
                                                        !!form.errors.from_name
                                                    }
                                                >
                                                    <FieldLabel htmlFor="from_name">
                                                        Nome
                                                    </FieldLabel>
                                                    <Input
                                                        id="from_name"
                                                        name="from_name"
                                                        value={
                                                            form.data.from_name
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'from_name',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        required
                                                        maxLength={255}
                                                        autoComplete="off"
                                                        aria-invalid={
                                                            !!form.errors
                                                                .from_name
                                                        }
                                                        aria-describedby={
                                                            form.errors
                                                                .from_name
                                                                ? 'from-name-error'
                                                                : undefined
                                                        }
                                                    />
                                                    <FieldError id="from-name-error">
                                                        {form.errors.from_name}
                                                    </FieldError>
                                                </Field>
                                                <Field
                                                    data-invalid={
                                                        !!form.errors
                                                            .from_address
                                                    }
                                                >
                                                    <FieldLabel htmlFor="from_address">
                                                        Indirizzo email
                                                    </FieldLabel>
                                                    <Input
                                                        id="from_address"
                                                        name="from_address"
                                                        type="email"
                                                        value={
                                                            form.data
                                                                .from_address
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'from_address',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        required
                                                        maxLength={255}
                                                        autoComplete="off"
                                                        aria-invalid={
                                                            !!form.errors
                                                                .from_address
                                                        }
                                                        aria-describedby={
                                                            form.errors
                                                                .from_address
                                                                ? 'from-address-error'
                                                                : undefined
                                                        }
                                                    />
                                                    <FieldError id="from-address-error">
                                                        {
                                                            form.errors
                                                                .from_address
                                                        }
                                                    </FieldError>
                                                </Field>
                                            </FieldGroup>
                                        </FieldSet>
                                    </FieldGroup>
                                </FieldSet>
                            </CardContent>
                            <CardFooter>
                                <Button
                                    type="submit"
                                    disabled={
                                        busy ||
                                        (settings !== null && !form.isDirty)
                                    }
                                >
                                    {form.processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : null}
                                    Salva impostazioni
                                </Button>
                            </CardFooter>
                        </Card>
                    </form>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (canTest)
                                testForm.submit(sendTest(), {
                                    preserveScroll: true,
                                });
                        }}
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h3>Email di prova</h3>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <FieldGroup>
                                    <Field
                                        data-invalid={
                                            !!testForm.errors.recipient
                                        }
                                        data-disabled={!canTest}
                                    >
                                        <FieldLabel htmlFor="recipient">
                                            Destinatario
                                        </FieldLabel>
                                        <Input
                                            id="recipient"
                                            name="recipient"
                                            type="email"
                                            value={testForm.data.recipient}
                                            onChange={(event) =>
                                                testForm.setData(
                                                    'recipient',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            maxLength={255}
                                            disabled={!canTest}
                                            aria-invalid={
                                                !!testForm.errors.recipient
                                            }
                                            aria-describedby="test-hint recipient-error"
                                        />
                                        <FieldDescription id="test-hint">
                                            {form.isDirty
                                                ? 'Salva le modifiche prima di inviare la prova.'
                                                : settings
                                                  ? 'Usa le impostazioni salvate.'
                                                  : 'Salva le impostazioni email per inviare la prova.'}
                                        </FieldDescription>
                                        <FieldError id="recipient-error">
                                            {testForm.errors.recipient}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </CardContent>
                            <CardFooter>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={!canTest}
                                >
                                    {testForm.processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Send data-icon="inline-start" />
                                    )}
                                    Invia email di prova
                                </Button>
                            </CardFooter>
                        </Card>
                    </form>
                </div>
            </div>
        </>
    );
}

Email.layout = {
    breadcrumbs: [
        { title: 'Amministrazione', href: administration() },
        { title: 'Email', href: edit() },
    ],
};
