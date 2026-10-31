import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    cableLead,
    roundedCablePath,
} from '../../resources/js/lib/rack-cable-routing.ts';
import type { PortAnchor } from '../../resources/js/lib/rack-cabling.ts';

test('cables leave the lower port row on separate tracks without crossing neighboring ports', () => {
    const port = (id: string, x: number, y: number): PortAnchor => ({
        device_id: 1,
        connector_id: id,
        face: 'front',
        type: 'rj45',
        x,
        y,
        width: 12,
        height: 12,
    });
    const a = port('a', 40, 60);
    const b = port('b', 80, 60);
    const upper = port('upper', 40, 40);
    const neighbor = port('unused', 120, 60);
    const ports = [a, b, upper, neighbor];
    const leadA = cableLead({ ...a }, 180, ports, 1, 6);
    const leadB = cableLead(b, 186, ports, 0, 6);
    assert.ok(leadA && leadB);
    assert.equal(leadA[1].x, a.x);
    assert.equal(leadB[1].x, b.x);
    assert.ok(leadA[1].y > a.y + a.height / 2);
    assert.ok(leadB[1].y > b.y + b.height / 2);
    assert.ok(Math.abs(leadA.at(-1)!.y - leadB.at(-1)!.y) >= 6);

    const leadUpper = cableLead(upper, 192, ports, 0, 6);
    assert.ok(leadUpper);
    assert.ok(leadUpper[1].y < upper.y, 'the lower row blocks a downward exit');
    for (const [source, lead] of [
        [a, leadA],
        [b, leadB],
        [upper, leadUpper],
    ] as const) {
        const curve = roundedCablePath(lead, 2);
        assert.deepEqual(curve.slice(0, 2), [source.x, source.y]);
        assert.equal((curve.length - 2) % 6, 0);
        for (let i = 2; i < curve.length; i += 6) {
            for (let step = 0; step <= 20; step++) {
                const t = step / 20;
                const u = 1 - t;
                const x =
                    u ** 3 * curve[i - 2] +
                    3 * u ** 2 * t * curve[i] +
                    3 * u * t ** 2 * curve[i + 2] +
                    t ** 3 * curve[i + 4];
                const y =
                    u ** 3 * curve[i - 1] +
                    3 * u ** 2 * t * curve[i + 1] +
                    3 * u * t ** 2 * curve[i + 3] +
                    t ** 3 * curve[i + 5];
                for (const obstacle of ports.filter((p) => p !== source)) {
                    assert.ok(
                        Math.abs(x - obstacle.x) >= obstacle.width / 2 ||
                            Math.abs(y - obstacle.y) >= obstacle.height / 2,
                        `cable from ${source.connector_id} crosses ${obstacle.connector_id}`,
                    );
                }
            }
        }
    }
});
