import { Head, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Switch } from '@/components/ui/switch';
import { edit, preferences } from '@/routes/notifications';

const sources = {
    notify_snipe_it: 'Snipe-IT',
    notify_mikrotik: 'MikroTik',
    notify_unifi: 'UniFi',
} as const;

type Source = keyof typeof sources;

export default function Notifications() {
    const { user } = usePage().props.auth;

    function toggle(source: Source, enabled: boolean) {
        router.patch(
            preferences(),
            {
                notify_snipe_it: user.notify_snipe_it,
                notify_mikrotik: user.notify_mikrotik,
                notify_unifi: user.notify_unifi,
                [source]: enabled,
            },
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Notifiche" />

            <h1 className="sr-only">Notifiche</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifiche"
                    description="Scegli quando ricevere una notifica"
                />
                <div className="overflow-hidden rounded-xl border">
                    <div className="border-b bg-muted/40 px-4 py-2 text-sm text-muted-foreground">
                        Sincronizzazioni
                    </div>
                    <ul className="divide-y">
                        {(Object.keys(sources) as Source[]).map((source) => (
                            <li
                                key={source}
                                className="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <label
                                    htmlFor={source}
                                    className="flex min-w-0 flex-col gap-0.5"
                                >
                                    <span className="text-sm font-medium">
                                        {sources[source]}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        Sincronizzazione non riuscita o
                                        ripristinata
                                    </span>
                                </label>
                                <Switch
                                    id={source}
                                    checked={user[source]}
                                    onCheckedChange={(checked) =>
                                        toggle(source, checked)
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notifiche', href: edit() }],
};
