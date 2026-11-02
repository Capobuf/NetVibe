import { Link, useForm, useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { SaveButton } from '@/components/save-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    CardFooter,
} from '@/components/ui/card';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldError,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import * as globalRoutes from '@/routes/administration/integrations/snipe-it';
import * as companyRoutes from '@/routes/companies/settings/integrations/snipe-it';

type Mapping = {
    ip: string;
    mac: string | null;
    name: string;
    description: string | null;
};
type Fields = { fields: { value: string; label: string }[]; mapping: Mapping };

const labels = {
    ip: 'Indirizzo IP',
    mac: 'MAC address',
    name: 'Nome dispositivo',
    description: 'Note del dispositivo',
} as const;

export function SnipeItFields({
    company,
    revision,
    globalRevision,
    inherited,
    disabled,
    active,
}: {
    company?: number;
    revision: number;
    globalRevision: number;
    inherited: boolean;
    disabled: boolean;
    active: boolean;
}) {
    const revisions = {
        revision,
        global_revision: globalRevision,
        connection: '',
    };
    const request = useHttp<typeof revisions, Fields>(revisions);
    const form = useForm({
        ...revisions,
        field_mapping: {
            ip: '',
            mac: null,
            name: 'name',
            description: null,
        } as Mapping,
    });
    const [options, setOptions] = useState<Fields['fields'] | null>(null);
    const [error, setError] = useState('');
    const requested = useRef(false);
    const busy = disabled || request.processing || form.processing;

    async function load() {
        setError('');
        form.clearErrors();
        try {
            const result = await request.submit(
                company ? companyRoutes.fields(company) : globalRoutes.fields(),
            );
            setOptions(result.fields);
            if (!options) form.setData('field_mapping', result.mapping);
        } catch {
            setError('Campi non caricati. Verifica la connessione e riprova.');
        }
    }

    const loadOnOpen = useEffectEvent(load);
    useEffect(() => {
        if (active && !inherited && !disabled && !requested.current) {
            requested.current = true;
            void loadOnOpen();
        }
    }, [active, inherited, disabled]);

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(
                    company
                        ? companyRoutes.mapFields(company)
                        : globalRoutes.mapFields(),
                    { preserveScroll: true },
                );
            }}
        >
            <Card>
                <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                    <CardTitle>Mappatura Campi</CardTitle>
                    {!inherited ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => void load()}
                        >
                            {request.processing ? <Spinner /> : <RefreshCw />}
                            {error ? 'Riprova' : 'Aggiorna elenco'}
                        </Button>
                    ) : null}
                </CardHeader>
                {inherited ? (
                    <CardContent>
                        <Button asChild variant="outline">
                            <Link href={globalRoutes.edit()}>
                                Configura i campi della connessione globale
                            </Link>
                        </Button>
                    </CardContent>
                ) : (
                    <>
                        <CardContent className="flex flex-col gap-5">
                            {!options && !error ? (
                                <div
                                    role="status"
                                    className="flex items-center gap-2 py-6 text-sm text-muted-foreground"
                                >
                                    <Spinner />
                                    Caricamento campi…
                                </div>
                            ) : null}
                            {form.errors.connection ||
                            request.errors.connection ||
                            error ? (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        {form.errors.connection ||
                                            request.errors.connection ||
                                            error}
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                            {options ? (
                                <FieldGroup className="grid gap-5 sm:grid-cols-2">
                                    {(
                                        Object.keys(labels) as (keyof Mapping)[]
                                    ).map((key) => {
                                        const value =
                                            form.data.field_mapping[key];
                                        const missing =
                                            value !== null &&
                                            !options.some(
                                                (option) =>
                                                    option.value === value,
                                            );
                                        const fieldError =
                                            form.errors[`field_mapping.${key}`];
                                        return (
                                            <Field
                                                key={key}
                                                data-invalid={!!fieldError}
                                                data-disabled={busy}
                                            >
                                                <FieldLabel
                                                    htmlFor={`snipe-field-${key}`}
                                                >
                                                    {labels[key]}
                                                </FieldLabel>
                                                <Select
                                                    value={value ?? 'none'}
                                                    disabled={busy}
                                                    onValueChange={(
                                                        selected,
                                                    ) => {
                                                        form.setData(
                                                            'field_mapping',
                                                            {
                                                                ...form.data
                                                                    .field_mapping,
                                                                [key]:
                                                                    selected ===
                                                                    'none'
                                                                        ? null
                                                                        : selected,
                                                            },
                                                        );
                                                        form.clearErrors(
                                                            `field_mapping.${key}`,
                                                        );
                                                    }}
                                                >
                                                    <SelectTrigger
                                                        id={`snipe-field-${key}`}
                                                        className="w-full"
                                                        aria-invalid={
                                                            !!fieldError
                                                        }
                                                        aria-describedby={`snipe-field-${key}-error`}
                                                    >
                                                        <SelectValue placeholder="Scegli campo" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            {key === 'mac' ||
                                                            key ===
                                                                'description' ? (
                                                                <SelectItem value="none">
                                                                    Non
                                                                    sincronizzare
                                                                </SelectItem>
                                                            ) : null}
                                                            {missing ? (
                                                                <SelectItem
                                                                    value={
                                                                        value
                                                                    }
                                                                    disabled
                                                                >
                                                                    Campo non
                                                                    disponibile
                                                                </SelectItem>
                                                            ) : null}
                                                            {options.map(
                                                                (option) => (
                                                                    <SelectItem
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                                <FieldError
                                                    id={`snipe-field-${key}-error`}
                                                >
                                                    {fieldError}
                                                </FieldError>
                                            </Field>
                                        );
                                    })}
                                </FieldGroup>
                            ) : null}
                        </CardContent>
                        {options ? (
                            <CardFooter>
                                <SaveButton
                                    processing={form.processing}
                                    disabled={busy}
                                >
                                    Salva mappatura
                                </SaveButton>
                            </CardFooter>
                        ) : null}
                    </>
                )}
            </Card>
        </form>
    );
}
