import { Check, Hash, MousePointer2, Undo2, X } from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import type { Numbering } from '@/lib/hardware-editor/document';
import { Choice, NumberField } from './editor-fields';
import type { Editor } from './use-editor';

export function NumberingPanel({
    editor: e,
    mode,
    options,
    setOptions,
    conflict,
    onApply,
    onClose,
    onFinish,
}: {
    editor: Editor;
    mode: 'quick' | 'click';
    options: Numbering;
    setOptions: (options: Numbering) => void;
    conflict: string | null;
    onApply: () => void;
    onClose: () => void;
    onFinish: () => void;
}) {
    const active = !!e.session;
    return (
        <div className="flex h-full flex-col">
            <div className="flex items-center justify-between gap-2 border-b p-3">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    {mode === 'quick' ? (
                        <Hash className="size-4" />
                    ) : (
                        <MousePointer2 className="size-4" />
                    )}
                    {mode === 'quick'
                        ? 'Numerazione rapida'
                        : 'Numera con clic'}
                </h2>
                <IconButton
                    label={
                        active ? 'Termina numerazione' : 'Chiudi numerazione'
                    }
                    variant="ghost"
                    onClick={onClose}
                    disabled={e.saving}
                >
                    <X />
                </IconButton>
            </div>
            <div className="flex flex-1 flex-col gap-5 overflow-y-auto p-4">
                {active ? (
                    <>
                        <Badge className="self-start">Numerazione attiva</Badge>
                        <div className="rounded-lg border bg-muted/30 p-4">
                            <p className="text-xs text-muted-foreground">
                                Prossima etichetta
                            </p>
                            <p className="mt-2 truncate text-3xl font-semibold tabular-nums">
                                {e.doc.numbering.prefix}
                                {e.doc.numbering.next}
                            </p>
                        </div>
                        <p className="text-sm tabular-nums" role="status">
                            {e.session!.steps.length} assegnazioni in questa
                            sessione
                        </p>
                        <NumberField
                            label="Prossimo numero"
                            value={e.doc.numbering.next}
                            min={0}
                            max={999999}
                            onChange={e.setNextNumber}
                            disabled={e.saving}
                        />
                        <Button
                            variant="outline"
                            disabled={!e.canUndo || e.saving}
                            onClick={e.undo}
                        >
                            <Undo2 />
                            Annulla ultima assegnazione
                        </Button>
                        <Button onClick={onFinish} disabled={e.saving}>
                            <Check />
                            Termina numerazione
                        </Button>
                    </>
                ) : (
                    <>
                        <FieldGroup>
                            <NumberField
                                label="Numero iniziale"
                                value={options.start}
                                min={0}
                                max={999999}
                                onChange={(start) =>
                                    setOptions({ ...options, start })
                                }
                            />
                            <Field className="gap-1.5">
                                <FieldLabel htmlFor="number-prefix">
                                    Prefisso
                                </FieldLabel>
                                <Input
                                    id="number-prefix"
                                    value={options.prefix}
                                    maxLength={24}
                                    onChange={(event) =>
                                        setOptions({
                                            ...options,
                                            prefix: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            {mode === 'quick' && (
                                <>
                                    <Choice
                                        label="Ordine"
                                        value={options.order}
                                        choices={{
                                            rows: 'Per righe',
                                            columns: 'Per colonne',
                                            snake: 'Serpentina',
                                        }}
                                        onChange={(order) =>
                                            setOptions({
                                                ...options,
                                                order: order as Numbering['order'],
                                            })
                                        }
                                    />
                                    <Choice
                                        label="Direzione orizzontale"
                                        value={
                                            options.reverseX ? 'right' : 'left'
                                        }
                                        choices={{
                                            left: 'Da sinistra',
                                            right: 'Da destra',
                                        }}
                                        onChange={(value) =>
                                            setOptions({
                                                ...options,
                                                reverseX: value === 'right',
                                            })
                                        }
                                    />
                                    <Choice
                                        label="Fila di partenza"
                                        value={
                                            options.reverseY ? 'bottom' : 'top'
                                        }
                                        choices={{
                                            top: 'Dall’alto',
                                            bottom: 'Dal basso',
                                        }}
                                        onChange={(value) =>
                                            setOptions({
                                                ...options,
                                                reverseY: value === 'bottom',
                                            })
                                        }
                                    />
                                </>
                            )}
                        </FieldGroup>
                        <Separator />
                        {mode === 'quick' && (
                            <Badge variant="secondary" className="self-start">
                                Anteprima su {e.selected.length} porte
                            </Badge>
                        )}
                        {conflict && (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    «{conflict}» è già assegnata. Cambia numero
                                    iniziale o prefisso.
                                </AlertDescription>
                            </Alert>
                        )}
                        <Button
                            onClick={onApply}
                            disabled={
                                !!conflict ||
                                (mode === 'quick' && !e.selected.length)
                            }
                        >
                            {mode === 'quick' ? (
                                <>
                                    <Check />
                                    Applica numerazione
                                </>
                            ) : (
                                <>
                                    <MousePointer2 />
                                    Inizia numerazione
                                </>
                            )}
                        </Button>
                    </>
                )}
            </div>
        </div>
    );
}
