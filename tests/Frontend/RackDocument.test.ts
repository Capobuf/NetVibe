import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test } from 'node:test';
import type {
    DocumentCable,
    DocumentRack,
} from '../../resources/js/types/rack-document.ts';

registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            specifier === './rack-layout' ? './rack-layout.ts' : specifier,
            context,
        );
    },
});
const { documentLinks, rackOccupancy, rackSections } =
    await import('../../resources/js/lib/rack-document-data.ts');

test('rack document keeps supported and multi-U elements together with correct top-down labels and free ranges', () => {
    const rack: DocumentRack = {
        id: 1,
        name: 'Rack',
        height_u: 50,
        numbering: 'top-down',
        items: [
            {
                key: 'shelf',
                kind: 'shelf',
                device_id: null,
                shelf_key: null,
                position: 7,
                height: 1,
                width: 100,
                x: 0,
            },
            {
                key: 'nas',
                kind: 'device',
                device_id: 1,
                shelf_key: 'shelf',
                position: 7,
                height: 2,
                width: 40,
                x: 0,
            },
            {
                key: 'drawer',
                kind: 'drawer',
                device_id: null,
                shelf_key: null,
                position: 0,
                height: 2,
                width: 100,
                x: 0,
            },
        ],
    };
    assert.deepEqual(rackSections(rack), [
        { bottom: 7, top: 50 },
        { bottom: 0, top: 7 },
    ]);
    assert.deepEqual(rackOccupancy(rack, []), [
        ['1–41', 'Spazio libero', '—'],
        ['42–43', '—', 'Apparato · appoggiato su Ripiano'],
        ['43', 'Ripiano', 'Ripiano · staffe 1U'],
        ['44–48', 'Spazio libero', '—'],
        ['49–50', 'Cassetto', 'Cassetto · 2U'],
    ]);
});

test('document topology aggregates only equal device pairs and cable types, including reversed ends and power', () => {
    const cables = [
        [1, 2, 'copper'],
        [2, 1, 'copper'],
        [1, 2, 'fiber'],
        [2, 3, 'power'],
    ].map(([a, b, type], index) => ({
        id: index + 1,
        a: { device_id: a },
        b: { device_id: b },
        type,
    })) as DocumentCable[];
    assert.deepEqual(
        documentLinks(cables).map((link) => [
            link.reference,
            link.a,
            link.b,
            link.type,
            link.cables.map((cable) => cable.id),
        ]),
        [
            ['T1', 1, 2, 'copper', [1, 2]],
            ['T2', 1, 2, 'fiber', [3]],
            ['T3', 2, 3, 'power', [4]],
        ],
    );
});
