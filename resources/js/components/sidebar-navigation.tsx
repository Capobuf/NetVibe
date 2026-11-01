import { usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { useSidebar } from '@/components/ui/sidebar';

export function SidebarNavigation({ children }: { children: ReactNode }) {
    const { url } = usePage();
    const { state, isMobile } = useSidebar();
    const container = useRef<HTMLElement>(null);
    const indicator = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const nav = container.current;
        const pill = indicator.current;
        if (!nav || !pill) return;

        const update = (animate = true) => {
            const active = nav.querySelector<HTMLElement>(
                '[aria-current="page"]',
            );
            const visible =
                active &&
                active.getClientRects().length > 0 &&
                !active.closest(
                    '[data-slot="collapsible-content"][data-state="closed"]',
                );
            if (!visible) {
                pill.style.opacity = '0';
                return;
            }

            const bounds = nav.getBoundingClientRect();
            const target = (
                active.closest('.sidebar-nav-row') ?? active
            ).getBoundingClientRect();
            const transform = `translate(${target.left - bounds.left}px, ${target.top - bounds.top}px)`;
            const width = `${target.width}px`;
            const height = `${target.height}px`;
            if (
                pill.style.opacity === '1' &&
                pill.style.transform === transform &&
                pill.style.width === width &&
                pill.style.height === height
            )
                return;

            // Follow resizing directly; slide only between selections, never from a stale footer position.
            pill.style.transitionProperty =
                animate && pill.style.opacity === '1'
                    ? 'transform, width, height, opacity'
                    : 'opacity';
            pill.style.transform = transform;
            pill.style.width = width;
            pill.style.height = height;
            pill.style.opacity = '1';
        };

        update();
        const resize = new ResizeObserver(() => update(false));
        resize.observe(nav);
        const mutations = new MutationObserver(() => update());
        mutations.observe(nav, {
            subtree: true,
            attributes: true,
            attributeFilter: ['data-state', 'aria-current'],
            childList: true,
        });
        return () => {
            resize.disconnect();
            mutations.disconnect();
        };
    }, [url, state, isMobile]);

    return (
        <nav
            ref={container}
            aria-label="Navigazione principale"
            className="sidebar-navigation relative isolate flex flex-col gap-5"
        >
            <div
                ref={indicator}
                aria-hidden="true"
                className="sidebar-active-indicator pointer-events-none absolute top-0 left-0 rounded-md bg-sidebar-accent opacity-0"
            />
            {children}
        </nav>
    );
}
