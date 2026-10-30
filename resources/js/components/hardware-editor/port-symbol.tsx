import { Circle, Group, Line, Rect, Text } from 'react-konva';
import {
    connectorTypes,
    portBounds,
    type Port,
} from '@/lib/hardware-editor/document';

export function PortSymbol({
    port,
    selected,
    numbering,
    preview,
    zoom,
    dark,
}: {
    port: Port;
    selected: boolean;
    numbering: boolean;
    preview?: string;
    zoom: number;
    dark: boolean;
}) {
    const { width: w, height: h } = connectorTypes[port.type];
    const ethernet = port.type === 'rj45' || port.type === 'console';
    const b = portBounds(port);
    const ink = dark ? '#edf0f4' : '#263344';
    const accent = '#3b82f6';
    const label = preview ?? port.label;
    const labelWidth = Math.max(40, label.length * 7.5);
    const labelHeight = 15;
    const pos =
        preview !== undefined && port.labelPosition === 'hidden'
            ? 'bottom'
            : port.labelPosition;
    const labelX =
        pos === 'left'
            ? -b.width / 2 - labelWidth - 7
            : pos === 'right'
              ? b.width / 2 + 7
              : -labelWidth / 2;
    const labelY =
        pos === 'top'
            ? -b.height / 2 - labelHeight - 6
            : pos === 'bottom'
              ? b.height / 2 + 6
              : -labelHeight / 2;
    return (
        <Group x={port.x} y={port.y} name="port" id={port.id}>
            <Rect
                x={-b.width / 2 - 4}
                y={-b.height / 2 - 4}
                width={b.width + 8}
                height={b.height + 8}
                cornerRadius={4}
                fill="transparent"
                stroke={selected ? accent : numbering ? '#38bdf8' : undefined}
                strokeWidth={1.5 / zoom}
            />
            <Group
                opacity={port.opacity}
                name="symbol"
                rotation={port.rotation}
                scaleX={port.scale}
                scaleY={port.scale}
            >
                {ethernet ? (
                    <Line
                        points={[
                            -w / 2,
                            -h / 2,
                            w / 2,
                            -h / 2,
                            w / 2,
                            h / 2 - 6,
                            6,
                            h / 2 - 6,
                            6,
                            h / 2,
                            -6,
                            h / 2,
                            -6,
                            h / 2 - 6,
                            -w / 2,
                            h / 2 - 6,
                        ]}
                        closed
                        fill="#17202c"
                        stroke={port.borderColor}
                        strokeWidth={1.5}
                        lineJoin="round"
                    />
                ) : port.type === 'dc' ? (
                    <>
                        <Circle
                            radius={w / 2}
                            fill="#17202c"
                            stroke={port.borderColor}
                            strokeWidth={1.5}
                        />
                        <Circle radius={2.5} fill="#8998aa" />
                    </>
                ) : port.type === 'ac' ? (
                    <Line
                        points={[
                            -w / 2,
                            -h / 2 + 8,
                            -w / 2 + 8,
                            -h / 2,
                            w / 2 - 8,
                            -h / 2,
                            w / 2,
                            -h / 2 + 8,
                            w / 2,
                            h / 2,
                            -w / 2,
                            h / 2,
                        ]}
                        closed
                        fill="#17202c"
                        stroke={port.borderColor}
                        strokeWidth={1.5}
                        lineJoin="round"
                    />
                ) : (
                    <>
                        <Rect
                            x={-w / 2}
                            y={-h / 2}
                            width={w}
                            height={h}
                            cornerRadius={port.type === 'usbC' ? h / 2 : 2}
                            fill="#17202c"
                            stroke={port.borderColor}
                            strokeWidth={1.5}
                        />
                        {(port.type === 'usbA' || port.type === 'usbC') && (
                            <Line
                                points={[-w / 2 + 5, 0, w / 2 - 5, 0]}
                                stroke={
                                    port.type === 'usbA' ? '#5b95d9' : '#8998aa'
                                }
                                strokeWidth={2}
                            />
                        )}
                    </>
                )}
            </Group>
            {port.poe && (
                <Line
                    opacity={port.opacity}
                    listening={false}
                    points={[
                        b.width / 2 + 5,
                        -b.height / 2 - 5,
                        b.width / 2 + 1,
                        -b.height / 2 + 1,
                        b.width / 2 + 5,
                        -b.height / 2 + 1,
                        b.width / 2 + 2,
                        -b.height / 2 + 6,
                    ]}
                    stroke={ink}
                    strokeWidth={1.4}
                />
            )}
            {pos !== 'hidden' && label && (
                <Text
                    opacity={port.opacity}
                    listening={false}
                    x={labelX}
                    y={labelY}
                    width={labelWidth}
                    height={labelHeight}
                    text={label}
                    fontFamily="Instrument Sans, sans-serif"
                    fontSize={12}
                    align="center"
                    fill={preview !== undefined ? accent : ink}
                />
            )}
        </Group>
    );
}
