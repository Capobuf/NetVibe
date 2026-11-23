import { cn } from '@/lib/utils';

// Simplified Google Drive triangle, monochrome like the other integration marks.
export function GoogleDriveMark({ className }: { className?: string }) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            width={28}
            height={28}
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            className={cn('size-7 shrink-0', className)}
        >
            <path d="M8.5 3h7l6.5 11.25-3.5 6.06H5.5L2 14.25Z" />
            <path d="M8.5 3 12 9.06M15.5 3 9 14.25h13M5.5 20.31 9 14.25" />
        </svg>
    );
}
