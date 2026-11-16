import type { ReactNode } from 'react';
import { TriangleAlert } from 'lucide-react';
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
    attention = false,
    interactive = true,
    children,
    onClick,
    className,
    ...props
}: React.ComponentProps<typeof Button> & {
    label: string;
    href?: string;
    warning?: boolean;
    // A source that needs a look (stale data, failed sync) carries a small triangle on its corner.
    attention?: boolean;
    // Without an action the avatar only explains itself in its tooltip.
    interactive?: boolean;
    children: ReactNode;
}) {
    const content = (
        <>
            <AvatarFallback className="*:size-5! *:object-contain">
                {children}
            </AvatarFallback>
            {attention && (
                <span
                    aria-hidden
                    className="absolute -top-1 -right-1 z-10 flex size-4 items-center justify-center rounded-full bg-background"
                >
                    <TriangleAlert className="size-3 fill-amber-300 text-amber-700 dark:fill-amber-500/40 dark:text-amber-300" />
                </span>
            )}
        </>
    );
    if (!interactive)
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Avatar
                        tabIndex={0}
                        role="img"
                        aria-label={label}
                        className={cn(
                            'relative size-8 overflow-visible rounded-full focus-visible:outline-2 focus-visible:outline-ring',
                            className,
                        )}
                    >
                        {content}
                    </Avatar>
                </TooltipTrigger>
                <TooltipContent>{label}</TooltipContent>
            </Tooltip>
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
                            'relative size-8 rounded-full p-0 hover:brightness-110',
                            warning && 'outline-2 outline-destructive',
                            className,
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
