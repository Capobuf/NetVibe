import type Konva from 'konva';
import { createRef } from 'react';
import { flushSync } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { Group, Layer, Stage } from 'react-konva';
import { PanelScene } from '@/components/hardware-editor/panel-scene';
import { panelTransform } from '@/lib/rack-cabling';
import type { Face } from '@/lib/hardware-editor/document';
import type { DocumentDevice } from '@/types/rack-document';

export async function documentPanel(
    device: DocumentDevice,
    face: Face,
    width: number,
    height: number,
): Promise<string | null> {
    const panel = device.model?.panel;
    if (!panel) return null;
    const side = panel.faces[face];
    const images: Record<string, HTMLImageElement> = {};
    if (side.image && !side.image.hidden) {
        const url = device.assetUrls[side.image.assetId];
        if (!url) return null;
        try {
            const response = await fetch(url, {
                signal: AbortSignal.timeout(15000),
            });
            if (!response.ok) return null;
            const objectUrl = URL.createObjectURL(await response.blob());
            try {
                const image = new Image();
                image.src = objectUrl;
                await image.decode();
                images[side.image.assetId] = image;
            } finally {
                URL.revokeObjectURL(objectUrl);
            }
        } catch {
            return null;
        }
    } else if (!side.ports.some((port) => !port.hidden)) return null;
    const host = document.createElement('div');
    host.style.cssText =
        'position:fixed;left:-10000px;top:0;pointer-events:none';
    host.setAttribute('aria-hidden', 'true');
    document.body.append(host);
    const root = createRoot(host);
    const stage = createRef<Konva.Stage>();
    const transform = panelTransform(device, face, width, height);
    try {
        flushSync(() =>
            root.render(
                <Stage ref={stage} width={width} height={height}>
                    <Layer listening={false}>
                        <Group
                            x={transform.x}
                            y={transform.y}
                            scaleX={transform.scale}
                            scaleY={transform.scale}
                        >
                            <PanelScene
                                shell={panel.shell}
                                face={side}
                                images={images}
                                dark={false}
                                zoom={transform.scale}
                            />
                        </Group>
                    </Layer>
                </Stage>,
            ),
        );
        if (!stage.current)
            throw new Error('Impossibile disegnare i pannelli del rack.');
        stage.current.draw();
        return stage.current.toDataURL({ pixelRatio: 3 });
    } finally {
        root.unmount();
        host.remove();
    }
}
