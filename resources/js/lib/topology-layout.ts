import type { TopologyEdge, TopologyNode } from '@/types/topology';

export type Position = { x: number; y: number };
export const NODE_WIDTH = 224;
export const NODE_HEIGHT = 140;
export type TopologyOrientation = 'horizontal' | 'vertical';

// A spanning tree orders the drawing; extra links remain visible and do not imply routing roles.
export function topologyLayout(
    nodes: TopologyNode[],
    edges: TopologyEdge[],
    orientation: TopologyOrientation = 'vertical',
): Record<string, Position> {
    const neighbors = new Map(
        nodes.map((node) => [node.id, new Set<string>()]),
    );
    for (const edge of edges) {
        if (edge.source === edge.target) continue;
        neighbors.get(edge.source)?.add(edge.target);
        neighbors.get(edge.target)?.add(edge.source);
    }
    const ordered = nodes.toSorted(
        (a, b) =>
            (neighbors.get(b.id)?.size ?? 0) - (neighbors.get(a.id)?.size ?? 0),
    );
    const remaining = new Set(nodes.map((node) => node.id));
    const positions: Record<string, Position> = {};
    const across =
        orientation === 'vertical' ? NODE_WIDTH + 40 : NODE_HEIGHT + 56;
    const down =
        orientation === 'vertical' ? NODE_HEIGHT + 100 : NODE_WIDTH + 100;
    let top = 40;
    const isolated: string[] = [];
    for (const root of ordered) {
        if (!remaining.delete(root.id)) continue;
        if (!neighbors.get(root.id)?.size) {
            isolated.push(root.id);
            continue;
        }
        const queue = [{ id: root.id, depth: 0, left: 0 }];
        const children = new Map<string, typeof queue>();
        for (let index = 0; index < queue.length; index++) {
            const parent = queue[index];
            const branch: typeof queue = [];
            for (const id of neighbors.get(parent.id) ?? []) {
                if (remaining.delete(id))
                    branch.push({ id, depth: parent.depth + 1, left: 0 });
            }
            children.set(parent.id, branch);
            queue.push(...branch);
        }
        const widths = new Map<string, number>();
        for (const node of queue.toReversed())
            widths.set(
                node.id,
                Math.max(
                    1,
                    children
                        .get(node.id)!
                        .reduce((sum, child) => sum + widths.get(child.id)!, 0),
                ),
            );
        for (const node of queue) {
            const cross =
                40 + (node.left + (widths.get(node.id)! - 1) / 2) * across;
            const depth = top + node.depth * down;
            positions[node.id] =
                orientation === 'vertical'
                    ? { x: cross, y: depth }
                    : { x: depth, y: cross };
            let left = node.left;
            for (const child of children.get(node.id)!) {
                child.left = left;
                left += widths.get(child.id)!;
            }
        }
        top += (Math.max(...queue.map((node) => node.depth)) + 1) * down + 60;
    }
    for (const [index, id] of isolated.entries()) {
        const cross = 40 + (index % 5) * across;
        const depth = top + Math.floor(index / 5) * down;
        positions[id] =
            orientation === 'vertical'
                ? { x: cross, y: depth }
                : { x: depth, y: cross };
    }
    return positions;
}
