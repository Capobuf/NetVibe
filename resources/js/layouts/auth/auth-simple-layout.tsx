import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted/30 p-4 sm:p-8">
            <div className="w-full max-w-md rounded-xl border bg-card p-6 shadow-sm sm:p-8">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex items-center gap-2 rounded-md font-semibold"
                        >
                            <AppLogoIcon className="size-24" />
                            <span className="sr-only">NetVibe</span>
                        </Link>

                        <div className="flex flex-col gap-2 text-center">
                            <h1 className="text-2xl font-medium tracking-tight text-balance">
                                {title}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
