import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    NODE_HEIGHT,
    NODE_WIDTH,
    topologyLayout,
} from '../../resources/js/lib/topology-layout.ts';
import type {
    TopologyEdge,
    TopologyNode,
} from '../../resources/js/types/topology.ts';

test('cycles, parallel links and isolated devices all receive distinct non-overlapping positions', () => {
    const nodes = ['a', 'b', 'c', 'isolated'].map((id) => ({
        id,
    })) as TopologyNode[];
    const edges = [
        ['a', 'b'],
        ['b', 'c'],
        ['c', 'a'],
        ['a', 'b'],
    ].map(([source, target]) => ({ source, target })) as TopologyEdge[];
    for (const orientation of ['horizontal', 'vertical'] as const) {
        const positions = topologyLayout(nodes, edges, orientation);
        assert.deepEqual(
            Object.keys(positions).sort(),
            nodes.map((node) => node.id).sort(),
        );
        for (const [i, a] of Object.values(positions).entries()) {
            for (const b of Object.values(positions).slice(i + 1)) {
                assert.ok(
                    Math.abs(a.x - b.x) >= NODE_WIDTH ||
                        Math.abs(a.y - b.y) >= NODE_HEIGHT,
                );
            }
        }
    }
    assert.deepEqual(topologyLayout([], []), {});
});

test('a wide tree keeps siblings at the same depth and the parent centered above them', () => {
    const children = Array.from({ length: 8 }, (_, index) => `child-${index}`);
    const nodes = ['root', ...children, 'grandchild', 'isolated'].map((id) => ({
        id,
    })) as TopologyNode[];
    const edges = [
        ...children.map((target) => ({ source: 'root', target })),
        { source: children[0], target: 'grandchild' },
    ] as TopologyEdge[];
    const positions = topologyLayout(nodes, edges);
    assert.equal(new Set(children.map((id) => positions[id].y)).size, 1);
    assert.ok(positions.root.y < positions[children[0]].y);
    assert.ok(positions[children[0]].y < positions.grandchild.y);
    assert.equal(
        positions.root.x,
        (positions[children[0]].x + positions[children.at(-1)!].x) / 2,
    );
    assert.ok(positions.isolated.y > positions.grandchild.y);
});
