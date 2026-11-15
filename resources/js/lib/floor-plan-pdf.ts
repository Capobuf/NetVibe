import type { PDFDocumentProxy } from 'pdfjs-dist';

/** Opens a PDF in the browser; pdf.js is loaded only when a PDF is chosen. */
export async function openPdf(file: File): Promise<PDFDocumentProxy> {
    const [pdfjs, worker] = await Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
    ]);
    pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
    return pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
}

/** Renders one page with its longest side at `longest` pixels, on a white background. */
export async function renderPdfPage(
    pdf: PDFDocumentProxy,
    number: number,
    longest: number,
): Promise<{ blob: Blob; width: number; height: number }> {
    const page = await pdf.getPage(number);
    const base = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({
        scale: longest / Math.max(base.width, base.height),
    });
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(viewport.width);
    canvas.height = Math.round(viewport.height);
    await page.render({ canvas, viewport }).promise;
    page.cleanup();
    // Line drawings compress best as PNG; scans can be too large and fall back to JPEG.
    let blob = await toBlob(canvas, 'image/png');
    if (blob.size > 15 * 1024 * 1024) blob = await toBlob(canvas, 'image/jpeg');
    canvas.width = 0;
    return {
        blob,
        width: Math.round(viewport.width),
        height: Math.round(viewport.height),
    };
}

function toBlob(canvas: HTMLCanvasElement, type: string) {
    return new Promise<Blob>((resolve, reject) =>
        canvas.toBlob(
            (blob) =>
                blob
                    ? resolve(blob)
                    : reject(new Error('Impossibile convertire la pagina.')),
            type,
            0.9,
        ),
    );
}
