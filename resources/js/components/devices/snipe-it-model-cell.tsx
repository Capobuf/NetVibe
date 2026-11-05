import {
    SnipeItPicker,
    type SnipeOption,
} from '@/components/devices/snipe-it-picker';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';

type ModelRow = {
    id: number;
    model: {
        name: string | null;
        manufacturer: string | null;
        category: string | null;
    } | null;
    model_local: SnipeOption | null;
    model_linked: boolean;
    model_ambiguous: boolean;
    model_problem: string | null;
};

/**
 * Controlled model cell of the Snipe-IT records table. Decisions, picked
 * objects and selection stay in the page; this only renders them.
 */
export function SnipeItModelCell({
    company,
    row,
    name,
    target,
    model,
    modelChosen,
    editable,
    changeModel,
    disabled,
    onModelChange,
    onChangeModelChange,
}: {
    company: number;
    row: ModelRow;
    name: string;
    // Existing NetVibe device chosen for a link action, if any.
    target: SnipeOption | null;
    model: SnipeOption | null;
    // True only when a model was explicitly picked on this page.
    modelChosen: boolean;
    editable: boolean;
    changeModel: boolean;
    disabled: boolean;
    onModelChange: (value: SnipeOption | null) => void;
    onChangeModelChange: (checked: boolean) => void;
}) {
    const different =
        !!target && (!target.model || target.model.id !== model?.id);

    return (
        <div className="flex min-w-48 flex-col gap-2">
            <span className="font-medium">{row.model?.name || '—'}</span>
            <span className="text-xs text-muted-foreground">
                {row.model?.manufacturer || '—'}
            </span>
            {row.model_problem ? (
                <div className="flex flex-col gap-1 text-xs">
                    <p className="text-destructive">{row.model_problem}</p>
                    <span>
                        Snipe-IT · {row.model?.category || 'Senza categoria'}
                    </span>
                    <span>
                        NetVibe · {row.model_local?.name} ·{' '}
                        {row.model_local?.detail || 'Senza tipologia'}
                    </span>
                </div>
            ) : null}
            {editable &&
            !row.model_linked &&
            (row.model_ambiguous || different || modelChosen) ? (
                <Field>
                    <FieldLabel>Modello NetVibe</FieldLabel>
                    <SnipeItPicker
                        company={company}
                        kind="models"
                        label={`Modello NetVibe per ${name}`}
                        value={model}
                        disabled={disabled}
                        onChange={onModelChange}
                    />
                </Field>
            ) : null}
            {editable && different ? (
                <Field orientation="horizontal">
                    <Checkbox
                        id={`change-model-${row.id}`}
                        checked={changeModel}
                        disabled={disabled}
                        onCheckedChange={(checked) =>
                            onChangeModelChange(checked === true)
                        }
                    />
                    <FieldLabel htmlFor={`change-model-${row.id}`}>
                        Cambia modello da{' '}
                        {target?.model?.name ?? 'nessun modello'} a{' '}
                        {model?.name ?? row.model?.name}
                    </FieldLabel>
                </Field>
            ) : null}
        </div>
    );
}
