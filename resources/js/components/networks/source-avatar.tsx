import type { ReactNode } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipTrigger,
    TooltipContent,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export function SourceAvatar({
    label,
    href,
    warning = false,
    children,
    onClick,
    ...props
}: React.ComponentProps<typeof Button> & {
    label: string;
    href?: string;
    warning?: boolean;
    children: ReactNode;
}) {
    const content = (
        <AvatarFallback className="*:size-5! *:object-contain">
            {children}
        </AvatarFallback>
    );
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Avatar asChild className="overflow-visible">
                    <Button
                        {...props}
                        type="button"
                        variant="ghost"
                        size="icon"
                        className={cn(
                            'size-8 rounded-full p-0 hover:brightness-110',
                            warning && 'outline-2 outline-destructive',
                        )}
                        aria-label={label}
                        onClick={(event) => {
                            event.stopPropagation();
                            onClick?.(event);
                        }}
                        asChild={Boolean(href)}
                    >
                        {href ? (
                            <a
                                href={href}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {content}
                            </a>
                        ) : (
                            content
                        )}
                    </Button>
                </Avatar>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
