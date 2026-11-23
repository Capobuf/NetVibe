import { useForm } from '@inertiajs/react';
import { FolderOpen, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { IconButton } from '@/components/icon-button';
import { intervalLabel } from '@/components/integration-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemGroup,
} from '@/components/ui/item';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { pickFolder, type DriveFolder } from '@/lib/google-picker';
import {
    connect,
    store,
    token,
    update,
} from '@/routes/administration/integrations/google-drive';
import { capitalize, type EditorData, type Editing } from './types';

/** Account, frequency and folder of one sync, or of several new ones (one folder per company). */
export function SyncEditor({
    editing,
    props,
    onClose,
}: {
    editing: Editing;
    props: EditorData;
    onClose: () => void;
}) {
    const { accounts, intervals, picker } = props;
    const existing = 'sync' in editing ? editing.sync : null;
    const options = 'companies' in editing ? editing.companies : [];
    const single = existing
        ? [existing.company]
        : options.length === 1
          ? options
          : null;
    const form = useForm({
        account_id: String(existing?.account?.id ?? accounts[0]?.id ?? ''),
        interval: String(existing?.interval ?? 1440),
    });
    // Selected companies with the folder chosen for each; a key present means selected.
    const [folders, setFolders] = useState<Record<number, DriveFolder | null>>(
        () =>
            existing
                ? {
                      [existing.company.id]: {
                          id: existing.folder_id,
                          name: existing.folder_name,
                      },
                  }
                : single
                  ? { [single[0].id]: null }
                  : {},
    );
    const [picking, setPicking] = useState(false);
    const selected = Object.entries(folders);
    const complete =
        form.data.account_id !== '' &&
        selected.length > 0 &&
        selected.every(([, folder]) => folder !== null);

    async function choose(companyId: number) {
        if (!picker || !form.data.account_id) return;
        // The Picker lives outside the dialog, which would otherwise block its clicks.
        setPicking(true);
        try {
            const folder = await pickFolder(
                token(Number(form.data.account_id)).url,
                picker,
            );
            if (folder) {
                // Selected companies still without a folder get the same one; each stays editable.
                setFolders((current) =>
                    Object.fromEntries(
                        Object.entries(current).map(([id, value]) => [
                            id,
                            Number(id) === companyId || value === null
                                ? folder
                                : value,
                        ]),
                    ),
                );
            }
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setPicking(false);
        }
    }

    function changeAccount(value: string) {
        form.setData('account_id', value);
        // Folders picked with another account may be out of reach for this one.
        setFolders((current) =>
            Object.fromEntries(Object.keys(current).map((id) => [id, null])),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const payload = {
            account_id: Number(form.data.account_id),
            interval: Number(form.data.interval),
        };
        const options = { preserveScroll: true, onSuccess: onClose };
        if (existing) {
            const folder = folders[existing.company.id];
            form.transform(() => ({
                ...payload,
                folder_id: folder?.id ?? '',
                folder_name: folder?.name ?? '',
            }));
            form.put(update(existing.id).url, options);
        } else {
            form.transform(() => ({
                ...payload,
                targets: selected.map(([id, folder]) => ({
                    company_id: Number(id),
                    folder_id: folder?.id ?? '',
                    folder_name: folder?.name ?? '',
                })),
            }));
            form.post(store().url, options);
        }
    }

    const errors = Object.entries(form.errors)
        .filter(([key]) => key !== 'account_id' && key !== 'interval')
        .map(([, message]) => message);

    return (
        <Dialog
            open={!picking}
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <DialogHeader>
                        <DialogTitle>
                            {existing
                                ? `Sincronizzazione di ${existing.company.name}`
                                : single
                                  ? `Sincronizza ${single[0].name} su Drive`
                                  : 'Sincronizza aziende su Drive'}
                        </DialogTitle>
                        {existing ? (
                            <DialogDescription>
                                Con un altro account o un’altra cartella il
                                foglio viene scritto nella nuova destinazione;
                                quello attuale resta su Drive.
                            </DialogDescription>
                        ) : null}
                    </DialogHeader>
                    <FieldGroup>
                        <Field data-invalid={!!form.errors.account_id}>
                            <FieldLabel htmlFor="drive-account">
                                Account Google
                            </FieldLabel>
                            <div className="flex gap-2">
                                <Select
                                    value={form.data.account_id}
                                    onValueChange={changeAccount}
                                >
                                    <SelectTrigger
                                        id="drive-account"
                                        className="min-w-0 flex-1"
                                    >
                                        <SelectValue placeholder="Scegli un account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.email}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <IconButton
                                    label="Collega un altro account"
                                    asChild
                                >
                                    <a href={connect.url()}>
                                        <Plus />
                                    </a>
                                </IconButton>
                            </div>
                            <FieldError>{form.errors.account_id}</FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.interval}>
                            <FieldLabel htmlFor="drive-interval">
                                Frequenza
                            </FieldLabel>
                            <Select
                                value={form.data.interval}
                                onValueChange={(value) =>
                                    form.setData('interval', value)
                                }
                            >
                                <SelectTrigger id="drive-interval">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {intervals.map((minutes) => (
                                        <SelectItem
                                            key={minutes}
                                            value={String(minutes)}
                                        >
                                            {capitalize(intervalLabel(minutes))}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <FieldError>{form.errors.interval}</FieldError>
                        </Field>
                        {single ? (
                            <Field>
                                <FieldLabel>Cartella</FieldLabel>
                                <FolderButton
                                    folder={folders[single[0].id] ?? null}
                                    disabled={!form.data.account_id}
                                    onClick={() => choose(single[0].id)}
                                />
                            </Field>
                        ) : (
                            <FieldSet>
                                <FieldLegend variant="label">
                                    Aziende e cartelle
                                </FieldLegend>
                                <ItemGroup className="max-h-72 gap-2 overflow-y-auto">
                                    {options.map((option) => {
                                        const checked = option.id in folders;
                                        return (
                                            <Item
                                                key={option.id}
                                                variant="outline"
                                                size="sm"
                                                role="listitem"
                                            >
                                                <Checkbox
                                                    id={`drive-company-${option.id}`}
                                                    checked={checked}
                                                    onCheckedChange={(value) =>
                                                        setFolders(
                                                            (current) => {
                                                                const next = {
                                                                    ...current,
                                                                };
                                                                if (value)
                                                                    next[
                                                                        option.id
                                                                    ] = null;
                                                                else
                                                                    delete next[
                                                                        option
                                                                            .id
                                                                    ];
                                                                return next;
                                                            },
                                                        )
                                                    }
                                                />
                                                <ItemContent className="min-w-0">
                                                    <label
                                                        htmlFor={`drive-company-${option.id}`}
                                                        className="truncate text-sm font-medium"
                                                    >
                                                        {option.name}
                                                    </label>
                                                </ItemContent>
                                                {checked ? (
                                                    <ItemActions>
                                                        <FolderButton
                                                            folder={
                                                                folders[
                                                                    option.id
                                                                ]
                                                            }
                                                            disabled={
                                                                !form.data
                                                                    .account_id
                                                            }
                                                            onClick={() =>
                                                                choose(
                                                                    option.id,
                                                                )
                                                            }
                                                        />
                                                    </ItemActions>
                                                ) : null}
                                            </Item>
                                        );
                                    })}
                                </ItemGroup>
                            </FieldSet>
                        )}
                    </FieldGroup>
                    {errors.length ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {[...new Set(errors)].join(' ')}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            Annulla
                        </Button>
                        <Button
                            type="submit"
                            disabled={!complete || form.processing}
                        >
                            {form.processing ? <Spinner /> : null}
                            {existing ? 'Salva' : 'Conferma'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FolderButton({
    folder,
    disabled,
    onClick,
}: {
    folder: DriveFolder | null;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={disabled}
            onClick={onClick}
            className="max-w-64 justify-start"
        >
            <FolderOpen data-icon="inline-start" />
            <span className="truncate">
                {folder ? folder.name : 'Scegli cartella'}
            </span>
        </Button>
    );
}

