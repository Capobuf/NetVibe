import { ChevronDown, Printer, type LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type PrintDocument = {
    label: string;
    icon: LucideIcon;
    onSelect: () => void;
};

/** Entry point for the printable documents available in the current context. */
export function PrintMenu({
    documents,
    disabled,
}: {
    documents: PrintDocument[];
    disabled?: boolean;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" disabled={disabled}>
                    <Printer data-icon="inline-start" />
                    Stampa
                    <ChevronDown data-icon="inline-end" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {documents.map(({ label, icon: Icon, onSelect }) => (
                    <DropdownMenuItem key={label} onSelect={onSelect}>
                        <Icon />
                        {label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
