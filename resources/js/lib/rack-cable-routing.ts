import type { Point } from '@/lib/hardware-editor/document';
import type { PortAnchor } from '@/lib/rack-cabling';

export function cableLead(
    port: PortAnchor,
    lane: number,
    ports: PortAnchor[],
    slot: number,
    gap: number,
    // Preferred heights for the horizontal run; with them a path is always returned.
    preferred?: number[],
): Point[] | null {
    const obstacles = ports
        .filter(
            (p) =>
                p.face === port.face &&
                (p.device_id !== port.device_id ||
                    p.connector_id !== port.connector_id),
        )
        .map((p) => ({
            left: p.x - p.width / 2 - gap / 2,
            right: p.x + p.width / 2 + gap / 2,
            top: p.y - p.height / 2 - gap / 2,
            bottom: p.y + p.height / 2 + gap / 2,
        }));
    const clear = (a: Point, b: Point) =>
        !obstacles.some((box) =>
            a.x === b.x
                ? a.x > box.left &&
                  a.x < box.right &&
                  Math.max(a.y, b.y) > box.top &&
                  Math.min(a.y, b.y) < box.bottom
                : a.y > box.top &&
                  a.y < box.bottom &&
                  Math.max(a.x, b.x) > box.left &&
                  Math.min(a.x, b.x) < box.right,
        );
    const offset = gap * (slot + 1);
    const below = port.y + port.height / 2 + offset;
    const above = port.y - port.height / 2 - offset;
    const levels = preferred ?? [
        below,
        ...obstacles
            .map((box) => box.bottom + offset)
            .filter((y) => y > below)
            .sort((a, b) => a - b),
        above,
        ...obstacles
            .map((box) => box.top - offset)
            .filter((y) => y < above)
            .sort((a, b) => b - a),
    ];
    const exits = [
        port.x,
        port.x - port.width / 2 - gap,
        port.x + port.width / 2 + gap,
    ];
    const pathTo = (x: number, y: number) =>
        [port, { x, y: port.y }, { x, y }, { x: lane, y }].filter(
            (p, i, points) =>
                i === 0 || p.x !== points[i - 1].x || p.y !== points[i - 1].y,
        );
    // Keep routing local to the port rows and the existing side corridor.
    for (const x of exits) {
        for (const y of levels) {
            const path = pathTo(x, y);
            if (path.slice(1).every((p, i) => clear(path[i], p))) return path;
        }
    }
    return preferred ? pathTo(port.x, preferred[0]) : null;
}

// Assigns a vertical lane to each bundle. Bundles whose spans overlap get different lanes, shorter
// spans closer to their rack (zone -1 hugs the left edge, 1 the right one, 0 crosses between them),
// so nested bundles do not cross.
export function trunkLanes(
    spans: { top: number; bottom: number; zone: -1 | 0 | 1 }[],
    left: number,
    right: number,
    spacing: number,
): number[] {
    const tracks = ([-1, 0, 1] as const).flatMap((zone) => {
        const zoneTracks: number[][] = [];
        spans
            .map((_, index) => index)
            .filter((index) => spans[index].zone === zone)
            .sort(
                (a, b) =>
                    spans[a].bottom -
                    spans[a].top -
                    (spans[b].bottom - spans[b].top),
            )
            .forEach((index) => {
                const free = zoneTracks.find((track) =>
                    track.every(
                        (other) =>
                            spans[index].top > spans[other].bottom + spacing ||
                            spans[other].top > spans[index].bottom + spacing,
                    ),
                );
                if (free) free.push(index);
                else zoneTracks.push([index]);
            });
        return zone === 1 ? zoneTracks.reverse() : zoneTracks;
    });
    const step = Math.min(
        spacing,
        (right - left) / Math.max(1, tracks.length - 1),
    );
    const lanes: number[] = [];
    tracks.forEach((track, position) => {
        for (const index of track)
            lanes[index] =
                (left + right) / 2 +
                (position - (tracks.length - 1) / 2) * step;
    });
    return lanes;
}

export function roundedCablePath(points: Point[], radius: number): number[] {
    const result = [points[0].x, points[0].y];
    let last = points[0];
    const lineTo = (point: Point) => {
        result.push(last.x, last.y, point.x, point.y, point.x, point.y);
        last = point;
    };
    for (let i = 1; i < points.length - 1; i++) {
        const previous = points[i - 1];
        const corner = points[i];
        const next = points[i + 1];
        const before = Math.hypot(corner.x - previous.x, corner.y - previous.y);
        const after = Math.hypot(next.x - corner.x, next.y - corner.y);
        if (!before || !after) continue;
        const bend = Math.min(radius, before / 2, after / 2);
        const entry = {
            x: corner.x + ((previous.x - corner.x) * bend) / before,
            y: corner.y + ((previous.y - corner.y) * bend) / before,
        };
        const exit = {
            x: corner.x + ((next.x - corner.x) * bend) / after,
            y: corner.y + ((next.y - corner.y) * bend) / after,
        };
        lineTo(entry);
        result.push(
            entry.x + ((corner.x - entry.x) * 2) / 3,
            entry.y + ((corner.y - entry.y) * 2) / 3,
            exit.x + ((corner.x - exit.x) * 2) / 3,
            exit.y + ((corner.y - exit.y) * 2) / 3,
            exit.x,
            exit.y,
        );
        last = exit;
    }
    lineTo(points[points.length - 1]);
    return result;
}
