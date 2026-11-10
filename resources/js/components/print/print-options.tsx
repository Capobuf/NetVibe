import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    type ClientRect,
    type UniqueIdentifier,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    ChevronDown,
    Eye,
    EyeOff,
    GripVertical,
    RotateCcw,
    type LucideIcon,
} from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field, FieldLabel } from '@/components/ui/field';
import { Toggle } from '@/components/ui/toggle';
import { cn } from '@/lib/utils';

export function ResetButton({
    label,
    onClick,
}: {
    label: string;
    onClick: () => void;
}) {
    return (
        <IconButton
            label={`Ripristina ${label.toLowerCase()}`}
            variant="ghost"
            onClick={onClick}
        >
            <RotateCcw />
        </IconButton>
    );
}

function VisibilityToggle({
    label,
    visible,
    disabled,
    onChange,
}: {
    label: string;
    visible: boolean;
    disabled?: boolean;
    onChange: (visible: boolean) => void;
}) {
    return (
        <Toggle
            aria-label={`Mostra ${label.toLowerCase()}`}
            title={`${visible ? 'Nascondi' : 'Mostra'} ${label.toLowerCase()}`}
            pressed={visible}
            disabled={disabled}
            onPressedChange={onChange}
        >
            {visible ? <Eye /> : <EyeOff />}
        </Toggle>
    );
}

export function VisibilityField({
    label,
    visible,
    disabled,
    onChange,
}: {
    label: string;
    visible: boolean;
    disabled?: boolean;
    onChange: (visible: boolean) => void;
}) {
    return (
        <Field orientation="horizontal" data-disabled={disabled}>
            <FieldLabel className="flex-1">{label}</FieldLabel>
            <VisibilityToggle
                label={label}
                visible={visible}
                disabled={disabled}
                onChange={onChange}
            />
        </Field>
    );
}

export function OptionGroup({
    icon: Icon,
    label,
    onReset,
    defaultOpen,
    children,
}: {
    icon: LucideIcon;
    label: string;
    onReset: () => void;
    defaultOpen?: boolean;
    children: ReactNode;
}) {
    return (
        <Collapsible defaultOpen={defaultOpen} asChild>
            <section className="flex flex-col gap-3 border-b pb-3 last:border-b-0">
                <div className="flex items-center gap-1">
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            className="group min-w-0 flex-1 justify-start"
                        >
                            <Icon data-icon="inline-start" />
                            {label}
                            <ChevronDown className="ml-auto group-data-[state=open]:rotate-180" />
                        </Button>
                    </CollapsibleTrigger>
                    <ResetButton label={label} onClick={onReset} />
                </div>
                <CollapsibleContent className="flex flex-col gap-3 px-2 pb-1">
                    {children}
                </CollapsibleContent>
            </section>
        </Collapsible>
    );
}

export type SortableOption = {
    id: string;
    label: string;
    visible: boolean;
    canHide?: boolean;
    actions?: ReactNode;
    content?: ReactNode;
};

// Expanded entries have different heights: compare them by their headers.
function headerRects(rects: Map<UniqueIdentifier, ClientRect>) {
    return new Map(
        [...rects].map(([id, rect]) => [id, { ...rect, height: 0 }]),
    );
}

function SortableEntry({
    option,
    onVisibilityChange,
}: {
    option: SortableOption;
    onVisibilityChange: (id: string, visible: boolean) => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: option.id });
    const expandable = option.visible && option.content;
    return (
        <Collapsible asChild>
            <li
                ref={setNodeRef}
                style={{
                    transform: CSS.Transform.toString(transform),
                    transition,
                }}
                className={cn(
                    'flex flex-col gap-2 rounded-md border bg-background p-1',
                    isDragging && 'relative z-10 opacity-60 shadow-md',
                )}
            >
                <div className="flex items-center gap-1">
                    <Button
                        ref={setActivatorNodeRef}
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="shrink-0 cursor-grab touch-none active:cursor-grabbing"
                        aria-label={`Trascina ${option.label}`}
                        title={`Trascina ${option.label} per riordinare`}
                        {...attributes}
                        {...listeners}
                    >
                        <GripVertical />
                    </Button>
                    {expandable ? (
                        <CollapsibleTrigger asChild>
                            <Button
                                variant="ghost"
                                className="group h-auto min-h-9 min-w-0 flex-1 justify-start px-2 text-left whitespace-normal"
                                aria-label={`Opzioni ${option.label}`}
                            >
                                {option.label}
                                <ChevronDown className="ml-auto group-data-[state=open]:rotate-180" />
                            </Button>
                        </CollapsibleTrigger>
                    ) : (
                        <span
                            className={cn(
                                'min-w-0 flex-1 px-2 text-sm',
                                !option.visible && 'text-muted-foreground',
                            )}
                        >
                            {option.label}
                        </span>
                    )}
                    {option.visible && option.actions}
                    <VisibilityToggle
                        label={option.label}
                        visible={option.visible}
                        disabled={option.canHide === false}
                        onChange={(visible) =>
                            onVisibilityChange(option.id, visible)
                        }
                    />
                </div>
                {expandable && (
                    <CollapsibleContent className="px-2 pb-2">
                        {option.content}
                    </CollapsibleContent>
                )}
            </li>
        </Collapsible>
    );
}

/** Visibility and drag-and-drop order for document sections or table columns. */
export function SortableOptions({
    options,
    label,
    onVisibilityChange,
    onOrderChange,
}: {
    options: SortableOption[];
    label: string;
    onVisibilityChange: (id: string, visible: boolean) => void;
    onOrderChange: (ids: string[]) => void;
}) {
    const id = useId();
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: (event, args) =>
                sortableKeyboardCoordinates(event, {
                    ...args,
                    context: {
                        ...args.context,
                        collisionRect: args.context.collisionRect && {
                            ...args.context.collisionRect,
                            height: 0,
                        },
                        droppableRects: headerRects(
                            args.context.droppableRects,
                        ),
                    },
                }),
        }),
    );
    const ids = options.map((option) => option.id);
    const name = (value: UniqueIdentifier) =>
        options.find((option) => option.id === value)?.label ?? String(value);
    const position = (value: UniqueIdentifier) =>
        `posizione ${ids.indexOf(String(value)) + 1} di ${ids.length}`;
    return (
        <DndContext
            id={id}
            sensors={sensors}
            modifiers={[({ transform }) => ({ ...transform, x: 0 })]}
            collisionDetection={(args) =>
                closestCenter({
                    ...args,
                    collisionRect: { ...args.collisionRect, height: 0 },
                    droppableRects: headerRects(args.droppableRects),
                })
            }
            accessibility={{
                screenReaderInstructions: {
                    draggable:
                        'Premi Spazio o Invio per iniziare il riordino, usa le frecce su e giù per spostare la voce, poi Spazio o Invio per confermare oppure Esc per annullare.',
                },
                announcements: {
                    onDragStart: ({ active }) =>
                        `${name(active.id)} selezionata.`,
                    onDragOver: ({ active, over }) =>
                        over
                            ? `${name(active.id)}: ${position(over.id)}.`
                            : 'Fuori dall’elenco.',
                    onDragEnd: ({ active, over }) =>
                        over
                            ? `${name(active.id)} spostata in ${position(over.id)}.`
                            : 'Riordino annullato.',
                    onDragCancel: () => 'Riordino annullato.',
                },
            }}
            onDragEnd={({ active, over }) => {
                if (!over || active.id === over.id) return;
                onOrderChange(
                    arrayMove(
                        ids,
                        ids.indexOf(String(active.id)),
                        ids.indexOf(String(over.id)),
                    ),
                );
            }}
        >
            <SortableContext items={ids} strategy={verticalListSortingStrategy}>
                <ul className="flex flex-col gap-2" aria-label={label}>
                    {options.map((option) => (
                        <SortableEntry
                            key={option.id}
                            option={option}
                            onVisibilityChange={onVisibilityChange}
                        />
                    ))}
                </ul>
            </SortableContext>
        </DndContext>
    );
}
