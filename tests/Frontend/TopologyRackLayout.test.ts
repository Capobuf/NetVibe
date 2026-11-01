import assert from 'node:assert/strict';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import type {
    TopologyNode,
    TopologyEdge,
} from '../../resources/js/types/topology.ts';
import type { TopologyRack } from '../../resources/js/lib/topology-rack-layout.ts';

registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            [
                './hardware-editor/document',
                './rack-layout',
                './topology-layout',
            ].includes(specifier)
                ? `${specifier}.ts`
                : specifier,
            context,
        );
    },
});
const { topologyRackLayout, RACK_MARGIN } =
    await import('../../resources/js/lib/topology-rack-layout.ts');
const { RACK_UNIT_HEIGHT: U, RACK_WIDTH } =
    await import('../../resources/js/lib/hardware-editor/document.ts');
const { topologyRoutes } =
    await import('../../resources/js/lib/topology-routing.ts');

test('physical rack view preserves mounting units, shelf stacking and widths, while keeping empty racks and unmounted nodes', () => {
    const nodes = [1, 2, 3, 4].map((id) => ({
        id: `device:${id}`,
        device_id: id,
    })) as TopologyNode[];
    const racks: TopologyRack[] = [
        {
            id: 1,
            name: 'Rack',
            height_u: 12,
            numbering: 'top-down',
            items: [
                {
                    key: 'mounted',
                    kind: 'device',
                    device_id: 1,
                    shelf_key: null,
                    position: 8,
                    height: 1,
                    width: 100,
                    x: 0,
                },
                {
                    key: 'shelf',
                    kind: 'shelf',
                    device_id: null,
                    shelf_key: null,
                    position: 2,
                    height: 1,
                    width: 100,
                    x: 0,
                },
                {
                    key: 'lower',
                    kind: 'device',
                    device_id: 2,
                    shelf_key: 'shelf',
                    position: 2,
                    height: 2,
                    width: 50,
                    x: 25,
                },
                {
                    key: 'upper',
                    kind: 'device',
                    device_id: 3,
                    shelf_key: 'lower',
                    position: 2,
                    height: 1,
                    width: 50,
                    x: 25,
                },
            ],
        },
        {
            id: 2,
            name: 'Empty',
            height_u: 24,
            numbering: 'bottom-up',
            items: [],
        },
    ];
    const layout = topologyRackLayout(nodes, racks);
    assert.equal(layout.frames.length, 2);
    assert.equal(layout.positions['device:1'].y, 80 + 3 * U);
    assert.equal(layout.positions['device:2'].y, 80 + 8 * U);
    assert.equal(layout.positions['device:3'].y, 80 + 7 * U);
    assert.equal(
        layout.positions['device:2'].x,
        60 + RACK_MARGIN + RACK_WIDTH / 4,
    );
    assert.deepEqual(layout.sizes['device:2'], {
        width: RACK_WIDTH / 2,
        height: U * 2,
    });
    assert.ok(
        layout.positions['device:4'].x >
            layout.frames[1].x + layout.frames[1].width,
    );
    assert.equal(layout.sizes['device:4'], undefined);
    const edges = [
        { id: 'stack', source: 'device:2', target: 'device:3' },
        { id: 'uplink', source: 'device:1', target: 'device:4' },
    ] as TopologyEdge[];
    const routes = topologyRoutes(
        layout.positions,
        edges,
        'horizontal',
        layout.sizes,
    );
    assert.equal(Object.keys(routes).length, edges.length);
    assert.deepEqual(topologyRackLayout([], racks).sizes, {});
    assert.equal(topologyRackLayout(nodes, []).hasOutside, true);
});
