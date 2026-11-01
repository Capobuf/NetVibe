import { useId } from 'react';
import { Label } from '@/components/ui/label';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export function TablePagination({
    summary,
    page,
    pageCount,
    pageSize,
    onPageChange,
    onPageSizeChange,
}: {
    summary: string;
    page: number;
    pageCount: number;
    pageSize: number;
    onPageChange: (page: number) => void;
    onPageSizeChange: (size: number) => void;
}) {
    const id = useId();
    const pages = [...new Set([1, page - 1, page, page + 1, pageCount])]
        .filter((value) => value >= 1 && value <= pageCount)
        .sort((a, b) => a - b);

    return (
        <footer className="grid grid-cols-2 items-center gap-4 border-t px-4 py-3 lg:grid-cols-[1fr_auto_1fr]">
            <p
                role="status"
                className="text-sm whitespace-nowrap text-muted-foreground tabular-nums"
            >
                {summary}
            </p>
            {pageCount > 1 ? (
                <Pagination className="col-span-2 row-start-2 lg:col-span-1 lg:col-start-2 lg:row-start-1">
                    <PaginationContent>
                        <PaginationItem>
                            <PaginationPrevious
                                disabled={page === 1}
                                onClick={() => onPageChange(page - 1)}
                            />
                        </PaginationItem>
                        {pages.map((value, position) => (
                            <PaginationItem
                                key={value}
                                className="flex items-center gap-1"
                            >
                                {position > 0 &&
                                value - pages[position - 1] > 1 ? (
                                    <PaginationEllipsis />
                                ) : null}
                                <PaginationLink
                                    isActive={value === page}
                                    aria-label={`Pagina ${value}`}
                                    onClick={() => onPageChange(value)}
                                >
                                    {value}
                                </PaginationLink>
                            </PaginationItem>
                        ))}
                        <PaginationItem>
                            <PaginationNext
                                disabled={page === pageCount}
                                onClick={() => onPageChange(page + 1)}
                            />
                        </PaginationItem>
                    </PaginationContent>
                </Pagination>
            ) : null}
            <div className="col-start-2 row-start-1 flex items-center justify-end gap-2 lg:col-start-3">
                <Label htmlFor={id} className="text-xs text-muted-foreground">
                    Righe
                </Label>
                <Select
                    value={String(pageSize)}
                    onValueChange={(value) => onPageSizeChange(Number(value))}
                >
                    <SelectTrigger
                        id={id}
                        className="w-20"
                        aria-label="Righe per pagina"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {[10, 25, 50, 100].map((size) => (
                                <SelectItem key={size} value={String(size)}>
                                    {size}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>
        </footer>
    );
}
