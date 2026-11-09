import { connectorShapes } from '@/lib/hardware-editor/connector-shapes';
import {
    connectorTypes,
    type ConnectorType,
} from '@/lib/hardware-editor/document';

export function ConnectorIcon({ type }: { type: ConnectorType }) {
    const { width, height, color } = connectorTypes[type];
    const shape = connectorShapes[type];
    return (
        <svg
            aria-hidden="true"
            className="size-6 shrink-0"
            viewBox={`${-width / 2 - 2} ${-height / 2 - 2} ${width + 4} ${height + 4}`}
            fill="#17202c"
            stroke={color}
            strokeWidth={1.5}
            strokeLinejoin="round"
        >
            <path d={shape.outline} />
            {shape.detail && (
                <path
                    d={shape.detail}
                    fill={
                        ['dc', 'ac', 'acC19', 'schuko', 'schukoBipasso'].includes(type)
                            ? color
                            : 'none'
                    }
                />
            )}
        </svg>
    );
}
