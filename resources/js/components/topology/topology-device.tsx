import { Group, Circle, Line, Rect, Text } from 'react-konva';
import { PanelScene } from '@/components/hardware-editor/panel-scene';
import {
    boundsOf,
    imageBounds,
    portBounds,
} from '@/lib/hardware-editor/document';
import { NODE_WIDTH } from '@/lib/topology-layout';
import type { TopologyModel } from '@/types/topology';

export function TopologyDevice({
    model,
    images,
    dark,
    zoom,
    known,
    selectedPortId,
    physicalSize,
}: {
    model?: TopologyModel;
    images: Record<string, HTMLImageElement>;
    dark: boolean;
    zoom: number;
    known: boolean;
    selectedPortId?: string;
    physicalSize?: { width: number; height: number };
}) {
    const panel = model?.panel;
    const front = panel?.faces.front;
    const selectedFace =
        panel &&
        Object.values(panel.faces).find((face) =>
            face.ports.some((port) => port.id === selectedPortId),
        );
    const face =
        selectedFace ??
        (panel &&
        front &&
        (!front.shellState.hidden ||
            front.ports.some((p) => !p.hidden) ||
            (front.image && !front.image.hidden))
            ? front
            : panel?.faces.rear);
    if (panel && face) {
        const box = boundsOf([
            panel.shell,
            ...face.ports
                .filter((p) => !p.hidden || p.id === selectedPortId)
                .map(portBounds),
            ...(face.image && !face.image.hidden && !face.image.clip
                ? [imageBounds(face.image)]
                : []),
        ]);
        const scale = Math.min(
            (physicalSize?.width ?? NODE_WIDTH - 36) / Math.max(1, box.width),
            (physicalSize?.height ?? 64) / Math.max(1, box.height),
        );
        return (
            <Group
                x={
                    ((physicalSize?.width ?? NODE_WIDTH) - box.width * scale) /
                        2 -
                    box.x * scale
                }
                y={
                    physicalSize
                        ? physicalSize.height - (box.height + box.y) * scale
                        : 44 - (box.height * scale) / 2 - box.y * scale
                }
                scaleX={scale}
                scaleY={scale}
                listening={false}
            >
                <PanelScene
                    shell={panel.shell}
                    face={face}
                    images={Object.fromEntries(
                        Object.entries(model!.assetUrls)
                            .filter(([, url]) => images[url])
                            .map(([id, url]) => [id, images[url]]),
                    )}
                    dark={dark}
                    zoom={scale * zoom}
                    selectedPortId={selectedPortId}
                />
            </Group>
        );
    }
    const stroke = dark ? '#a3a3a3' : '#737373';
    return (
        <Group
            x={(physicalSize?.width ?? NODE_WIDTH) / 2}
            y={physicalSize ? physicalSize.height / 2 : 44}
            scaleX={
                physicalSize
                    ? Math.min(
                          1,
                          physicalSize.height / 64,
                          physicalSize.width / 64,
                      )
                    : 1
            }
            scaleY={
                physicalSize
                    ? Math.min(
                          1,
                          physicalSize.height / 64,
                          physicalSize.width / 64,
                      )
                    : 1
            }
            listening={false}
        >
            <Circle
                radius={30}
                stroke={stroke}
                strokeWidth={1.5}
                dash={known ? undefined : [3, 4]}
            />
            {known ? (
                <>
                    <Line
                        points={[0, -7, 0, 5, -14, 5, -14, 12]}
                        stroke={stroke}
                        strokeWidth={2}
                    />
                    <Line
                        points={[0, 5, 14, 5, 14, 12]}
                        stroke={stroke}
                        strokeWidth={2}
                    />
                    <Rect
                        x={-7}
                        y={-20}
                        width={14}
                        height={13}
                        cornerRadius={2}
                        stroke={stroke}
                        strokeWidth={2}
                    />
                    {[-14, 14].map((x) => (
                        <Rect
                            key={x}
                            x={x - 5}
                            y={12}
                            width={10}
                            height={8}
                            cornerRadius={2}
                            stroke={stroke}
                            strokeWidth={2}
                        />
                    ))}
                </>
            ) : (
                <Text
                    x={-15}
                    y={-16}
                    width={30}
                    text="?"
                    align="center"
                    fontSize={30}
                    fill={stroke}
                />
            )}
        </Group>
    );
}
