import { useForm } from '@inertiajs/react';
import { ExternalLink, FileUp } from 'lucide-react';
import { useRef } from 'react';
import { CopyValue } from '@/components/copy-value';
import PasswordInput from '@/components/password-input';
import { SaveButton } from '@/components/save-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { settings } from '@/routes/administration/integrations/google-drive';
import type { DriveProject } from './types';

/** OAuth client and Picker key of the Google Cloud project, saved from the web. */
export function ProjectSheet({
    project,
    open,
    onOpenChange,
}: {
    project: DriveProject;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        client_id: project.client_id ?? '',
        client_secret: '',
        api_key: '',
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const importClient = async (file: File) => {
        try {
            const client = JSON.parse(await file.text())?.web;
            const id =
                typeof client?.client_id === 'string' ? client.client_id : '';
            if (!/^\d+-/.test(id) || typeof client.client_secret !== 'string') {
                throw new Error('Not a web OAuth client');
            }
            form.setData((data) => ({
                ...data,
                client_id: id,
                client_secret: client.client_secret,
            }));
            form.clearErrors('client_id', 'client_secret');
        } catch {
            form.setError(
                'client_id',
                'File non valido: scarica il JSON di un client OAuth di tipo applicazione web.',
            );
        }
    };
    const secret = (
        key: 'client_secret' | 'api_key',
        label: string,
        saved: boolean,
        link?: { href: string; label: string },
    ) => (
        <Field data-invalid={!!form.errors[key]}>
            <div className="flex items-center justify-between gap-3">
                <FieldLabel htmlFor={`google-${key}`}>{label}</FieldLabel>
                {link ? (
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="-my-1.5"
                    >
                        <a
                            href={link.href}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {link.label}
                            <ExternalLink data-icon="inline-end" />
                        </a>
                    </Button>
                ) : null}
            </div>
            <PasswordInput
                id={`google-${key}`}
                value={form.data[key]}
                hasSavedValue={saved}
                onChange={(event) => form.setData(key, event.target.value)}
                required={!saved}
                autoComplete="new-password"
                spellCheck={false}
                aria-invalid={!!form.errors[key]}
                disabled={form.processing}
            />
            <FieldError>{form.errors[key]}</FieldError>
        </Field>
    );

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) onOpenChange(next);
            }}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Progetto Google Cloud</SheetTitle>
                </SheetHeader>
                <form
                    className="flex flex-1 flex-col gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(settings().url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset('client_secret', 'api_key');
                                onOpenChange(false);
                            },
                        });
                    }}
                >
                    <div className="flex flex-col gap-6 px-4">
                        <FieldSet>
                            <div className="flex items-center justify-between gap-3">
                                <FieldLegend variant="label" className="mb-0">
                                    Client OAuth (applicazione web)
                                </FieldLegend>
                                <Button asChild variant="ghost" size="sm">
                                    <a
                                        href="https://console.cloud.google.com/auth/clients"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Google Cloud
                                        <ExternalLink data-icon="inline-end" />
                                    </a>
                                </Button>
                            </div>
                            <dl className="grid gap-3 rounded-lg border bg-muted/40 p-3 text-sm">
                                <div className="flex flex-col gap-1">
                                    <dt className="text-muted-foreground">
                                        Origine JavaScript autorizzata
                                    </dt>
                                    <dd>
                                        <CopyValue
                                            value={project.origin}
                                            className="font-mono text-xs"
                                        />
                                    </dd>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <dt className="text-muted-foreground">
                                        URI di reindirizzamento autorizzato
                                    </dt>
                                    <dd>
                                        <CopyValue
                                            value={project.redirect_uri}
                                            className="font-mono text-xs"
                                        />
                                    </dd>
                                </div>
                            </dl>
                        </FieldSet>
                        <Separator />
                        <FieldSet disabled={form.processing}>
                            <div className="flex items-center justify-between gap-3">
                                <FieldLegend variant="label" className="mb-0">
                                    Credenziali
                                </FieldLegend>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => fileInput.current?.click()}
                                >
                                    <FileUp data-icon="inline-start" />
                                    Importa JSON
                                </Button>
                                <input
                                    ref={fileInput}
                                    type="file"
                                    accept="application/json,.json"
                                    className="hidden"
                                    tabIndex={-1}
                                    aria-hidden="true"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0];
                                        event.target.value = '';
                                        if (file) void importClient(file);
                                    }}
                                />
                            </div>
                            <FieldGroup>
                                <Field data-invalid={!!form.errors.client_id}>
                                    <FieldLabel htmlFor="google-client_id">
                                        ID client
                                    </FieldLabel>
                                    <Input
                                        id="google-client_id"
                                        value={form.data.client_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'client_id',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        autoComplete="off"
                                        spellCheck={false}
                                        placeholder="…apps.googleusercontent.com"
                                        aria-invalid={!!form.errors.client_id}
                                    />
                                    <FieldError>
                                        {form.errors.client_id}
                                    </FieldError>
                                </Field>
                                {secret(
                                    'client_secret',
                                    'Client secret',
                                    project.has_secret,
                                )}
                                {secret(
                                    'api_key',
                                    'Chiave API (Google Picker)',
                                    project.has_api_key,
                                    {
                                        href: 'https://console.cloud.google.com/apis/credentials',
                                        label: 'Chiavi API',
                                    },
                                )}
                            </FieldGroup>
                        </FieldSet>
                    </div>
                    <SheetFooter className="mt-auto flex-row justify-end border-t">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => onOpenChange(false)}
                        >
                            Annulla
                        </Button>
                        <SaveButton
                            processing={form.processing}
                            className="min-w-0"
                        />
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
