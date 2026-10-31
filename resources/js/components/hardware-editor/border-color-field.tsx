import { ColorField } from '@/components/color-field';
import type { Editor } from './use-editor';

export function BorderColorField({
    editor,
    value,
}: {
    editor: Editor;
    value?: string;
}) {
    return (
        <ColorField
            label="Colore bordo"
            value={value}
            fallbackValue={editor.selected[0].borderColor}
            onPreview={editor.setBorderColorPreview}
            onChange={(borderColor) => editor.patchPorts({ borderColor })}
        />
    );
}
