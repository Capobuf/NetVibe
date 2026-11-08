import { useForm } from '@inertiajs/react';
import { Cloud, Server } from 'lucide-react';
import { store, update } from '@/actions/App/Http/Controllers/UnifiController';
import PasswordInput from '@/components/password-input';
import { SaveButton } from '@/components/save-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { unifiAccess, type UnifiConnection, type UnifiMode } from './types';

export function UnifiConnectionForm({
    connection,
    mode,
    companyId,
    onClose,
}: {
    connection?: UnifiConnection;
    mode: UnifiMode;
    companyId: number | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: connection?.name ?? '',
        mode,
        company_id: connection?.company_id ?? (mode === 'direct' ? companyId : null),
        server_url: connection?.server_url ?? '',
        api_key: '',
        allow_unverified_certificate:
            connection?.allow_unverified_certificate ?? false,
        enabled: connection?.enabled ?? true,
        revision: connection?.revision ?? '',
    });

    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>
                        {connection
                            ? `Modifica ${connection.name}`
                            : mode === 'cloud'
                              ? `Configura ${unifiAccess.cloud}`
                              : `Nuovo ${unifiAccess.direct}`}
                    </SheetTitle>
                    <SheetDescription className="flex items-center gap-2">
                        {mode === 'cloud' ? (
                            <Cloud className="size-4" />
                        ) : (
                            <Server className="size-4" />
                        )}
                        {mode === 'cloud'
                            ? 'Accesso a tutte le console dell’account tramite UniFi Site Manager'
                            : 'Accesso diretto a una console UniFi Network raggiungibile da NetVibe'}
                    </SheetDescription>
                </SheetHeader>
                <form
                    className="flex flex-1 flex-col gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(
                            connection ? update(connection.id) : store(),
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset('api_key');
                                    onClose();
                                },
                            },
                        );
                    }}
                >
                    <FieldSet disabled={form.processing} className="px-4">
                        <FieldGroup>
                            {mode === 'direct' ? (
                            <Field data-invalid={!!form.errors.name}>
                                    <FieldLabel htmlFor="unifi-name">
                                        Nome
                                    </FieldLabel>
                                    <Input
                                        id="unifi-name"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData('name', event.target.value)
                                        }
                                        required
                                        maxLength={255}
                                        autoComplete="off"
                                        aria-invalid={!!form.errors.name}
                                    />
                                    <FieldError>{form.errors.name}</FieldError>
                                </Field>
                            ) : null}
                            {mode === 'direct' ? (
                                <Field data-invalid={!!form.errors.server_url}>
                                    <FieldLabel htmlFor="unifi-url">
                                        Indirizzo dell’host (HTTPS)
                                    </FieldLabel>
                                    <Input
                                        id="unifi-url"
                                        type="url"
                                        value={form.data.server_url}
                                        onChange={(event) =>
                                            form.setData(
                                                'server_url',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        readOnly={!!connection}
                                        placeholder="https://192.168.1.1"
                                        maxLength={2048}
                                        autoComplete="off"
                                        aria-invalid={!!form.errors.server_url}
                                    />
                                    <FieldError>
                                        {form.errors.server_url}
                                    </FieldError>
                                </Field>
                            ) : null}
                            <Field data-invalid={!!form.errors.api_key}>
                                <FieldLabel htmlFor="unifi-key">
                                    {connection
                                        ? 'Sostituisci API key'
                                        : 'API key'}
                                </FieldLabel>
                                <PasswordInput
                                    id="unifi-key"
                                    value={form.data.api_key}
                                    hasSavedValue={connection?.has_key}
                                    onChange={(event) =>
                                        form.setData(
                                            'api_key',
                                            event.target.value,
                                        )
                                    }
                                    required={!connection}
                                    maxLength={8192}
                                    autoComplete="new-password"
                                    aria-invalid={!!form.errors.api_key}
                                    disabled={form.processing}
                                />
                                {connection ? (
                                    <FieldDescription>
                                        Lascia vuoto per conservare la chiave
                                        salvata.
                                    </FieldDescription>
                                ) : null}
                                <FieldError>{form.errors.api_key}</FieldError>
                            </Field>
                            {mode === 'direct' ? (
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="unifi-tls"
                                        checked={
                                            form.data
                                                .allow_unverified_certificate
                                        }
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'allow_unverified_certificate',
                                                checked === true,
                                            )
                                        }
                                        disabled={form.processing}
                                    />
                                    <FieldLabel htmlFor="unifi-tls">
                                        Consenti certificato non verificato
                                    </FieldLabel>
                                </Field>
                            ) : null}
                            {form.data.allow_unverified_certificate ? (
                                <Alert>
                                    <AlertDescription>
                                        L’identità del server non verrà
                                        verificata tramite il certificato TLS.
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                            <FieldError
                                errors={Object.entries(form.errors)
                                    .filter(
                                        ([key]) =>
                                            ![
                                                'name',
                                                'server_url',
                                                'api_key',
                                            ].includes(key),
                                    )
                                    .map(([, message]) => ({ message }))}
                            />
                        </FieldGroup>
                    </FieldSet>
                    <SheetFooter className="flex-row justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Annulla
                        </Button>
                        <SaveButton processing={form.processing}>
                            Salva
                        </SaveButton>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
