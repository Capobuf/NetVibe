import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';

// Window listeners run in registration order, so Inertia's popstate handler would run before a
// page's guard. app.tsx imports this module before Inertia starts, putting this listener first.
let guardHistory: ((event: PopStateEvent) => void) | null = null;
if (typeof window !== 'undefined')
    window.addEventListener('popstate', (event) => guardHistory?.(event));

// Holds Inertia visits, history navigation and page unloads while there are unsaved changes.
export function useLeaveGuard(
    blocked: boolean,
    {
        ignore,
        onBlocked,
    }: {
        ignore?: (visit: PendingVisit) => boolean;
        onBlocked?: () => void;
    } = {},
) {
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const allowLeave = useRef(false);
    const ignored = useEffectEvent(
        (visit: PendingVisit) => ignore?.(visit) ?? false,
    );
    const block = useEffectEvent((proceed: () => void) => {
        onBlocked?.();
        setLeave(() => () => {
            allowLeave.current = true;
            setLeave(null);
            proceed();
        });
    });

    useEffect(() => {
        if (!blocked) return;
        const stop = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (allowLeave.current || ignored(visit)) return;
            event.preventDefault();
            block(() =>
                router.visit(visit.url, {
                    ...visit,
                    // A confirmed discard must remount the page even on an Inertia reload.
                    preserveState: false,
                    onFinish: () => {
                        allowLeave.current = false;
                    },
                }),
            );
        });
        const unload = (event: BeforeUnloadEvent) => {
            if (allowLeave.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        // Inertia's cancellable before event does not cover browser history traversal.
        const currentUrl = window.location.href;
        const currentState = window.history.state;
        const pop = (event: PopStateEvent) => {
            if (allowLeave.current) return;
            event.stopImmediatePropagation();
            window.history.pushState(currentState, '', currentUrl);
            block(() => window.history.back());
        };
        window.addEventListener('beforeunload', unload);
        guardHistory = pop;
        return () => {
            stop();
            window.removeEventListener('beforeunload', unload);
            if (guardHistory === pop) guardHistory = null;
        };
    }, [blocked]);

    return { leave, setLeave, allowLeave };
}
