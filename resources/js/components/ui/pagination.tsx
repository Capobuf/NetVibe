import type { ComponentProps } from 'react';
import { ChevronLeft, ChevronRight, MoreHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

function Pagination({ className, ...props }: ComponentProps<'nav'>) {
    return (
        <nav
            aria-label="Paginazione"
            data-slot="pagination"
            className={cn('mx-auto flex w-full justify-center', className)}
            {...props}
        />
    );
}

function PaginationContent({ className, ...props }: ComponentProps<'ul'>) {
    return (
        <ul
            data-slot="pagination-content"
            className={cn('flex flex-wrap items-center justify-center gap-1', className)}
            {...props}
        />
    );
}

function PaginationItem(props: ComponentProps<'li'>) {
    return <li data-slot="pagination-item" {...props} />;
}

function PaginationLink({
    isActive,
    size = 'icon',
    ...props
}: ComponentProps<typeof Button> & { isActive?: boolean }) {
    return (
        <Button
            type="button"
            aria-current={isActive ? 'page' : undefined}
            data-slot="pagination-link"
            data-active={isActive}
            variant={isActive ? 'outline' : 'ghost'}
            size={size}
            {...props}
        />
    );
}

function PaginationPrevious(props: ComponentProps<typeof PaginationLink>) {
    return (
        <PaginationLink aria-label="Pagina precedente" size="default" {...props}>
            <ChevronLeft data-icon="inline-start" />
            <span className="hidden sm:block">Precedente</span>
        </PaginationLink>
    );
}

function PaginationNext(props: ComponentProps<typeof PaginationLink>) {
    return (
        <PaginationLink aria-label="Pagina successiva" size="default" {...props}>
            <span className="hidden sm:block">Successiva</span>
            <ChevronRight data-icon="inline-end" />
        </PaginationLink>
    );
}

function PaginationEllipsis({ className, ...props }: ComponentProps<'span'>) {
    return (
        <span
            aria-hidden="true"
            data-slot="pagination-ellipsis"
            className={cn('flex size-10 items-center justify-center', className)}
            {...props}
        >
            <MoreHorizontal className="size-4" />
        </span>
    );
}

export {
    Pagination,
    PaginationContent,
    PaginationItem,
    PaginationLink,
    PaginationPrevious,
    PaginationNext,
    PaginationEllipsis,
};
