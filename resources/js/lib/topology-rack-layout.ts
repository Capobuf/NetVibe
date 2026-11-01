import { RACK_UNIT_HEIGHT, RACK_WIDTH } from './hardware-editor/document';
import { itemBounds } from './rack-layout';
import { NODE_HEIGHT, NODE_WIDTH, type Position } from './topology-layout';
import type { TopologyNode } from '../types/topology';
import type { Rack } from '../types/rack';

export type TopologyRack = Pick<
    Rack,
    'id' | 'name' | 'height_u' | 'numbering' | 'items'
>;
export type NodeSize = { width: number; height: number };
export const RACK_MARGIN = 48;

export function topologyRackLayout(
    nodes: TopologyNode[],
    racks: TopologyRack[],
) {
    const positions: Record<string, Position> = {};
    const sizes: Record<string, NodeSize> = {};
    const frames = racks.map((rack, index) => ({
        rack,
        x: 60 + index * (RACK_WIDTH + RACK_MARGIN * 2 + 180),
        y: 80,
        width: RACK_WIDTH + RACK_MARGIN * 2,
        height: rack.height_u * RACK_UNIT_HEIGHT,
    }));
    for (const frame of frames) {
        for (const item of frame.rack.items) {
            const node = nodes.find(
                (node) =>
                    node.device_id === item.device_id && item.kind === 'device',
            );
            if (!node) continue;
            const bounds = itemBounds(item, frame.rack.items);
            positions[node.id] = {
                x: frame.x + RACK_MARGIN + (bounds.left / 100) * RACK_WIDTH,
                y:
                    frame.y +
                    (frame.rack.height_u - bounds.top) * RACK_UNIT_HEIGHT,
            };
            sizes[node.id] = {
                width: (item.width / 100) * RACK_WIDTH,
                height: item.height * RACK_UNIT_HEIGHT,
            };
        }
    }
    const outsideX = frames.length
        ? frames.at(-1)!.x + frames.at(-1)!.width + 180
        : 60;
    const outside = nodes.filter((node) => !positions[node.id]);
    for (const [index, node] of outside.entries()) {
        positions[node.id] = {
            x: outsideX + (index % 2) * (NODE_WIDTH + 48),
            y: 80 + Math.floor(index / 2) * (NODE_HEIGHT + 60),
        };
    }
    return {
        positions,
        sizes,
        frames,
        outsideX,
        hasOutside: outside.length > 0,
    };
}
