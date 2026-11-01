import { useEffect, useState } from 'react';
import { mikrotikPorts } from '@/actions/App/Http/Controllers/ModelPanelController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { CatalogModel } from '@/lib/hardware-editor/catalog-storage';
import { connectorTypes } from '@/lib/hardware-editor/document';
import { sortTableRows } from '@/lib/table-sort';
import type { Editor } from './use-editor';

type Mapping = {
    observed: boolean;
    interfaces: { name: string; type: string | null }[];
    matches: Record<string, string>;
};

export function MikrotikPortsPanel({
    editor: e,
    model,
}: {
    editor: Editor;
    model: CatalogModel;
}) {
    const allPorts = [
        ...e.doc.faces.front.ports,
        ...e.doc.faces.rear.ports,
    ].filter((port) => ['rj45', 'sfp', 'qsfp'].includes(port.type));
    const payload = JSON.stringify({
        ports: allPorts.map(({ id, type, label, mikrotikName }) => ({
            id,
            type,
            label,
            mikrotikName,
        })),
    });
    const [result, setResult] = useState<{
        payload: string;
        data: Mapping;
    } | null>(null);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const loading = result?.payload !== payload;
    const data = result?.data;
    const ports = sortTableRows(
        e.ports.filter((port) => ['rj45', 'sfp', 'qsfp'].includes(port.type)),
        { column: 'label', direction: 'asc' },
        (port) => port.label,
    );

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setError('');
            try {
                const token = document.cookie
                    .split('; ')
                    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                    ?.slice('XSRF-TOKEN='.length);
                const response = await fetch(
                    mikrotikPorts.url({
                        manufacturer: model.manufacturer_id,
                        model: model.id,
                    }),
                    {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                        },
                        body: payload,
                        signal: controller.signal,
                    },
                );
                if (!response.ok)
                    throw new Error(
                        'Impossibile caricare le interfacce MikroTik. Riprova.',
                    );
                const mapping: Mapping = await response.json();
                if (!controller.signal.aborted)
                    setResult({ payload, data: mapping });
            } catch (error) {
                if (!controller.signal.aborted)
                    setError(
                        error instanceof Error
                            ? error.message
                            : 'Lettura non riuscita.',
                    );
            }
        }, 150);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [payload, model.id, model.manufacturer_id, retry]);

    return (
        <div className="flex h-full min-h-0 flex-col gap-4 overflow-y-auto p-4">
            {error && (
                <Alert variant="destructive">
                    <AlertDescription>
                        {error}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setRetry((value) => value + 1)}
                        >
                            Riprova
                        </Button>
                    </AlertDescription>
                </Alert>
            )}
            {data && !data.observed && (
                <Badge variant="outline" className="self-start">
                    Nomi standard
                </Badge>
            )}
            <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] gap-3 text-xs font-medium text-muted-foreground">
                <span>Porta NetVibe</span>
                <span>Interfaccia MikroTik</span>
            </div>
            {ports.map((port) => {
                const current =
                    port.mikrotikName === null
                        ? 'none'
                        : (port.mikrotikName ??
                          data?.matches[port.id] ??
                          'unassigned');
                const options = (data?.interfaces ?? []).filter(
                    (item) => item.type === null || item.type === port.type,
                );
                if (
                    port.mikrotikName &&
                    !options.some((item) => item.name === port.mikrotikName)
                )
                    options.push({ name: port.mikrotikName, type: port.type });
                return (
                    <div
                        key={port.id}
                        className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] items-center gap-3"
                    >
                        <Button
                            variant={
                                e.selection.ids.includes(port.id)
                                    ? 'secondary'
                                    : 'ghost'
                            }
                            className="h-auto min-w-0 flex-col items-start gap-1 px-2 py-2"
                            onClick={() => {
                                e.setEnteredGroup(port.group);
                                e.setSelection({
                                    kind: 'ports',
                                    ids: [port.id],
                                });
                            }}
                        >
                            <span className="max-w-full truncate">
                                {port.label || 'Senza numero'}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {connectorTypes[port.type].name}
                            </span>
                        </Button>
                        <Field>
                            <FieldLabel
                                className="sr-only"
                                htmlFor={`mikrotik-port-${port.id}`}
                            >
                                Interfaccia MikroTik per{' '}
                                {port.label || connectorTypes[port.type].name}
                            </FieldLabel>
                            <Select
                                value={current}
                                disabled={
                                    loading ||
                                    !!error ||
                                    e.saving ||
                                    e.loading ||
                                    !!e.crop
                                }
                                onValueChange={(value) => {
                                    e.setPortList(
                                        e.ports.map((item) =>
                                            item.id === port.id
                                                ? {
                                                      ...item,
                                                      mikrotikName:
                                                          value === 'auto'
                                                              ? undefined
                                                              : value === 'none'
                                                                ? null
                                                                : value,
                                                  }
                                                : item,
                                        ),
                                    );
                                }}
                            >
                                <SelectTrigger
                                    id={`mikrotik-port-${port.id}`}
                                    className="w-full min-w-0"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="unassigned" disabled>
                                            {loading
                                                ? 'Caricamento…'
                                                : 'Da associare'}
                                        </SelectItem>
                                        <SelectItem value="auto">
                                            Ripristina automatica
                                        </SelectItem>
                                        <SelectItem value="none">
                                            Nessuna
                                        </SelectItem>
                                        {options.map((item) => (
                                            <SelectItem
                                                key={item.name}
                                                value={item.name}
                                                disabled={allPorts.some(
                                                    (other) =>
                                                        other.id !== port.id &&
                                                        other.mikrotikName ===
                                                            item.name,
                                                )}
                                            >
                                                {item.name}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>
                );
            })}
            {!ports.length && (
                <p className="text-sm text-muted-foreground">
                    Nessuna porta di rete su questa faccia.
                </p>
            )}
        </div>
    );
}
