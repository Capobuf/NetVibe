import { useState } from 'react';
import { ChevronDown, Copy, Terminal } from 'lucide-react';
import { toast } from 'sonner';
import { postJson } from '@/lib/http';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { setupCommand } from '@/routes/companies/sites/devices/mikrotik';

type Setup = {
    username: string;
    password: string;
    port: number;
    public_ip: string;
    command: string;
};

export function MikrotikSetup({
    route,
    port,
    username,
    password,
    disabled,
    preparing,
    onPreparingChange,
    onApply,
}: {
    route: { company: number; site: number; device: number };
    port: string;
    username: string;
    password: string;
    disabled: boolean;
    preparing: boolean;
    onPreparingChange: (value: boolean) => void;
    onApply: (username: string, password: string) => void;
}) {
    const [generated, setGenerated] = useState<Setup | null>(null);
    const [, copy] = useClipboard();
    const current =
        generated?.port === Number(port) &&
        generated?.username === username &&
        generated?.password === password
            ? generated
            : null;

    async function prepare() {
        onPreparingChange(true);
        try {
            let setup = current;
            if (!setup) {
                const response = await postJson<Setup>(
                    setupCommand.url(route),
                    { port },
                );
                if (!response.ok || !response.data) {
                    const errors = response.ok
                        ? undefined
                        : response.data?.errors;
                    throw new Error(
                        errors?.port?.[0] ??
                            errors?.setup?.[0] ??
                            'Impossibile preparare il comando. Riprova.',
                    );
                }
                setup = response.data;
                setGenerated(setup);
                onApply(setup.username, setup.password);
            }
            if (await copy(setup.command)) {
                toast.success(
                    'Comando copiato. Incollalo nel terminale RouterOS, poi salva le credenziali.',
                );
            } else {
                toast.error(
                    'Copia non riuscita. Apri il comando e copialo manualmente.',
                );
            }
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Impossibile preparare il comando.',
            );
        } finally {
            onPreparingChange(false);
        }
    }

    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="flex items-center gap-2">
                    <Terminal className="size-4" />
                    Abilita REST API
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4">
                <p className="text-sm text-muted-foreground">
                    RouterOS richiede il servizio HTTPS (www-ssl). Il comando lo
                    abilita sulla porta indicata, crea netvibe-rest-api in sola
                    lettura e aggiunge in cima al firewall l’accesso dall’IP
                    pubblico di NetVibe.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    onClick={() => void prepare()}
                >
                    {preparing ? (
                        <Spinner />
                    ) : (
                        <Copy data-icon="inline-start" />
                    )}
                    {preparing
                        ? 'Preparazione…'
                        : current
                          ? 'Copia comando'
                          : 'Genera e copia comando'}
                </Button>
                <p className="text-xs text-muted-foreground">
                    Username e password vengono compilati automaticamente.
                    Incolla il comando nel terminale RouterOS prima di salvare.
                    Utenti e gruppi omonimi non vengono sovrascritti.
                </p>
                {current ? (
                    <Collapsible>
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                className="group w-full justify-between"
                            >
                                Mostra comando · {current.public_ip}
                                <ChevronDown className="transition-transform group-data-[state=open]:rotate-180" />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <pre
                                tabIndex={0}
                                aria-label="Comando di configurazione RouterOS"
                                className="mt-2 max-h-56 overflow-auto rounded-md border bg-muted/50 p-3 font-mono text-xs break-all whitespace-pre-wrap select-text focus-visible:outline-2 focus-visible:outline-ring"
                            >
                                {current.command}
                            </pre>
                        </CollapsibleContent>
                    </Collapsible>
                ) : null}
            </CardContent>
        </Card>
    );
}
