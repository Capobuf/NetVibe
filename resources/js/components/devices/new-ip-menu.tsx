import { useRef } from 'react';
import { Network } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export function NewIpMenu<
    T extends { id: number; cidr: string; name: string | null },
>({
    subnets,
    onSelect,
}: {
    subnets: T[];
    onSelect: (subnet: T, trigger: HTMLButtonElement | null) => void;
}) {
    const trigger = useRef<HTMLButtonElement>(null);
    const opening = useRef(false);
    return (
        <DropdownMenu
            onOpenChange={(open) => {
                if (open) opening.current = false;
            }}
        >
            <DropdownMenuTrigger asChild>
                <Button ref={trigger} variant="outline" size="sm">
                    <Network data-icon="inline-start" />
                    Nuovo IP
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                onCloseAutoFocus={(event) => {
                    if (opening.current) event.preventDefault();
                }}
            >
                <DropdownMenuGroup>
                    {subnets.map((subnet) => (
                        <DropdownMenuItem
                            key={subnet.id}
                            onSelect={() => {
                                opening.current = true;
                                onSelect(subnet, trigger.current);
                            }}
                        >
                            {subnet.cidr}
                            {subnet.name ? ` · ${subnet.name}` : ''}
                        </DropdownMenuItem>
                    ))}
                    {!subnets.length && (
                        <DropdownMenuItem disabled>
                            Nessuna subnet nella sede
                        </DropdownMenuItem>
                    )}
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
