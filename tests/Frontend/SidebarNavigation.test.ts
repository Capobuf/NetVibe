import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { createElement } from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import ts from 'typescript';

// Render the real React navigation in Node, without a browser or new test dependencies.
registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier.startsWith('@/')) {
            const base = new URL(
                `../../resources/js/${specifier.slice(2)}`,
                import.meta.url,
            );
            specifier =
                ['.ts', '.tsx']
                    .map((ext) => `${base.href}${ext}`)
                    .find((url) => existsSync(fileURLToPath(url))) ?? specifier;
        }
        return nextResolve(specifier, context);
    },
    load(url, context, nextLoad) {
        if (url.startsWith('file:') && url.endsWith('.tsx')) {
            return {
                format: 'module',
                source: ts.transpileModule(readFileSync(new URL(url), 'utf8'), {
                    compilerOptions: {
                        jsx: ts.JsxEmit.ReactJSX,
                        module: ts.ModuleKind.ESNext,
                    },
                }).outputText,
                shortCircuit: true,
            };
        }
        return nextLoad(url, context);
    },
});

const { NavMain } = await import('../../resources/js/components/nav-main.tsx');
const { TooltipProvider } =
    await import('../../resources/js/components/ui/tooltip.tsx');
const { SidebarProvider } =
    await import('../../resources/js/components/ui/sidebar.tsx');

async function navigation(url: string, expanded = true) {
    const page = {
        component: 'navigation-test',
        props: {},
        url,
        version: null,
        clearHistory: false,
        encryptHistory: false,
    };
    const { body } = await createInertiaApp({
        page,
        resolve: () => () =>
            createElement(
                SidebarProvider,
                { defaultOpen: expanded },
                createElement(NavMain, {
                    items: [
                        {
                            title: 'Dispositivi',
                            href: '/devices',
                            items: [
                                { title: 'Catalogo', href: '/devices/models' },
                            ],
                        },
                    ],
                }),
            ),
        render: renderToString,
        setup: ({ App, props }) =>
            createElement(TooltipProvider, null, createElement(App, props)),
    });
    return body;
}

test('models select only the child and open its branch, including icon-only navigation', async () => {
    const models = await navigation('/devices/models');
    assert.equal((models.match(/aria-current="page"/g) ?? []).length, 1);
    assert.match(
        models.match(/<a\b[^>]*aria-current="page"[^>]*>/)?.[0] ?? '',
        /href="\/devices\/models"/,
    );
    assert.match(models, /aria-expanded="true"/);
    assert.match(models, /data-child-active="true"/);

    const devices = await navigation('/devices');
    assert.match(devices, /aria-expanded="true"/);
    assert.equal((devices.match(/aria-current="page"/g) ?? []).length, 1);

    const elsewhere = await navigation('/subnets');
    assert.match(elsewhere, /aria-expanded="false"/);
    assert.doesNotMatch(elsewhere, /aria-current="page"/);

    const collapsed = await navigation('/devices/models', false);
    assert.match(collapsed, /href="\/devices\/models"/);
    assert.doesNotMatch(collapsed, /aria-label="Chiudi il sottomenu/);
});
