import assert from 'node:assert/strict';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import {
    NODE_WIDTH,
    NODE_HEIGHT,
    type Position,
} from '../../resources/js/lib/topology-layout.ts';
import type { TopologyEdge } from '../../resources/js/types/topology.ts';

registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            specifier === './topology-layout'
                ? './topology-layout.ts'
                : specifier,
            context,
        );
    },
});
const { topologyRoutes } =
    await import('../../resources/js/lib/topology-routing.ts');

const edges = [
    { id: 'first', source: 'a', target: 'b' },
    { id: 'parallel', source: 'a', target: 'b' },
] as TopologyEdge[];

test('links avoid devices and labels in both orientations, also after a device moves into their path', () => {
    const positions: Record<string, Position> = {
        a: { x: 0, y: 240 },
        b: { x: 800, y: 240 },
        middle: { x: 400, y: 240 },
    };
    for (const orientation of ['vertical', 'horizontal'] as const) {
        for (const middle of [
            { x: 400, y: 240 },
            { x: 300, y: 100 },
        ]) {
            positions.middle = middle;
            const paths = topologyRoutes(positions, edges, orientation);
            assert.equal(Object.keys(paths).length, 2);
            assert.notDeepEqual(paths.first, paths.parallel);
            for (const path of Object.values(paths)) {
                for (let i = 1; i < path.length; i++) {
                    const a = path[i - 1],
                        b = path[i];
                    assert.ok(a.x === b.x || a.y === b.y);
                    for (const box of Object.values(positions)) {
                        const crosses =
                            a.x === b.x
                                ? a.x > box.x &&
                                  a.x < box.x + NODE_WIDTH &&
                                  Math.max(a.y, b.y) > box.y &&
                                  Math.min(a.y, b.y) < box.y + NODE_HEIGHT
                                : a.y > box.y &&
                                  a.y < box.y + NODE_HEIGHT &&
                                  Math.max(a.x, b.x) > box.x &&
                                  Math.min(a.x, b.x) < box.x + NODE_WIDTH;
                        assert.equal(crosses, false);
                    }
                }
            }
        }
    }
    assert.deepEqual(topologyRoutes({}, edges, 'vertical'), {});
});
