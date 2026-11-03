import { Link, router, useForm } from '@inertiajs/react';
import { Eye, EyeOff, MoreHorizontal } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    preview as subnetPreview,
    update as subnetUpdate,
} from '@/routes/companies/sites/subnets/exclusion';
import {
    preview as vlanPreview,
    update as vlanUpdate,
} from '@/routes/companies/sites/vlans/exclusion';
import { show as vlanShow } from '@/routes/companies/sites/vlans';
import {
    preview as sourcePreview,
    update as sourceUpdate,
} from '@/routes/companies/sites/devices/mikrotik/sources/exclusion';

export function NetworkVisibilityFilter({
    value,
    onChange,
    count,
}: {
    value: string;
    onChange: (value: string) => void;
    count: number;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            value={value}
            onValueChange={(next) => {
                if (next) onChange(next);
            }}
            aria-label="Visibilità delle reti"
        >
            <ToggleGroupItem value="active">Non escluse</ToggleGroupItem>
            <ToggleGroupItem value="excluded">
                Escluse <Badge variant="secondary">{count}</Badge>
            </ToggleGroupItem>
        </ToggleGroup>
    );
}

export function NetworkExclusionAction({
    company,
    site,
    kind,
    id,
    label,
    device,
    excluded = false,
    compact = false,
    open: controlledOpen,
    onOpenChange,
    onRestoreFocus,
}: {
    company: number;
    site: number;
    kind: 'subnet' | 'vlan' | 'source';
    id: number;
    label: string;
    device?: number;
    excluded?: boolean;
    compact?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onRestoreFocus?: () => void;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const setOpen = onOpenChange ?? setInternalOpen;
    const trigger = useRef<HTMLButtonElement>(null);
    const route = { company, site };
    const preview =
        kind === 'subnet'
            ? subnetPreview({ ...route, subnet: id })
            : kind === 'vlan'
              ? vlanPreview({ ...route, vlan: id })
              : sourcePreview({ ...route, device: device!, source: id });
    const action =
        kind === 'subnet'
            ? subnetUpdate({ ...route, subnet: id })
            : kind === 'vlan'
              ? vlanUpdate({ ...route, vlan: id })
              : sourceUpdate({ ...route, device: device!, source: id });
    const actionLabel =
        kind === 'source'
            ? excluded
                ? 'Riprendi acquisizione'
                : 'Ignora questa rete…'
            : excluded
              ? 'Reincludi'
              : 'Escludi da NetVibe…';

    return (
        <>
            {controlledOpen !== undefined ? null : compact && !excluded ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            ref={trigger}
                            variant="ghost"
                            size="icon"
                            aria-label={`Azioni per ${label}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        onCloseAutoFocus={(event) => {
                            if (open) event.preventDefault();
                        }}
                    >
                        <DropdownMenuGroup>
                            <DropdownMenuItem onSelect={() => setOpen(true)}>
                                <EyeOff />
                                {actionLabel}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : (
                <Button
                    ref={trigger}
                    variant="outline"
                    size={compact ? 'sm' : 'default'}
                    onClick={() => setOpen(true)}
                >
                    {excluded ? (
                        <Eye data-icon="inline-start" />
                    ) : (
                        <EyeOff data-icon="inline-start" />
                    )}
                    {actionLabel}
                </Button>
            )}
            <Dialog open={open} onOpenChange={setOpen}>
                {open ? (
                    <ExclusionDialog
                        company={company}
                        site={site}
                        label={label}
                        previewUrl={preview.url}
                        action={action}
                        onClose={() => setOpen(false)}
                        onRestoreFocus={
                            onRestoreFocus ?? (() => trigger.current?.focus())
                        }
                    />
                ) : null}
            </Dialog>
        </>
    );
}

type Impact = {
    kind?: 'subnet' | 'vlan';
    source?: string;
    label: string;
    excluded_at: string | null;
    inherited_vlan: { id: number; tag: number } | null;
    subnets: {
        id: number;
        cidr: string;
        name: string | null;
        excluded_at: string | null;
        ip_addresses_count: number;
    }[];
};

function ExclusionDialog({
    company,
    site,
    label,
    previewUrl,
    action,
    onClose,
    onRestoreFocus,
}: {
    company: number;
    site: number;
    label: string;
    previewUrl: string;
    action: { url: string; method: 'put' };
    onClose: () => void;
    onRestoreFocus: () => void;
}) {
    const [impact, setImpact] = useState<Impact | null>(null);
    const [error, setError] = useState('');
    const form = useForm({ excluded: true });
    useEffect(() => {
        const controller = new AbortController();
        fetch(previewUrl, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Impossibile caricare l’anteprima. Chiudi e riprova.',
                    );
                const data: Impact = await response.json();
                if (!controller.signal.aborted) setImpact(data);
            })
            .catch((failure: unknown) => {
                if (!controller.signal.aborted)
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'Anteprima non disponibile.',
                    );
            });
        return () => controller.abort();
    }, [previewUrl]);
    const restore = Boolean(impact?.excluded_at);

    function submit() {
        form.transform(() => ({ excluded: !restore }));
        form.submit(action, {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
                toast.success(
                    impact?.source
                        ? restore
                            ? 'Acquisizione della rete riattivata.'
                            : 'Rete ignorata nelle sincronizzazioni.'
                        : restore
                          ? 'Rete reinclusa.'
                          : 'Rete esclusa da NetVibe.',
                    {
                        action: restore
                            ? undefined
                            : {
                                  label: 'Annulla',
                                  onClick: () =>
                                      router.put(
                                          action.url,
                                          { excluded: false },
                                          {
                                              preserveScroll: true,
                                              onSuccess: () =>
                                                  toast.success(
                                                      'Esclusione annullata.',
                                                  ),
                                              onError: (errors) =>
                                                  toast.error(
                                                      Object.values(
                                                          errors,
                                                      )[0] ??
                                                          'Impossibile annullare l’esclusione.',
                                                  ),
                                          },
                                      ),
                              },
                    },
                );
            },
        });
    }

    return (
        <DialogContent
            onCloseAutoFocus={(event) => {
                event.preventDefault();
                onRestoreFocus();
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {impact?.source
                        ? restore
                            ? 'Riprendere l’acquisizione di'
                            : 'Ignorare'
                        : impact
                          ? restore
                              ? 'Reincludere'
                              : 'Escludere'
                          : 'Gestisci'}{' '}
                    {impact?.label ?? label}?
                </DialogTitle>
                <DialogDescription>
                    {impact?.source
                        ? restore
                            ? `Le prossime sincronizzazioni da ${impact.source} potranno nuovamente importare questa rete e i suoi indirizzi, se i dati lo consentono.`
                            : `NetVibe ignorerà questa rete e i suoi indirizzi nelle prossime sincronizzazioni da ${impact.source}. I dati già acquisiti resteranno consultabili in «Escluse», dove potrai riprendere l’acquisizione.`
                        : impact?.inherited_vlan
                          ? `Esclusa tramite VLAN ${impact.inherited_vlan.tag}.`
                          : restore
                            ? 'La rete tornerà visibile e riprenderà gli aggiornamenti da MikroTik.'
                            : 'La rete e i suoi indirizzi saranno nascosti dalle viste ordinarie. I dati saranno conservati e potrai reincluderli.'}
                </DialogDescription>
            </DialogHeader>
            {!impact && !error ? (
                <p role="status" className="flex items-center gap-2 text-sm">
                    <Spinner />
                    Caricamento…
                </p>
            ) : null}
            {impact && !impact.source ? (
                <div className="flex flex-col gap-3 text-sm">
                    <p>
                        {impact.subnets.length} subnet ·{' '}
                        {impact.subnets.reduce(
                            (total, subnet) =>
                                total + subnet.ip_addresses_count,
                            0,
                        )}{' '}
                        IP documentati conservati
                    </p>
                    <ul className="flex max-h-56 flex-col gap-2 overflow-y-auto">
                        {impact.subnets.map((subnet) => (
                            <li
                                key={subnet.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
                            >
                                <span>
                                    {subnet.cidr}
                                    {subnet.name ? ` · ${subnet.name}` : ''}
                                </span>
                                <span className="text-muted-foreground">
                                    {subnet.ip_addresses_count} IP
                                </span>
                                {restore &&
                                subnet.excluded_at &&
                                impact.kind === 'vlan' ? (
                                    <Badge variant="outline">
                                        Esclusa singolarmente
                                    </Badge>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
            <InputError message={error || Object.values(form.errors)[0]} />
            <DialogFooter>
                <Button
                    variant="outline"
                    onClick={onClose}
                    disabled={form.processing}
                >
                    Annulla
                </Button>
                {impact?.inherited_vlan ? (
                    <Button asChild>
                        <Link
                            href={vlanShow({
                                company,
                                site,
                                vlan: impact.inherited_vlan.id,
                            })}
                        >
                            Apri VLAN {impact.inherited_vlan.tag}
                        </Link>
                    </Button>
                ) : (
                    <Button
                        onClick={submit}
                        disabled={!impact || form.processing}
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : restore ? (
                            <Eye />
                        ) : (
                            <EyeOff />
                        )}
                        {impact?.source
                            ? restore
                                ? 'Riprendi acquisizione'
                                : 'Ignora rete'
                            : restore
                              ? 'Reincludi'
                              : 'Escludi'}
                    </Button>
                )}
            </DialogFooter>
        </DialogContent>
    );
}
