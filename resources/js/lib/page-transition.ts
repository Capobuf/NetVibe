import type { VisitOptions } from '@inertiajs/core';

const prefersReducedMotion = (): boolean =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Default visit options that animate real page changes with the View
 * Transitions API. Same-path visits (search, sorting, pagination, filters),
 * partial reloads and form submissions update in place without a transition.
 */
export function pageTransitionOptions(
    href: string,
    options: VisitOptions,
): VisitOptions {
    const method = (options.method ?? 'get').toLowerCase();

    if (
        method !== 'get' ||
        options.preserveState ||
        options.only?.length ||
        prefersReducedMotion() ||
        new URL(href, window.location.href).pathname ===
            window.location.pathname
    ) {
        return {};
    }

    return {
        viewTransition: (transition) => {
            // Scopes the CSS animation to the page region (see app.css), so the
            // shell stays still and theme cross-fades keep their own styling.
            const root = document.documentElement;
            const cleanup = () => delete root.dataset.pageTransition;

            root.dataset.pageTransition = '';
            transition.finished.then(cleanup, cleanup);
        },
    };
}
