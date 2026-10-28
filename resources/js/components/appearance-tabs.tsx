import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

export default function AppearanceToggleTab({
    className = '',
}: {
    className?: string;
}) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: 'Chiaro' },
        { value: 'dark', icon: Moon, label: 'Scuro' },
        { value: 'system', icon: Monitor, label: 'Sistema' },
    ];

    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="lg"
            value={appearance}
            onValueChange={(value) => {
                if (value === 'light' || value === 'dark' || value === 'system')
                    updateAppearance(value);
            }}
            aria-label="Tema dell’interfaccia"
            className={cn('w-full sm:w-fit', className)}
        >
            {tabs.map(({ value, icon: Icon, label }) => (
                <ToggleGroupItem
                    key={value}
                    value={value}
                    className="flex-1 px-3 sm:flex-none"
                >
                    <Icon aria-hidden="true" />
                    {label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
