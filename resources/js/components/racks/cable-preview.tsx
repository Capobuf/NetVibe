import { useEffect, useRef, useState } from 'react';
import { Group, Layer, Line, Rect, Stage, Text } from 'react-konva';
import { DeviceDrawing } from './rack-canvas';
import { useAppearance } from '@/hooks/use-appearance';
import { portBounds } from '@/lib/hardware-editor/document';
import { cableLead, roundedCablePath } from '@/lib/rack-cable-routing';
import {
    devicePorts,
    endLabel,
    panelTransform,
    type Cable,
    type PortAnchor,
} from '@/lib/rack-cabling';
import type { RackDevice } from '@/types/rack';

export function CablePreview({
    cable,
    devices,
    connected,
    onError,
}: {
    cable: Cable;
    devices: RackDevice[];
    connected: boolean;
    onError: (message: string) => void;
}) {
    const host = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);
    const { resolvedAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    useEffect(() => {
        const observer = new ResizeObserver(([entry]) =>
            setWidth(entry.contentRect.width),
        );
        if (host.current) observer.observe(host.current);
        return () => observer.disconnect();
    }, []);
    const stacked = width < 600;
    const cellWidth = stacked ? width - 48 : width / 2 - 48;
    const height = stacked ? 560 : 320;
    const drawings = (['a', 'b'] as const).map((side, index) => {
        const end = cable[side];
        const original = devices.find((device) => device.id === end.device_id);
        const port = devicePorts(original).find(
            (p) => p.id === end.connector_id,
        );
        const face = port?.face ?? 'front';
        if (!original?.model?.panel) return null;
        const panel = original.model.panel;
        const device = port?.hidden
            ? {
                  ...original,
                  model: {
                      ...original.model,
                      panel: {
                          ...panel,
                          faces: {
                              ...panel.faces,
                              [face]: {
                                  ...panel.faces[face],
                                  ports: panel.faces[face].ports.map((p) =>
                                      p.id === port.id
                                          ? { ...p, hidden: false }
                                          : p,
                                  ),
                              },
                          },
                      },
                  },
              }
            : original;
        const x = stacked ? 24 : (index * width) / 2 + 24;
        const y = stacked ? index * 270 + 30 : 30;
        const transform = panelTransform(device, face, cellWidth, 180);
        const anchors: PortAnchor[] = devicePorts(device)
            .filter((p) => p.face === face && !p.hidden)
            .map((p) => {
                const bounds = portBounds(p);
                return {
                    device_id: device.id,
                    connector_id: p.id,
                    face,
                    type: p.type,
                    x: x + transform.x + p.x * transform.scale,
                    y: y + transform.y + p.y * transform.scale,
                    width: bounds.width * transform.scale,
                    height: bounds.height * transform.scale,
                };
            });
        return {
            device,
            face,
            x,
            y,
            anchors,
            selected: anchors.find((p) => p.connector_id === end.connector_id),
        };
    });
    const anchors = drawings.flatMap((drawing) => drawing?.anchors ?? []);
    const a = drawings[0]?.selected;
    const b = drawings[1]?.selected;
    const lane = stacked ? width - 12 : width / 2;
    const start = a && cableLead(a, lane, anchors, 1, 6);
    const end = b && cableLead(b, lane, anchors, 0, 6);
    const points =
        connected && start && end
            ? roundedCablePath([...start, ...end.toReversed()], 2)
            : null;
    const description = (['a', 'b'] as const)
        .map((side) =>
            cable[side].connector_id
                ? endLabel(cable[side], devices, '@')
                : (devices.find((device) => device.id === cable[side].device_id)
                      ?.name ?? (side === 'a' ? 'Partenza' : 'Destinazione')),
        )
        .join(' → ');
    return (
        <figure className="min-w-0 overflow-hidden rounded-lg border bg-muted/30">
            <div
                ref={host}
                role="img"
                aria-label={`Anteprima collegamento: ${description}`}
            >
                {width > 0 && (
                    <Stage width={width} height={height}>
                        <Layer listening={false}>
                            {drawings.map(
                                (drawing, index) =>
                                    drawing && (
                                        <Group
                                            key={index}
                                            x={drawing.x}
                                            y={drawing.y}
                                        >
                                            <Text
                                                text={
                                                    drawing.face === 'front'
                                                        ? 'FRONTE'
                                                        : 'RETRO'
                                                }
                                                y={-16}
                                                fontSize={11}
                                                fill={
                                                    dark ? '#94a3b8' : '#64748b'
                                                }
                                            />
                                            <DeviceDrawing
                                                device={drawing.device}
                                                face={drawing.face}
                                                width={cellWidth}
                                                height={180}
                                                dark={dark}
                                                zoom={1}
                                                onError={onError}
                                            />
                                        </Group>
                                    ),
                            )}
                            {points && (
                                <Line
                                    points={points}
                                    bezier
                                    stroke={cable.color}
                                    strokeWidth={3}
                                    lineCap="round"
                                />
                            )}
                            {[a, b].map(
                                (anchor, index) =>
                                    anchor && (
                                        <Rect
                                            key={index}
                                            x={anchor.x - anchor.width / 2}
                                            y={anchor.y - anchor.height / 2}
                                            width={anchor.width}
                                            height={anchor.height}
                                            fill="#22c55e66"
                                            stroke={
                                                dark ? '#93c5fd' : '#1d4ed8'
                                            }
                                            strokeWidth={2}
                                        />
                                    ),
                            )}
                        </Layer>
                    </Stage>
                )}
            </div>
            <figcaption className="border-t px-4 py-2 text-center text-sm break-words">
                {description}
            </figcaption>
        </figure>
    );
}
