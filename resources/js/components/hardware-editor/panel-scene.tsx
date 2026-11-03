import { Group, Image as CanvasImage, Rect } from 'react-konva';
import {
    faceLayers,
    imageBounds,
    portBounds,
    type PanelDocument,
    type Face,
    type Point,
} from '@/lib/hardware-editor/document';
import { PortSymbol } from './port-symbol';

export function PanelScene({
    shell,
    face,
    images,
    dark,
    zoom,
    hideImage = false,
    imageDelta,
    imageOpacity,
    preview,
    selectedPortId,
}: {
    shell: PanelDocument['shell'];
    face: PanelDocument['faces'][Face];
    images: Record<string, HTMLImageElement>;
    dark: boolean;
    zoom: number;
    hideImage?: boolean;
    imageDelta?: Point | null;
    imageOpacity?: number;
    preview?: Map<string, string>;
    selectedPortId?: string;
}) {
    const image = face.image;
    const selected = face.ports.find((port) => port.id === selectedPortId);
    return (
        <>
            <Rect
                visible={!face.shellState.hidden}
                id="shell"
                x={shell.x}
                y={shell.y}
                width={shell.width}
                height={shell.height}
                cornerRadius={shell.radius}
                stroke={dark ? '#64748b' : '#9ba7b6'}
                strokeWidth={shell.border}
                fill="transparent"
            />
            {faceLayers(face).map((layer) =>
                layer.id === 'image' ? (
                    image &&
                    !image.hidden &&
                    !hideImage &&
                    images[image.assetId] && (
                        <Group
                            key="image"
                            clipX={image.clip ? shell.x : undefined}
                            clipY={image.clip ? shell.y : undefined}
                            clipWidth={image.clip ? shell.width : undefined}
                            clipHeight={image.clip ? shell.height : undefined}
                        >
                            <CanvasImage
                                id="panel-image"
                                image={images[image.assetId]}
                                {...imageBounds(image)}
                                x={imageBounds(image).x + (imageDelta?.x ?? 0)}
                                y={imageBounds(image).y + (imageDelta?.y ?? 0)}
                                crop={image.crop}
                                listening={!image.locked}
                                opacity={imageOpacity ?? image.opacity}
                            />
                        </Group>
                    )
                ) : (
                    <Group key={layer.id}>
                        {layer.ids
                            .map((id) => face.ports.find((p) => p.id === id)!)
                            .filter((p) => !p.hidden || p.id === selectedPortId)
                            .map((port) => (
                                <PortSymbol
                                    key={port.id}
                                    port={port}
                                    selected={false}
                                    preview={preview?.get(port.id)}
                                    zoom={zoom}
                                />
                            ))}
                    </Group>
                ),
            )}
            {selected && (
                <Rect
                    {...portBounds(selected)}
                    fill="#3b82f633"
                    stroke={dark ? '#93c5fd' : '#2563eb'}
                    strokeWidth={2.5 / zoom}
                    listening={false}
                />
            )}
        </>
    );
}
