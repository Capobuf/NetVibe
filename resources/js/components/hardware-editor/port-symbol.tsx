import { Group, Line, Path, Rect, Text } from 'react-konva';
import { connectorShapes } from '@/lib/hardware-editor/connector-shapes';
import {
    portBounds,
    portBodyBounds,
    portLabelLayout,
    portLabelFontFamily,
    type Port,
} from '@/lib/hardware-editor/document';

export function PortSymbol({
    port,
    selected,
    preview,
    zoom,
}: {
    port: Port;
    selected: boolean;
    preview?: string;
    zoom: number;
}) {
    const shape = connectorShapes[port.type];
    const b = portBounds(port);
    const label = preview ?? port.label;
    const layout = portLabelLayout(port, label);
    const ethernet = port.type === 'rj45' || port.type === 'console';
    const body = portBodyBounds(port);
    return (
        <Group x={port.x} y={port.y} name="port" id={port.id}>
            <Rect
                x={-b.width / 2 - 4}
                y={-b.height / 2 - 4}
                width={b.width + 8}
                height={b.height + 8}
                cornerRadius={4}
                fill="transparent"
                stroke={selected ? '#3b82f6' : undefined}
                strokeWidth={1.5 / zoom}
            />
            <Group
                opacity={port.opacity}
                name="symbol"
                rotation={port.rotation}
                scaleX={port.scale}
                scaleY={port.scale}
            >
                <Path
                    data={shape.outline}
                    fill="#17202c"
                    stroke={port.borderColor}
                    strokeWidth={1.5}
                    lineJoin="round"
                />
                {shape.detail && (
                    <Path
                        data={shape.detail}
                        fill={
                            port.type === 'dc' ||
                            port.type === 'ac' ||
                            port.type === 'acC19'
                                ? port.borderColor
                                : undefined
                        }
                        stroke={port.borderColor}
                        strokeWidth={1.5}
                        lineJoin="round"
                    />
                )}
            </Group>
            {label &&
                (port.labelPosition !== 'hidden' || preview !== undefined) && (
                    <Group opacity={port.opacity}>
                        <Rect
                            x={layout.x}
                            y={layout.y}
                            width={layout.width}
                            height={layout.height}
                            fill="#17202c"
                            cornerRadius={port.scale}
                        />
                        <Text
                            listening={false}
                            x={layout.x}
                            y={layout.y}
                            width={layout.width}
                            height={layout.height}
                            fontSize={layout.fontSize}
                            text={layout.text}
                            fontFamily={portLabelFontFamily}
                            align="center"
                            verticalAlign="middle"
                            wrap="none"
                            fill={port.borderColor}
                        />
                    </Group>
                )}
            {ethernet && port.poe && (
                <Line
                    listening={false}
                    opacity={port.opacity}
                    x={body.x - port.x + body.width - 5 * port.scale}
                    y={body.y - port.y + 5 * port.scale}
                    scaleX={port.scale * 0.35}
                    scaleY={port.scale * 0.35}
                    points={[2, -9, -5, 0, 0, 0, -2, 7, 5, -3, 1, -3]}
                    closed
                    fill={port.borderColor}
                    stroke={port.borderColor}
                    strokeWidth={0.6}
                    lineJoin="round"
                />
            )}
        </Group>
    );
}
