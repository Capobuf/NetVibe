import { useForm } from "@inertiajs/react";
import {
    Cloud,
    MoreHorizontal,
    Pause,
    Pencil,
    Play,
    RefreshCw,
    Search,
    Server,
    ShieldCheck,
    Trash2,
} from "lucide-react";
import { useState } from "react";
import {
    destroy,
    discover,
    update,
    verify,
} from "@/actions/App/Http/Controllers/UnifiController";
import {
    IntegrationStatus,
    type IntegrationState,
} from "@/components/integration-header";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from "@/components/ui/input-group";
import { Spinner } from "@/components/ui/spinner";
import { formatDateTime } from "@/lib/utils";
import { UnifiSiteList } from "./site-list";
import { UnifiInventoryPreview } from "./inventory-preview";
import { unifiAccess, type UnifiCompany, type UnifiConnection } from "./types";

export function connectionState(connection: UnifiConnection): IntegrationState {
    return !connection.enabled
        ? "suspended"
        : connection.check_status === "ok"
          ? "active"
          : connection.checked_at
            ? "error"
            : "pending";
}

/** Content of the access dialog: connection status and actions, then UniFi sites mapped to NetVibe sites. */
export function UnifiConnectionPanel({
    connection,
    companies,
    readonly,
    onEdit,
}: {
    connection: UnifiConnection;
    companies: UnifiCompany[];
    readonly: boolean;
    onEdit: () => void;
}) {
    const [search, setSearch] = useState("");
    const form = useForm({
        revision: connection.revision ?? "",
        confirmed: true,
    });
    const [pending, setPending] = useState<
        "verify" | "discover" | "toggle" | "destroy" | null
    >(null);
    const [confirm, setConfirm] = useState<"suspend" | "destroy" | null>(null);
    const savedConsoles = connection.selected_console_ids ?? [];
    const state = connectionState(connection);
    const linked = connection.sites.filter((source) => source.site !== null);

    function run(action: "verify" | "discover" | "toggle" | "destroy") {
        setPending(action);
        form.transform(() =>
            action === "toggle"
                ? {
                      name: connection.name,
                      mode: connection.mode,
                      company_id: connection.company_id,
                      server_url: connection.server_url ?? "",
                      api_key: "",
                      allow_unverified_certificate:
                          connection.allow_unverified_certificate ?? false,
                      enabled: !connection.enabled,
                      revision: connection.revision,
                  }
                : { revision: connection.revision, confirmed: true },
        );
        const route =
            action === "verify"
                ? verify(connection.id)
                : action === "discover"
                  ? discover(connection.id)
                  : action === "toggle"
                    ? update(connection.id)
                    : destroy(connection.id);
        form.submit(route, {
            preserveScroll: true,
            onSuccess: () => setConfirm(null),
            onFinish: () => setPending(null),
        });
    }

    return (
        <section
            className="flex min-w-0 flex-col gap-4"
            aria-labelledby={`unifi-connection-${connection.id}`}
        >
            <div className="flex flex-col gap-4">
                <DialogHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted">
                            {connection.mode === "cloud" ? (
                                <Cloud />
                            ) : (
                                <Server />
                            )}
                        </span>
                        <div className="flex min-w-0 flex-col gap-1">
                            <DialogTitle className="flex flex-wrap items-center gap-2 break-words">
                                {connection.name}
                                <IntegrationStatus state={state} />
                            </DialogTitle>
                            <DialogDescription className="break-all">
                                {[
                                    connection.mode === "cloud"
                                        ? `${unifiAccess.cloud} · tutte le console dell’account UniFi`
                                        : `${unifiAccess.direct} · accesso diretto alla console`,
                                    !readonly && connection.mode === "direct"
                                        ? connection.server_url
                                        : null,
                                    connection.checked_at
                                        ? `verificato il ${formatDateTime(connection.checked_at)}`
                                        : "mai verificato",
                                ]
                                    .filter(Boolean)
                                    .join(" · ")}
                            </DialogDescription>
                        </div>
                    </div>
                    {!readonly ? (
                        <div className="flex shrink-0 flex-wrap items-center gap-2 sm:pr-8">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    form.processing || !connection.enabled
                                }
                                onClick={() => run("verify")}
                            >
                                {pending === "verify" ? (
                                    <Spinner />
                                ) : (
                                    <ShieldCheck data-icon="inline-start" />
                                )}
                                Verifica
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    form.processing ||
                                    !connection.enabled ||
                                    (connection.mode === "cloud" &&
                                        !savedConsoles.length)
                                }
                                onClick={() => run("discover")}
                            >
                                {pending === "discover" ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw data-icon="inline-start" />
                                )}
                                Aggiorna siti
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Altre azioni per ${connection.name}`}
                                        disabled={form.processing}
                                    >
                                        {pending === "toggle" ? (
                                            <Spinner />
                                        ) : (
                                            <MoreHorizontal />
                                        )}
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuGroup>
                                        <DropdownMenuItem onSelect={onEdit}>
                                            <Pencil />
                                            Modifica
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                connection.enabled
                                                    ? setConfirm("suspend")
                                                    : run("toggle")
                                            }
                                        >
                                            {connection.enabled ? (
                                                <Pause />
                                            ) : (
                                                <Play />
                                            )}
                                            {connection.enabled
                                                ? "Sospendi"
                                                : "Riattiva"}
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => setConfirm("destroy")}
                                    >
                                        <Trash2 />
                                        Rimuovi
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    ) : null}
                </DialogHeader>
                {connection.check_message &&
                connection.check_status !== "ok" ? (
                    <Alert variant="destructive" aria-live="polite">
                        <AlertTitle>Verifica non riuscita</AlertTitle>
                        <AlertDescription>
                            {connection.check_message}
                        </AlertDescription>
                    </Alert>
                ) : null}
                {form.hasErrors && !confirm ? (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {Object.values(form.errors).join(" ")}
                        </AlertDescription>
                    </Alert>
                ) : null}
            </div>
            {!readonly &&
            connection.mode === "cloud" &&
            connection.check_status === "ok" &&
            !connection.hosts?.length ? (
                <Alert>
                    <AlertDescription>
                        Nessuna console accessibile con questa API key.
                    </AlertDescription>
                </Alert>
            ) : null}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <UnifiInventoryPreview
                    connection={connection}
                    readonly={readonly}
                />
                {connection.sites.length > 5 ||
                (connection.hosts?.length ?? 0) > 5 ? (
                    <InputGroup className="max-w-xs">
                        <InputGroupAddon>
                            <Search />
                        </InputGroupAddon>
                        <InputGroupInput
                            type="search"
                            aria-label="Cerca console, siti o sedi"
                            placeholder="Cerca console, siti o sedi…"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </InputGroup>
                ) : null}
            </div>
            <UnifiSiteList
                key={`${connection.id}:${savedConsoles.join(",")}`}
                connection={connection}
                companies={companies}
                search={search}
                readonly={readonly}
                disabled={!connection.enabled || form.processing}
            />
            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => {
                    if (!open && !form.processing) setConfirm(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirm === "destroy"
                                ? "Rimuovere la connessione?"
                                : "Sospendere la connessione?"}
                        </DialogTitle>
                        <DialogDescription>
                            {confirm === "destroy"
                                ? "I siti raggiungibili anche da un altro accesso UniFi passeranno a quell’accesso con i dati acquisiti. Per gli altri, collegamenti e anteprime saranno rimossi; i dati documentati saranno conservati."
                                : "Le nuove acquisizioni saranno sospese per tutti i siti collegati. I dati già acquisiti resteranno consultabili."}
                        </DialogDescription>
                    </DialogHeader>
                    <p className="font-medium">{connection.name}</p>
                    {linked.length ? (
                        <ul className="flex max-h-48 flex-col gap-2 overflow-y-auto text-sm">
                            {linked.map((source) => (
                                <li key={source.id}>
                                    {source.name} ·{" "}
                                    {[
                                        source.site?.company.name,
                                        source.site?.name,
                                    ]
                                        .filter(Boolean)
                                        .join(" · ")}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {form.hasErrors ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {Object.values(form.errors).join(" ")}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => setConfirm(null)}
                        >
                            Annulla
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={form.processing}
                            onClick={() =>
                                run(
                                    confirm === "destroy"
                                        ? "destroy"
                                        : "toggle",
                                )
                            }
                        >
                            {form.processing ? <Spinner /> : null}
                            {confirm === "destroy" ? "Rimuovi" : "Sospendi"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
