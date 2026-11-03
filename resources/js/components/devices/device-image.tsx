import { ImageIcon } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

export function DeviceImage({
    src,
    name,
    className,
}: {
    src?: string | null;
    name: string;
    className?: string;
}) {
    return (
        <Avatar className={cn('h-14 w-24 rounded-md', className)}>
            <AvatarImage
                src={src ?? undefined}
                alt={`Immagine di ${name}`}
                className="aspect-auto object-contain"
                referrerPolicy="no-referrer"
            />
            <AvatarFallback
                className="rounded-md bg-muted/30 text-muted-foreground"
                aria-label="Immagine non disponibile"
            >
                <ImageIcon className="size-5" aria-hidden="true" />
            </AvatarFallback>
        </Avatar>
    );
}
