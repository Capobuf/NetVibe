import { useState } from 'react';

export function useTablePagination<Row>(rows: Row[], resetKey: string) {
    const [pageSize, setSize] = useState(25);
    const [position, setPosition] = useState({ key: resetKey, page: 1 });
    if (position.key !== resetKey) {
        setPosition({ key: resetKey, page: 1 });
    }
    const needsPagination = rows.length > 25;
    const pageCount = needsPagination ? Math.ceil(rows.length / pageSize) : 1;
    const page =
        position.key === resetKey ? Math.min(position.page, pageCount) : 1;
    const pageRows = needsPagination
        ? rows.slice((page - 1) * pageSize, page * pageSize)
        : rows;

    function setPage(page: number) {
        setPosition({ key: resetKey, page });
    }

    function setPageSize(size: number) {
        setSize(size);
        setPage(1);
    }

    return {
        pageRows,
        page,
        pageCount,
        pageSize,
        needsPagination,
        setPage,
        setPageSize,
    };
}
