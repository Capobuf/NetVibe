import { Form, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { PDFDocumentProxy } from 'pdfjs-dist';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import InputError from '@/components/input-error';
import { RackFloorField } from '@/components/racks/rack-floor-field';
import { SaveButton } from '@/components/save-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { openPdf, renderPdfPage } from '@/lib/floor-plan-pdf';
import { cn } from '@/lib/utils';
import { destroy, plans, store, update } from '@/routes/companies/sites/floors';
import type { Floor, FloorListItem } from '@/types/floor';

type Route = { company: number; site: number };
type DialogProps = Route & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function Footer({
    processing,
    disabled,
    label,
}: {
    processing: boolean;
    disabled?: boolean;
    label: string;
}) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline" disabled={processing}>
                    Annulla
                </Button>
            </DialogClose>
            <SaveButton processing={processing} disabled={disabled}>
                {label}
            </SaveButton>
        </DialogFooter>
    );
}

/** Creates a floor, or changes the code of an existing one. */
export function FloorDialog({
    company,
    site,
    floor,
    open,
    onOpenChange,
}: DialogProps & { floor?: Floor }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {floor ? 'Modifica piano' : 'Nuovo piano'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...(floor
                        ? update.form({ company, site, floor: floor.id })
                        : store.form({ company, site }))}
                    onSuccess={() => onOpenChange(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            {floor && (
                                <input
                                    type="hidden"
                                    name="revision"
                                    value={floor.revision}
                                />
                            )}
                            <RackFloorField
                                defaultValue={floor?.code}
                                required
                                autoFocus
                                disabled={processing}
                                error={errors.floor}
                            />
                            <InputError message={errors.revision} />
                            <Footer
                                processing={processing}
                                label={floor ? 'Salva' : 'Crea piano'}
                            />
                        </FieldGroup>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

type Page = {
    number: number;
    label: string;
    preview: string;
    width: number;
    height: number;
    floor: string;
    /** The chosen image; PDF pages are rendered at full size only when uploaded. */
    source: File | null;
};

const FLOOR_SUGGESTIONS = ['PT', 'P1', 'P2', 'P3', 'P-1', 'P-2'];

/**
 * Uploads floor plans: an image goes to one floor, each page of a PDF to the
 * floor typed beside it. A code that does not exist yet creates the floor.
 */
export function FloorPlansDialog({
    company,
    site,
    floors,
    floor,
    open,
    onOpenChange,
}: DialogProps & { floors: FloorListItem[]; floor?: Floor }) {
    const id = useId();
    const pdf = useRef<PDFDocumentProxy | null>(null);
    const [pages, setPages] = useState<Page[]>([]);
    const [reading, setReading] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitted, setSubmitted] = useState<number[]>([]);
    const [positions, setPositions] = useState('');
    const byCode = new Map(floors.map((item) => [item.code, item]));
    // Releases the previews once they are replaced or the dialog closes.
    const previews = pages.map((page) => page.preview).join(' ');
    useEffect(
        () => () =>
            previews
                .split(' ')
                .filter(Boolean)
                .forEach((url) => URL.revokeObjectURL(url)),
        [previews],
    );
    useEffect(() => () => void pdf.current?.loadingTask.destroy(), []);

    async function choose(file: File | undefined) {
        setErrors({});
        setPositions('');
        setPages([]);
        pdf.current?.loadingTask.destroy();
        pdf.current = null;
        if (!file) return;
        const first = floor?.code ?? '';
        try {
            if (file.type !== 'application/pdf') {
                const bitmap = await createImageBitmap(file);
                setPages([
                    {
                        number: 1,
                        label: file.name,
                        preview: URL.createObjectURL(file),
                        width: bitmap.width,
                        height: bitmap.height,
                        floor: first,
                        source: file,
                    },
                ]);
                bitmap.close();
                return;
            }
            setReading('Lettura del PDF…');
            const opened = await openPdf(file);
            pdf.current = opened;
            const read: Page[] = [];
            for (let number = 1; number <= opened.numPages; number++) {
                setReading(`Pagina ${number} di ${opened.numPages}…`);
                const thumbnail = await renderPdfPage(opened, number, 320);
                read.push({
                    number,
                    label: `Pagina ${number}`,
                    preview: URL.createObjectURL(thumbnail.blob),
                    width: thumbnail.width,
                    height: thumbnail.height,
                    floor: number === 1 ? first : '',
                    source: null,
                });
            }
            setPages(read);
        } catch (error) {
            setErrors({
                file:
                    error instanceof Error && error.name === 'PasswordException'
                        ? 'Il PDF è protetto da password.'
                        : 'Impossibile leggere il file. Scegli un PNG, un JPG o un PDF valido.',
            });
        } finally {
            setReading('');
        }
    }
    function assign(number: number, code: string) {
        setPages((current) =>
            current.map((page) =>
                page.number === number
                    ? { ...page, floor: code.trim().toUpperCase() }
                    : page,
            ),
        );
    }
    const assigned = pages.filter((page) => page.floor);
    const repeated = new Set(
        assigned
            .map((page) => page.floor)
            .filter((code, index, all) => all.indexOf(code) !== index),
    );
    // Existing positions are fractions of the old image: they shift if the proportions change.
    const reshaped = assigned.filter((page) => {
        const target = byCode.get(page.floor);
        return (
            target?.image &&
            target.items_count > 0 &&
            Math.abs(
                page.width /
                    page.height /
                    (target.image.width / target.image.height) -
                    1,
            ) > 0.01
        );
    });
    function rowError(page: Page) {
        if (repeated.has(page.floor))
            return 'Questo piano è già scelto per un’altra pagina.';
        const index = submitted.indexOf(page.number);
        return index < 0
            ? undefined
            : (errors[`pages.${index}.floor`] ??
                  errors[`pages.${index}.image`]);
    }
    async function submit(event: FormEvent) {
        event.preventDefault();
        if (processing || repeated.size) return;
        if (!assigned.length) {
            setErrors({ pages: 'Indica il piano di almeno una pagina.' });
            return;
        }
        setProcessing(true);
        setErrors({});
        try {
            const upload = [];
            for (const page of assigned) {
                if (page.source) {
                    upload.push({ floor: page.floor, image: page.source });
                    continue;
                }
                setReading(`Preparazione della pagina ${page.number}…`);
                const rendered = await renderPdfPage(
                    pdf.current!,
                    page.number,
                    4096,
                );
                upload.push({
                    floor: page.floor,
                    image: new File(
                        [rendered.blob],
                        `pagina-${page.number}.${rendered.blob.type === 'image/png' ? 'png' : 'jpg'}`,
                        { type: rendered.blob.type },
                    ),
                });
            }
            setReading('');
            setSubmitted(assigned.map((page) => page.number));
            router.post(
                plans.url({ company, site }),
                { pages: upload, positions: positions || null },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onError: setErrors,
                    onSuccess: () => onOpenChange(false),
                    onFinish: () => setProcessing(false),
                },
            );
        } catch {
            setReading('');
            setProcessing(false);
            setErrors({ pages: 'Impossibile preparare le pagine del PDF.' });
        }
    }
    const generalError = [errors.file, errors.pages, errors.positions]
        .filter(Boolean)
        .join(' ');
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!processing) onOpenChange(next);
            }}
        >
            <DialogContent
                className="sm:max-w-2xl"
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>
                        {floor?.image
                            ? 'Sostituisci planimetria'
                            : 'Carica planimetria'}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={submit}>
                    <FieldGroup>
                        <Field data-invalid={!!errors.file}>
                            <FieldLabel htmlFor={id}>File</FieldLabel>
                            <Input
                                id={id}
                                type="file"
                                accept="image/png,image/jpeg,application/pdf"
                                required
                                autoFocus
                                disabled={processing || !!reading}
                                aria-describedby={`${id}-formats`}
                                onChange={(event) =>
                                    void choose(event.target.files?.[0])
                                }
                            />
                            <p
                                id={`${id}-formats`}
                                className="text-xs text-muted-foreground"
                            >
                                PNG, JPG o PDF · immagini fino a 20 MB e 8192 ×
                                8192 px
                            </p>
                        </Field>
                        {reading && (
                            <p
                                role="status"
                                className="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <Spinner />
                                {reading}
                            </p>
                        )}
                        {pages.length > 0 && (
                            <ul
                                className="flex max-h-[50vh] flex-col gap-2 overflow-y-auto"
                                aria-label="Pagine e piani"
                            >
                                {pages.map((page) => {
                                    const target = byCode.get(page.floor);
                                    const problem = rowError(page);
                                    return (
                                        <li
                                            key={page.number}
                                            className={cn(
                                                'flex items-center gap-3 rounded-lg border p-2 transition-colors',
                                                page.floor
                                                    ? 'bg-background'
                                                    : 'bg-muted/30',
                                            )}
                                        >
                                            <img
                                                src={page.preview}
                                                alt=""
                                                className={cn(
                                                    'h-16 w-24 shrink-0 rounded border bg-white object-contain transition-opacity',
                                                    !page.floor && 'opacity-50',
                                                )}
                                            />
                                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                                <span className="truncate text-sm font-medium">
                                                    {page.label}
                                                </span>
                                                {page.floor &&
                                                    (target ? (
                                                        target.image && (
                                                            <Badge
                                                                variant="outline"
                                                                className="self-start"
                                                            >
                                                                Sostituisce la
                                                                planimetria
                                                            </Badge>
                                                        )
                                                    ) : (
                                                        <Badge
                                                            variant="secondary"
                                                            className="self-start"
                                                        >
                                                            <Plus />
                                                            Nuovo piano
                                                        </Badge>
                                                    ))}
                                                <InputError message={problem} />
                                            </div>
                                            <Input
                                                aria-label={`Piano per ${page.label}`}
                                                list={`${id}-floors`}
                                                value={page.floor}
                                                onChange={(event) =>
                                                    assign(
                                                        page.number,
                                                        event.target.value,
                                                    )
                                                }
                                                maxLength={12}
                                                pattern="PT|P-?[1-9][0-9]*"
                                                placeholder="Piano"
                                                disabled={processing}
                                                aria-invalid={!!problem}
                                                className="w-28 shrink-0"
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <datalist id={`${id}-floors`}>
                            {[
                                ...new Set([
                                    ...floors.map((item) => item.code),
                                    ...FLOOR_SUGGESTIONS,
                                ]),
                            ].map((code) => (
                                <option key={code} value={code} />
                            ))}
                        </datalist>
                        {(reshaped.length > 0 || errors.positions) && (
                            <Field data-invalid={!!errors.positions}>
                                <Alert>
                                    <AlertDescription>
                                        La nuova planimetria di{' '}
                                        {reshaped
                                            .map((page) => page.floor)
                                            .join(', ')}{' '}
                                        ha proporzioni diverse: le posizioni
                                        mantenute risulterebbero spostate.
                                    </AlertDescription>
                                </Alert>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    value={positions}
                                    onValueChange={setPositions}
                                    disabled={processing}
                                    aria-label="Posizioni esistenti"
                                    className="w-full"
                                >
                                    <ToggleGroupItem
                                        value="keep"
                                        className="flex-1"
                                    >
                                        Mantieni posizioni
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="clear"
                                        className="flex-1"
                                    >
                                        Rimuovi posizioni
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </Field>
                        )}
                        <InputError message={generalError} />
                        <Footer
                            processing={processing}
                            disabled={
                                !!reading ||
                                !assigned.length ||
                                repeated.size > 0 ||
                                (reshaped.length > 0 && !positions)
                            }
                            label={
                                assigned.length > 1
                                    ? `Carica ${assigned.length} planimetrie`
                                    : 'Carica'
                            }
                        />
                    </FieldGroup>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function FloorDeleteDialog({
    company,
    site,
    floor,
    open,
    onOpenChange,
}: DialogProps & { floor: Floor }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Elimina piano {floor.code}</DialogTitle>
                </DialogHeader>
                <DeletionConfirmation
                    action={destroy.form({ company, site, floor: floor.id })}
                    onCancel={() => onOpenChange(false)}
                    onSuccess={() => onOpenChange(false)}
                    submitLabel="Elimina piano"
                    fields={() => (
                        <input
                            type="hidden"
                            name="revision"
                            value={floor.revision}
                        />
                    )}
                >
                    {floor.image
                        ? `Verranno eliminati il piano, la planimetria e ${floor.items.length} posizioni.`
                        : 'Verrà eliminato il piano.'}{' '}
                    Dispositivi e rack resteranno documentati.
                </DeletionConfirmation>
            </DialogContent>
        </Dialog>
    );
}
