import { useForm, useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { ArrowRight, RefreshCw } from 'lucide-react';
import { SaveButton } from '@/components/save-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FieldError } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import * as globalRoutes from '@/routes/administration/integrations/snipe-it';
import * as companyRoutes from '@/routes/companies/settings/integrations/snipe-it';

type Mapping = {
    ip: string;
    mac: string | null;
    name: string;
    description: string | null;
};
type Option = { value: string; label: string };
type Fields = { fields: Option[]; mapping: Mapping };

const targets: { key: keyof Mapping; label: string; required: boolean }[] = [
    { key: 'name', label: 'Nome dispositivo', required: true },
    { key: 'ip', label: 'Indirizzo IP', required: true },
    { key: 'mac', label: 'MAC address', required: false },
    { key: 'description', label: 'Note del dispositivo', required: false },
];

/**
 * Maps Snipe-IT fields (standard and custom) onto the NetVibe fields read by
 * the sync. The mapping belongs to the connection in use.
 */
export function SnipeItFields({
    company,
    revision,
    globalRevision,
    shared = false,
    disabled = false,
}: {
    company?: number;
    revision: number;
    globalRevision: number;
    shared?: boolean;
    disabled?: boolean;
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
    const [options, setOptions] = useState<Option[] | null>(null);
    const [saved, setSaved] = useState<Mapping | null>(null);
    const [error, setError] = useState('');
    const requested = useRef(false);
    const busy = disabled || request.processing || form.processing;
    const standard = options?.filter((o) => !o.value.startsWith('custom:'));
    const custom = options?.filter((o) => o.value.startsWith('custom:'));
    const dirty =
        !!saved &&
        JSON.stringify(saved) !== JSON.stringify(form.data.field_mapping);

    async function load() {
        setError('');
        form.clearErrors();
        try {
            const result = await request.submit(
                company ? companyRoutes.fields(company) : globalRoutes.fields(),
            );
            setOptions(result.fields);
            if (!saved) {
                setSaved(result.mapping);
                form.setData('field_mapping', result.mapping);
            }
        } catch {
            setError('Campi non caricati. Verifica la connessione e riprova.');
        }
    }

    const loadOnOpen = useEffectEvent(load);
    useEffect(() => {
        if (!disabled && !requested.current) {
            requested.current = true;
            void loadOnOpen();
        }
    }, [disabled]);

    function select(key: keyof Mapping, value: string) {
        form.setData('field_mapping', {
            ...form.data.field_mapping,
            [key]: value === 'none' ? null : value,
        });
        form.clearErrors(`field_mapping.${key}`);
    }

    const failure =
        form.errors.connection || request.errors.connection || error;

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
                <CardHeader className="flex-row flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1.5">
                        <CardTitle>Mappatura campi</CardTitle>
                        {shared ? (
                            <CardDescription>
                                Connessione globale: la mappatura vale per tutte
                                le aziende che la usano.
                            </CardDescription>
                        ) : null}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() => void load()}
                    >
                        {request.processing ? <Spinner /> : <RefreshCw />}
                        {error ? 'Riprova' : 'Aggiorna campi'}
                    </Button>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    {failure ? (
                        <Alert variant="destructive">
                            <AlertDescription>{failure}</AlertDescription>
                        </Alert>
                    ) : null}
                    {!options && !error ? (
                        <div
                            role="status"
                            className="flex items-center gap-2 py-6 text-sm text-muted-foreground"
                        >
                            <Spinner />
                            Caricamento campi Snipe-IT…
                        </div>
                    ) : null}
                    {options ? (
                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableHead>Campo Snipe-IT</TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Verso
                                            </span>
                                        </TableHead>
                                        <TableHead>Campo NetVibe</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {targets.map(({ key, label, required }) => {
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
                                        const id = `snipe-field-${key}`;
                                        return (
                                            <TableRow
                                                key={key}
                                                className="hover:bg-transparent"
                                            >
                                                <TableCell className="w-1/2 py-3 align-top whitespace-normal">
                                                    <Select
                                                        value={value ?? 'none'}
                                                        disabled={busy}
                                                        onValueChange={(next) =>
                                                            select(key, next)
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            id={id}
                                                            className="w-full"
                                                            aria-label={`Campo Snipe-IT per ${label}`}
                                                            aria-invalid={
                                                                !!fieldError ||
                                                                missing
                                                            }
                                                            aria-describedby={`${id}-error`}
                                                        >
                                                            <SelectValue placeholder="Scegli campo" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {!required ? (
                                                                <>
                                                                    <SelectItem value="none">
                                                                        Non
                                                                        sincronizzare
                                                                    </SelectItem>
                                                                    <SelectSeparator />
                                                                </>
                                                            ) : null}
                                                            {missing ? (
                                                                <SelectItem
                                                                    value={
                                                                        value
                                                                    }
                                                                    disabled
                                                                >
                                                                    Campo non
                                                                    più
                                                                    disponibile
                                                                </SelectItem>
                                                            ) : null}
                                                            <SelectGroup>
                                                                <SelectLabel>
                                                                    Campi
                                                                    standard
                                                                </SelectLabel>
                                                                {standard!.map(
                                                                    (
                                                                        option,
                                                                    ) => (
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
                                                            {custom!.length ? (
                                                                <>
                                                                    <SelectSeparator />
                                                                    <SelectGroup>
                                                                        <SelectLabel>
                                                                            Campi
                                                                            personalizzati
                                                                        </SelectLabel>
                                                                        {custom!.map(
                                                                            (
                                                                                option,
                                                                            ) => (
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
                                                                </>
                                                            ) : null}
                                                        </SelectContent>
                                                    </Select>
                                                    <FieldError
                                                        id={`${id}-error`}
                                                        className="mt-1.5"
                                                    >
                                                        {fieldError ??
                                                            (missing
                                                                ? 'Il campo non esiste più in Snipe-IT. Scegline un altro.'
                                                                : null)}
                                                    </FieldError>
                                                </TableCell>
                                                <TableCell className="py-3 align-top">
                                                    <ArrowRight
                                                        aria-hidden="true"
                                                        className="mt-3 size-4 text-muted-foreground"
                                                    />
                                                </TableCell>
                                                <TableCell className="py-3 align-top whitespace-normal">
                                                    <div className="flex min-h-10 flex-wrap items-center gap-2">
                                                        <span className="font-medium">
                                                            {label}
                                                        </span>
                                                        {required ? (
                                                            <Badge variant="outline">
                                                                Obbligatorio
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    ) : null}
                </CardContent>
                {options ? (
                    <CardFooter>
                        <SaveButton
                            processing={form.processing}
                            saved={!dirty}
                            disabled={busy}
                        >
                            Salva mappatura
                        </SaveButton>
                    </CardFooter>
                ) : null}
            </Card>
        </form>
    );
}
