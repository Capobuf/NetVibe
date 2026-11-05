import { router } from '@inertiajs/react';
import { Boxes, Link2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { edit, records } from '@/routes/companies/settings/integrations/snipe-it';

/** Second-level selector of the company Snipe-IT integration. */
export function SnipeItTabs({
    company,
    value,
    pending,
}: {
    company: number;
    value: 'assets' | 'settings';
    pending?: number;
}) {
    return (
        <Tabs
            value={value}
            activationMode="manual"
            onValueChange={(next) =>
                router.visit(
                    next === 'assets' ? records(company) : edit(company),
                )
            }
        >
            <TabsList aria-label="Snipe-IT">
                <TabsTrigger value="assets">
                    <Boxes aria-hidden="true" />
                    Asset
                    {pending ? (
                        <Badge className="tabular-nums">{pending}</Badge>
                    ) : null}
                </TabsTrigger>
                <TabsTrigger value="settings">
                    <Link2 aria-hidden="true" />
                    Configurazione
                </TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
