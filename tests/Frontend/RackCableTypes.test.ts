import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test } from 'node:test';
import {
    blankDocument,
    makePort,
} from '../../resources/js/lib/hardware-editor/document.ts';
import type { Cable } from '../../resources/js/lib/rack-cabling.ts';
import type { RackDevice } from '../../resources/js/types/rack.ts';

// Resolve the application's Vite alias for this Node test.
registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            specifier.startsWith('@/')
                ? new URL(
                      `../../resources/js/${specifier.slice(2)}.ts`,
                      import.meta.url,
                  ).href
                : specifier,
            context,
        );
    },
});
const { compatibleCableTypes, normalizeCableType, cableError } =
    await import('../../resources/js/lib/rack-cabling.ts');

test('endpoint changes infer unambiguous cable types and preserve compatible manual choices', () => {
    const panel = blankDocument();
    panel.faces.front.ports = ['rj45', 'sfp', 'qsfp', 'usbA', 'usbC', 'ac'].map(
        (type) => ({
            ...makePort(type as Parameters<typeof makePort>[0], { x: 0, y: 0 }),
            id: type,
        }),
    );
    const devices = [
        { id: 1, name: 'Device', model: { panel } },
    ] as RackDevice[];
    const cable = (
        a: string,
        b: string,
        type: Cable['type'] = 'copper',
    ): Cable => ({
        id: -1,
        a: { device_id: 1, connector_id: a },
        b: { device_id: 1, connector_id: b },
        type,
        color: '#2563eb',
        purpose: null,
        name: null,
    });
    assert.deepEqual(compatibleCableTypes(cable('sfp', 'qsfp'), devices), [
        'copper',
        'fiber',
    ]);
    assert.equal(
        normalizeCableType(cable('sfp', 'qsfp', 'fiber'), devices).type,
        'fiber',
    );
    assert.deepEqual(compatibleCableTypes(cable('rj45', 'sfp'), devices), [
        'copper',
    ]);
    assert.equal(
        normalizeCableType(cable('rj45', 'sfp', 'fiber'), devices).type,
        'copper',
    );
    assert.equal(
        normalizeCableType(cable('usbA', 'usbC'), devices).type,
        'usb',
    );
    assert.equal(normalizeCableType(cable('ac', ''), devices).type, 'power');
    assert.deepEqual(compatibleCableTypes(cable('', ''), devices), []);
    const incompatible = normalizeCableType(cable('rj45', 'ac'), devices);
    assert.deepEqual(compatibleCableTypes(incompatible, devices), []);
    assert.ok(cableError(incompatible, [], devices));
});
