import { Palette } from 'lucide-react';
import type { ComponentProps } from 'react';
import { IconButton } from '@/components/icon-button';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

const colors: Record<string, string> = {
    gray: 'Grigio',
    blue: 'Blu',
    green: 'Verde',
    amber: 'Giallo',
    orange: 'Arancione',
    red: 'Rosso',
    purple: 'Viola',
};

export function TagBadge({
    color,
    className,
    ...props
}: ComponentProps<typeof Badge> & { color: string }) {
    return (
        <Badge
            variant="secondary"
            data-color={color}
            className={cn('tag-badge', className)}
            {...props}
        />
    );
}

export function TagColorPicker({
    name,
    color,
    onChange,
    disabled,
}: {
    name: string;
    color: string;
    onChange: (color: string) => void;
    disabled: boolean;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <IconButton
                    variant="ghost"
                    className="size-6"
                    label={`Colore del tag aziendale ${name}: ${colors[color]}`}
                    disabled={disabled}
                >
                    <Palette />
                </IconButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuGroup>
                    <DropdownMenuLabel>
                        Colore del tag aziendale
                    </DropdownMenuLabel>
                </DropdownMenuGroup>
                <DropdownMenuRadioGroup value={color} onValueChange={onChange}>
                    {Object.entries(colors).map(([value, label]) => (
                        <DropdownMenuRadioItem key={value} value={value}>
                            <TagBadge color={value}>{label}</TagBadge>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
