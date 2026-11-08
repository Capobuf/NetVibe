import { Maximize, Minus, Plus } from 'lucide-react';
import { IconButton } from '@/components/icon-button';

export function ZoomControls({
    zoom,
    onZoom,
    onFit,
    fitLabel,
    step = 1.25,
}: {
    zoom: number;
    onZoom: (factor: number) => void;
    onFit: () => void;
    fitLabel: string;
    step?: number;
}) {
    return (
        <>
            <IconButton
                label="Riduci zoom"
                variant="ghost"
                onClick={() => onZoom(1 / step)}
            >
                <Minus />
            </IconButton>
            <span className="hidden w-12 text-center text-xs text-muted-foreground tabular-nums sm:block">
                {Math.round(zoom * 100)}%
            </span>
            <IconButton
                label="Aumenta zoom"
                variant="ghost"
                onClick={() => onZoom(step)}
            >
                <Plus />
            </IconButton>
            <IconButton label={fitLabel} variant="ghost" onClick={onFit}>
                <Maximize />
            </IconButton>
        </>
    );
}
