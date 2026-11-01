import {
    NODE_HEIGHT,
    NODE_WIDTH,
    type Position,
    type TopologyOrientation,
} from './topology-layout';
import type { NodeSize } from './topology-rack-layout';
import type { TopologyEdge } from '../types/topology';

// Route on the clear corridors around node bounds, including their labels.
export function topologyRoutes(
    positions: Record<string, Position>,
    edges: TopologyEdge[],
    orientation: TopologyOrientation,
    sizes: Record<string, NodeSize> = {},
): Record<string, Position[]> {
    const size = (id: string) =>
        sizes[id] ?? { width: NODE_WIDTH, height: NODE_HEIGHT };
    const routes: Record<string, Position[]> = {};
    const lanes = new Map<string, number>();
    for (const edge of edges) {
        const a = positions[edge.source];
        const b = positions[edge.target];
        if (!a || !b) continue;
        const pair = [edge.source, edge.target].sort().join('|');
        const lane = lanes.get(pair) ?? 0;
        lanes.set(pair, lane + 1);
        const gap = 16 + lane * 8;
        const aSize = size(edge.source),
            bSize = size(edge.target);
        const vertical = orientation === 'vertical';
        const sameLevel =
            Math.abs(vertical ? a.y - b.y : a.x - b.x) <
            (vertical
                ? Math.min(aSize.height, bSize.height)
                : Math.min(aSize.width, bSize.width));
        const forward = vertical ? a.y < b.y : a.x < b.x;
        let start = vertical
            ? {
                  x: a.x + aSize.width / 2 + lane * 8,
                  y: a.y + (!sameLevel && forward ? aSize.height : 0),
              }
            : {
                  x: a.x + (!sameLevel && forward ? aSize.width : 0),
                  y:
                      a.y +
                      (sizes[edge.source] ? aSize.height / 2 : 44) +
                      Math.min(lane * 8, aSize.height / 4),
              };
        let end = vertical
            ? {
                  x: b.x + bSize.width / 2 + lane * 8,
                  y: b.y + (!sameLevel && !forward ? bSize.height : 0),
              }
            : {
                  x: b.x + (!sameLevel && !forward ? bSize.width : 0),
                  y:
                      b.y +
                      (sizes[edge.target] ? bSize.height / 2 : 44) +
                      Math.min(lane * 8, bSize.height / 4),
              };
        let from = {
            x: start.x + (vertical ? 0 : start.x === a.x ? -gap : gap),
            y: start.y + (vertical ? (start.y === a.y ? -gap : gap) : 0),
        };
        let to = {
            x: end.x + (vertical ? 0 : end.x === b.x ? -gap : gap),
            y: end.y + (vertical ? (end.y === b.y ? -gap : gap) : 0),
        };
        const boxes = Object.entries(positions).map(([id, p]) => ({
            id,
            left: p.x - gap,
            right: p.x + size(id).width + gap,
            top: p.y - (sizes[id] ? 0 : gap),
            bottom: p.y + size(id).height + (sizes[id] ? 0 : gap),
        }));
        const clear = (p: Position, q: Position, except?: string) =>
            !boxes.some(
                (box) =>
                    box.id !== except &&
                    (p.x === q.x
                        ? p.x > box.left &&
                          p.x < box.right &&
                          Math.max(p.y, q.y) > box.top &&
                          Math.min(p.y, q.y) < box.bottom
                        : p.y > box.top &&
                          p.y < box.bottom &&
                          Math.max(p.x, q.x) > box.left &&
                          Math.min(p.x, q.x) < box.right),
            );
        // Rack units can touch: choose a free exit without moving physical devices.
        const escape = (id: string, anchor: Position, exit: Position) => {
            if (clear(anchor, exit, id)) return { anchor, exit };
            if (!sizes[id]) return null;
            const p = positions[id],
                box = size(id);
            const candidates = [
                {
                    anchor: { x: p.x, y: p.y + box.height / 2 },
                    exit: { x: p.x - gap, y: p.y + box.height / 2 },
                },
                {
                    anchor: { x: p.x + box.width, y: p.y + box.height / 2 },
                    exit: { x: p.x + box.width + gap, y: p.y + box.height / 2 },
                },
                {
                    anchor: { x: p.x + box.width / 2, y: p.y },
                    exit: { x: p.x + box.width / 2, y: p.y - gap },
                },
                {
                    anchor: { x: p.x + box.width / 2, y: p.y + box.height },
                    exit: { x: p.x + box.width / 2, y: p.y + box.height + gap },
                },
            ];
            return (
                candidates.find((candidate) =>
                    clear(candidate.anchor, candidate.exit, id),
                ) ?? null
            );
        };
        const sourceExit = escape(edge.source, start, from);
        const targetExit = escape(edge.target, end, to);
        if (!sourceExit || !targetExit) continue;
        start = sourceExit.anchor;
        from = sourceExit.exit;
        end = targetExit.anchor;
        to = targetExit.exit;
        const xs = [
            ...new Set([
                from.x,
                to.x,
                ...boxes.flatMap((box) => [box.left, box.right]),
            ]),
        ].sort((a, b) => a - b);
        const ys = [
            ...new Set([
                from.y,
                to.y,
                ...boxes.flatMap((box) => [box.top, box.bottom]),
            ]),
        ].sort((a, b) => a - b);
        const point = (id: number) => ({
            x: xs[id % xs.length],
            y: ys[Math.floor(id / xs.length)],
        });
        const id = (p: Position) =>
            ys.indexOf(p.y) * xs.length + xs.indexOf(p.x);
        const first = id(from),
            last = id(to);
        const open = new Set([first]);
        const distance = new Map([[first, 0]]);
        const previous = new Map<number, number>();
        const score = (id: number) =>
            distance.get(id)! +
            Math.abs(point(id).x - to.x) +
            Math.abs(point(id).y - to.y);
        while (open.size) {
            const current = [...open].reduce((best, next) =>
                score(next) < score(best) ? next : best,
            );
            if (current === last) break;
            open.delete(current);
            const col = current % xs.length,
                row = Math.floor(current / xs.length);
            const adjacent = [
                col > 0 ? current - 1 : -1,
                col + 1 < xs.length ? current + 1 : -1,
                row > 0 ? current - xs.length : -1,
                row + 1 < ys.length ? current + xs.length : -1,
            ];
            const p = point(current);
            for (const next of adjacent) {
                if (next < 0) continue;
                const q = point(next);
                if (!clear(p, q)) continue;
                const cost =
                    distance.get(current)! +
                    Math.abs(p.x - q.x) +
                    Math.abs(p.y - q.y);
                if (cost >= (distance.get(next) ?? Infinity)) continue;
                distance.set(next, cost);
                previous.set(next, current);
                open.add(next);
            }
        }
        if (!distance.has(last)) continue;
        const path = [to];
        let current = last;
        while (current !== first) {
            current = previous.get(current)!;
            path.push(point(current));
        }
        routes[edge.id] = [start, ...path.reverse(), end].filter(
            (p, i, all) => {
                const before = all[i - 1],
                    after = all[i + 1];
                return (
                    !before ||
                    !after ||
                    !(
                        (before.x === p.x && p.x === after.x) ||
                        (before.y === p.y && p.y === after.y)
                    )
                );
            },
        );
    }
    return routes;
}
