import { Group, Line, Rect, Text } from 'react-konva';
import {
    RACK_UNIT_HEIGHT as U,
    RACK_WIDTH,
} from '@/lib/hardware-editor/document';
import { itemBounds, unitLabel } from '@/lib/rack-layout';
import {
    RACK_MARGIN,
    type topologyRackLayout,
} from '@/lib/topology-rack-layout';

export function TopologyRacks({
    layout,
    dark,
}: {
    layout: ReturnType<typeof topologyRackLayout>;
    dark: boolean;
}) {
    const text = dark ? '#a3a3a3' : '#525252';
    const border = dark ? '#404040' : '#d4d4d4';
    return (
        <Group listening={false}>
            {layout.frames.map(({ rack, x, y, width, height }) => (
                <Group key={rack.id} x={x} y={y}>
                    <Text
                        y={-48}
                        width={width}
                        text={`${rack.name} · ${rack.height_u}U`}
                        fontSize={22}
                        fontFamily="Instrument Sans, sans-serif"
                        fontStyle="bold"
                        fill={text}
                        align="center"
                    />
                    <Rect
                        x={RACK_MARGIN - 24}
                        y={-14}
                        width={RACK_WIDTH + 48}
                        height={height + 28}
                        fill={dark ? '#262626' : '#e5e5e5'}
                        stroke={border}
                        strokeWidth={2}
                        cornerRadius={6}
                    />
                    <Rect
                        x={RACK_MARGIN}
                        width={RACK_WIDTH}
                        height={height}
                        fill={dark ? '#101010' : '#fafafa'}
                    />
                    {Array.from({ length: rack.height_u }, (_, row) => (
                        <Group key={row} y={row * U}>
                            <Line
                                points={[
                                    RACK_MARGIN,
                                    0,
                                    RACK_MARGIN + RACK_WIDTH,
                                    0,
                                ]}
                                stroke={border}
                                dash={[4, 4]}
                            />
                            <Text
                                x={-16}
                                y={U / 2 - 8}
                                width={36}
                                align="right"
                                fontSize={16}
                                fill={text}
                                text={String(
                                    unitLabel(rack.height_u - row - 1, rack),
                                )}
                            />
                            {[
                                RACK_MARGIN - 16,
                                RACK_MARGIN + RACK_WIDTH + 10,
                            ].map((x) => (
                                <Rect
                                    key={x}
                                    x={x}
                                    y={U / 2 - 4}
                                    width={6}
                                    height={8}
                                    fill={border}
                                />
                            ))}
                        </Group>
                    ))}
                    {rack.items
                        .filter((item) => item.kind !== 'device')
                        .map((item) => {
                            const bounds = itemBounds(item, rack.items);
                            const itemX =
                                RACK_MARGIN + (item.x / 100) * RACK_WIDTH;
                            const itemWidth = (item.width / 100) * RACK_WIDTH;
                            const bottom = (rack.height_u - bounds.bottom) * U;
                            return item.kind === 'shelf' ? (
                                <Line
                                    key={item.key}
                                    points={[
                                        itemX,
                                        bottom - 8,
                                        itemX,
                                        bottom,
                                        itemX + itemWidth,
                                        bottom,
                                        itemX + itemWidth,
                                        bottom - 8,
                                    ]}
                                    stroke={text}
                                    strokeWidth={3}
                                />
                            ) : (
                                <Group
                                    key={item.key}
                                    x={itemX}
                                    y={bottom - item.height * U}
                                >
                                    <Rect
                                        width={itemWidth}
                                        height={item.height * U}
                                        fill={dark ? '#262626' : '#e5e5e5'}
                                        stroke={border}
                                    />
                                    <Rect
                                        x={itemWidth / 2 - 28}
                                        y={(item.height * U) / 2 - 3}
                                        width={56}
                                        height={6}
                                        cornerRadius={3}
                                        stroke={text}
                                    />
                                </Group>
                            );
                        })}
                </Group>
            ))}
            {layout.hasOutside && (
                <Text
                    x={layout.outsideX}
                    y={32}
                    text="Fuori rack"
                    fontFamily="Instrument Sans, sans-serif"
                    fontSize={22}
                    fill={text}
                />
            )}
        </Group>
    );
}
