import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function AppLogoIcon({
    className,
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            {...props}
            src="/branding/logo.svg"
            width={1254}
            height={1254}
            alt=""
            aria-hidden="true"
            className={cn('shrink-0 object-contain dark:invert', className)}
        />
    );
}
