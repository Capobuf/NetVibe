import { useForm } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/companies/settings/integrations/retention';

/**
 * Company retention of automatically documented IPs backed only by temporary evidence (ARP, dynamic
 * leases, neighbors, UniFi clients), counted from their last presence.
 */
export function ObservationRetention({
    company,
    days,
}: {
    company: number;
    days: number;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ observation_retention_days: String(days) });

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.clearErrors();
                    form.setData('observation_retention_days', String(days));
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <History data-icon="inline-start" />
                    IP rilevati: liberati dopo {days}{' '}
                    {days === 1 ? 'giorno' : 'giorni'}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
                <PopoverHeader>
                    <PopoverTitle>IP rilevati automaticamente</PopoverTitle>
                </PopoverHeader>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(update(company), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <Field data-invalid={Boolean(form.errors.observation_retention_days)}>
                        <FieldLabel htmlFor="observation-retention-days">
                            Libera gli IP non più rilevati dopo
                        </FieldLabel>
                        <InputGroup>
                            <InputGroupInput
                                id="observation-retention-days"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={365}
                                required
                                value={form.data.observation_retention_days}
                                aria-invalid={Boolean(
                                    form.errors.observation_retention_days,
                                )}
                                onChange={(event) =>
                                    form.setData(
                                        'observation_retention_days',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputGroupAddon align="inline-end">
                                <InputGroupText>giorni</InputGroupText>
                            </InputGroupAddon>
                        </InputGroup>
                        <FieldError>
                            {form.errors.observation_retention_days}
                        </FieldError>
                    </Field>
                    <Button
                        type="submit"
                        size="sm"
                        className="self-end"
                        disabled={form.processing}
                    >
                        {form.processing ? <Spinner /> : null}
                        Salva
                    </Button>
                </form>
            </PopoverContent>
        </Popover>
    );
}
