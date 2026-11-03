import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

export function CopyValue({
    value,
    className,
}: {
    value: string;
    className?: string;
}) {
    const [, copy] = useClipboard();

    async function copyValue() {
        if (await copy(value)) {
            toast.success('Valore copiato.');
        } else {
            toast.error(
                'Copia non riuscita. Seleziona il valore e copialo manualmente.',
            );
        }
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className={cn(
                        'h-auto max-w-full min-w-0 justify-start px-2 py-1.5 text-left font-normal break-words whitespace-pre-wrap select-text',
                        className,
                    )}
                    aria-label={`Copia ${value}`}
                    onClick={(event) => {
                        event.stopPropagation();
                        void copyValue();
                    }}
                >
                    <span className="min-w-0 break-words">{value}</span>
                </Button>
            </TooltipTrigger>
            <TooltipContent>Copia valore</TooltipContent>
        </Tooltip>
    );
}
