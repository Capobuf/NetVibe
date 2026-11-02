import type { LabelLayout } from './cable-labels';

export async function cableLabelPdf(layouts: LabelLayout[]): Promise<Blob> {
    if (!layouts.length || layouts.some((layout) => layout.errors.length))
        throw new Error('Correggi gli avvisi prima di generare il PDF.');
    const { jsPDF } = await import('jspdf');
    const pdf = new jsPDF({
        unit: 'mm',
        format: [layouts[0].length, layouts[0].width],
        orientation:
            layouts[0].length >= layouts[0].width ? 'landscape' : 'portrait',
        compress: true,
    });
    pdf.setProperties({ title: 'Etichette cavo' });
    pdf.viewerPreferences({ PrintScaling: 'None' });
    for (const [index, layout] of layouts.entries()) {
        if (index)
            pdf.addPage(
                [layout.length, layout.width],
                layout.length >= layout.width ? 'landscape' : 'portrait',
            );
        const canvas = document.createElement('canvas');
        const scale = 600 / 25.4;
        canvas.width = Math.ceil(layout.length * scale);
        canvas.height = Math.ceil(layout.width * scale);
        const context = canvas.getContext('2d');
        if (!context)
            throw new Error(
                'Impossibile preparare il documento in questo browser.',
            );
        context.scale(
            canvas.width / layout.length,
            canvas.height / layout.width,
        );
        context.fillStyle = 'white';
        context.fillRect(0, 0, layout.length, layout.width);
        context.fillStyle = 'black';
        for (const [face, texts] of layout.faces.entries()) {
            context.save();
            if (face) {
                context.translate(layout.faceLength + layout.wrap, 0);
                if (layout.rotation === 180) {
                    context.translate(layout.faceLength, layout.width);
                    context.rotate(Math.PI);
                }
            }
            for (const text of texts) {
                context.font = `${text.size}px Arial`;
                context.textAlign = text.align;
                context.fillText(text.text, text.x, text.y);
            }
            context.restore();
        }
        // Rasterize only the shared text layout: preview guides and cable simulation never enter the PDF.
        pdf.addImage(
            canvas.toDataURL('image/png'),
            'PNG',
            0,
            0,
            layout.length,
            layout.width,
        );
        canvas.width = canvas.height = 0;
    }
    return pdf.output('blob');
}
