import { usePage, WhenVisible } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

export function MikrotikSourcesLoader() {
    const { component, props } = usePage();
    const [attempt, setAttempt] = useState(0);

    if (component !== 'devices/show' || props.mikrotikSources !== undefined)
        return null;

    return (
        <WhenVisible
            key={attempt}
            data="mikrotikSources"
            fallback={
                <div
                    role="status"
                    className="flex items-center gap-2 p-4 text-sm"
                >
                    <Spinner />
                    Caricamento dati MikroTik…
                </div>
            }
        >
            <Alert variant="destructive">
                <AlertDescription>
                    Impossibile caricare i dati MikroTik.
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setAttempt(attempt + 1)}
                    >
                        Riprova
                    </Button>
                </AlertDescription>
            </Alert>
        </WhenVisible>
    );
}
