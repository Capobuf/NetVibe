import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ExternalLink, Unlink } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { detach } from '@/routes/companies/settings/integrations/snipe-it';

export type SnipeDevice = {
    id: number;
    url: string;
    active: boolean;
    present: boolean;
    synced_at: string | null;
    error: string | null;
    problem: string | null;
    model_problem: string | null;
    snapshot: {
        name: string | null;
        asset_tag: string | null;
        serial: string | null;
    };
    model: {
        name: string | null;
        manufacturer: string | null;
        category: string | null;
    } | null;
};

export function SnipeItDevice({
    company,
    source,
}: {
    company: number;
    source: SnipeDevice;
}) {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);
    const form = useForm({
        asset_id: source.id,
        confirmed: true,
        exclude: true,
    });
    const problem = !source.active
        ? 'Collegamento sospeso: riconferma l’azienda Snipe-IT.'
        : !source.present
          ? 'Asset non più presente nell’azienda Snipe-IT.'
          : source.error || source.problem || source.model_problem;
    return (
        <section
            aria-label="Anagrafica Snipe-IT"
            className="flex flex-col gap-3"
        >
            <div className="flex flex-wrap items-center gap-3">
                <Badge variant="secondary">Snipe-IT</Badge>
                <Button asChild variant="outline" size="sm">
                    <a
                        href={source.url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink data-icon="inline-start" />
                        Apri in Snipe-IT
                    </a>
                </Button>
                {source.synced_at ? (
                    <span className="text-xs text-muted-foreground">
                        Ultimo aggiornamento:{' '}
                        {new Date(source.synced_at).toLocaleString('it-IT')}
                    </span>
                ) : null}
                {auth.user.is_superadmin ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setOpen(true)}
                    >
                        <Unlink data-icon="inline-start" />
                        Scollega da Snipe-IT
                    </Button>
                ) : null}
            </div>
            {problem ? (
                <Alert>
                    <AlertDescription className="flex flex-col gap-2">
                        <span>{problem}</span>
                        {source.problem || source.model_problem ? (
                            <span>
                                Snipe-IT:{' '}
                                {[
                                    source.snapshot.name ||
                                        source.snapshot.asset_tag,
                                    source.snapshot.serial,
                                    source.model?.manufacturer,
                                    source.model?.name,
                                    source.model?.category,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        ) : null}
                    </AlertDescription>
                </Alert>
            ) : null}
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!form.processing) setOpen(value);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Scollega da Snipe-IT</DialogTitle>
                        <DialogDescription>
                            Il dispositivo diventa manuale. IP, interfacce,
                            rack, cablaggi e note restano conservati.
                        </DialogDescription>
                    </DialogHeader>
                    <Field orientation="horizontal">
                        <Checkbox
                            id="exclude-detached"
                            checked={form.data.exclude}
                            disabled={form.processing}
                            onCheckedChange={(value) =>
                                form.setData('exclude', value === true)
                            }
                        />
                        <FieldLabel htmlFor="exclude-detached">
                            Escludi dalle prossime importazioni
                        </FieldLabel>
                    </Field>
                    {Object.values(form.errors)[0] ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {Object.values(form.errors)[0]}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => setOpen(false)}
                        >
                            Annulla
                        </Button>
                        <Button
                            disabled={form.processing}
                            onClick={() =>
                                form.submit(detach(company), {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                })
                            }
                        >
                            {form.processing ? <Spinner /> : null}Scollega
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
