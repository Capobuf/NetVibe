import { Link, router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import {
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            {/* The user card itself opens the personal settings. */}
            <DropdownMenuItem asChild>
                <Link
                    className="flex w-full cursor-pointer items-center gap-2 px-1 py-1.5 text-left text-sm"
                    href={edit()}
                    onClick={cleanup}
                    aria-label={`Impostazioni di ${user.name}`}
                >
                    <UserInfo user={user} showEmail={true} />
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Esci
                </Link>
            </DropdownMenuItem>
        </>
    );
}
