import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function AppLogoIcon({
    className,
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            {...props}
            src="/branding/logo-black.svg"
            width={2000}
            height={2000}
            alt=""
            aria-hidden="true"
            className={cn(
                'shrink-0 object-contain dark:content-[url(/branding/logo-white.svg)]',
                className,
            )}
        />
    );
}
