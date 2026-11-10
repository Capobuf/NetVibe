import { jsPDF } from 'jspdf';
import { autoTable, type RowInput } from 'jspdf-autotable';
import regularFont from '../../fonts/DejaVuSans.ttf?url';
import boldFont from '../../fonts/DejaVuSans-Bold.ttf?url';
import {
    RACK_UNIT_HEIGHT,
    RACK_WIDTH,
    type Face,
} from '@/lib/hardware-editor/document';
import {
    cablesOfType,
    isCableSection,
    partLabels,
    visibleParts,
    visibleSections,
    type PlanOptions,
    type PlanSection,
} from '@/lib/installation-plan';
import { cableCode, cableTypes } from '@/lib/rack-cabling';
import {
    devicePosition,
    documentLinks,
    rackOccupancy,
    rackSections,
    unitRange,
} from '@/lib/rack-document-data';
import { documentPanel } from '@/lib/rack-document-panel';
import { itemBounds, unitLabel } from '@/lib/rack-layout';
import type {
    DocumentCable,
    DocumentDevice,
    RackDocumentProps,
} from '@/types/rack-document';

const ink = '#20343E';
const muted = '#58666E';
const rule = '#CCD5DA';
const paper = '#F2F5F7';
const margin = 14;
const inventoryWidths: Record<string, number> = {
    name: 36,
    manufacturer: 23,
    model: 30,
    serial: 27,
    assetTag: 23,
    rack: 29,
    units: 14,
};
const cableWidths: Record<string, number> = {
    reference: 22,
    name: 30,
    a: 40,
    b: 40,
    color: 14,
    purpose: 36,
};

// Fonts, logo and panel images do not change while options do: build them once per page visit.
let fonts: Promise<string[]> | undefined;
let logo: Promise<string | null> | undefined;
const panels = new WeakMap<
    DocumentDevice,
    Map<string, ReturnType<typeof documentPanel>>
>();

function panelImage(
    device: DocumentDevice,
    face: Face,
    width: number,
    height: number,
) {
    const cache = panels.get(device) ?? new Map();
    panels.set(device, cache);
    const key = `${face}:${width}:${height}`;
    if (!cache.has(key))
        cache.set(
            key,
            documentPanel(device, face, width, height).catch(
                (reason: unknown) => {
                    cache.delete(key);
                    throw reason;
                },
            ),
        );
    return cache.get(key)!;
}

function logoImage() {
    logo ??= (async () => {
        try {
            const image = new Image();
            image.src = '/branding/logo-black.svg';
            await image.decode();
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 480;
            canvas.getContext('2d')?.drawImage(image, 0, 0, 480, 480);
            return canvas.toDataURL('image/png');
        } catch {
            return null;
        }
    })();
    return logo;
}

async function fontData(url: string) {
    const response = await fetch(url);
    if (!response.ok)
        throw new Error('Impossibile caricare i caratteri del PDF. Riprova.');
    const bytes = new Uint8Array(await response.arrayBuffer());
    let binary = '';
    for (let i = 0; i < bytes.length; i += 16384)
        binary += String.fromCharCode(...bytes.subarray(i, i + 16384));
    return btoa(binary);
}

export async function rackDocumentPdf(
    { company, site, rack: scope, document: data }: RackDocumentProps,
    options: PlanOptions,
) {
    fonts ??= Promise.all([fontData(regularFont), fontData(boldFont)]).catch(
        (reason: unknown) => {
            fonts = undefined;
            throw reason;
        },
    );
    const [fontFiles, logoData] = await Promise.all([
        fonts,
        options.logo || options.pageHeader ? logoImage() : null,
    ]);
    const title = options.title.trim();
    const pdf = new jsPDF({
        unit: 'mm',
        format: 'a4',
        orientation: options.orientation,
        compress: true,
        putOnlyUsedFonts: true,
    });
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const contentWidth = pageWidth - margin * 2;
    const bottom = pageHeight - 18;
    for (const [index, style] of ['normal', 'bold'].entries()) {
        pdf.addFileToVFS(`Document-${style}.ttf`, fontFiles[index]);
        pdf.addFont(`Document-${style}.ttf`, 'Document', style);
    }
    pdf.setFont('Document');
    pdf.setProperties({
        title,
        subject: [company.name, site.name, scope?.name]
            .filter(Boolean)
            .join(' · '),
        creator: 'NetVibe',
    });
    pdf.setFontSize(8);
    const context = [company.name, site.name].filter(Boolean).join(' / ');
    const headerLines: string[] = pdf.splitTextToSize(
        context,
        contentWidth - 22,
    );
    const top = options.pageHeader ? 15 + headerLines.length * 3.5 : 12;
    let y = top;
    const warnings = new Set<string>();
    const devices = new Map(data.devices.map((device) => [device.id, device]));
    const links = documentLinks(data.cables);
    const sections = visibleSections(options);
    const topology = sections.some((section) => section.id === 'topology');
    const linkForCable = new Map(
        links.flatMap((link) =>
            link.cables.map((cable) => [cable.id, link.reference] as const),
        ),
    );

    // Starts a new page unless the current one is still empty.
    function page() {
        if (y <= top) return;
        pdf.addPage();
        y = top;
    }
    function room(height: number) {
        if (y + height > bottom) page();
    }
    function write(value: string, size = 10, bold = false) {
        pdf.setFont('Document', bold ? 'bold' : 'normal')
            .setFontSize(size)
            .setTextColor(ink);
        const lines: string[] = pdf.splitTextToSize(value, contentWidth);
        const lineHeight = size * 0.45;
        for (const line of lines) {
            room(lineHeight);
            pdf.text(line, margin, y + lineHeight * 0.8);
            y += lineHeight;
        }
    }
    // First page of each part, so the preview can reopen where the user is working.
    const anchors: Record<string, number> = { cover: 1 };
    let anchor: string | null = null;
    function heading(value: string) {
        room(25);
        if (anchor) anchors[anchor] = pdf.getNumberOfPages();
        anchor = null;
        y += 5;
        write(value, 15, true);
        y += 4;
    }
    function table(
        head: string[],
        body: RowInput[],
        weights: number[],
        didDrawCell?: Parameters<typeof autoTable>[1]['didDrawCell'],
    ) {
        if (!body.length) {
            write('Nessun elemento documentato.', 9);
            return;
        }
        const total = weights.reduce((sum, weight) => sum + weight, 0);
        const widths = weights.map((weight) => (weight / total) * contentWidth);
        autoTable(pdf, {
            startY: y,
            head: [head],
            body,
            margin: { top, bottom: 18, left: margin, right: margin },
            tableWidth: contentWidth,
            theme: 'plain',
            showHead: 'everyPage',
            rowPageBreak: 'avoid',
            styles: {
                font: 'Document',
                fontSize: 8,
                cellPadding: 2.1,
                textColor: ink,
                overflow: 'linebreak',
                lineColor: rule,
                lineWidth: { bottom: 0.15 },
            },
            headStyles: {
                fillColor: ink,
                textColor: '#FFFFFF',
                fontStyle: 'bold',
                fontSize: 7.5,
            },
            alternateRowStyles: { fillColor: paper },
            columnStyles: Object.fromEntries(
                widths.map((width, index) => [index, { cellWidth: width }]),
            ),
            didDrawCell,
            didDrawPage: ({ cursor }) => {
                if (cursor) y = cursor.y;
            },
        });
        y += 4;
    }
    function short(value: string, width: number, size = 7) {
        pdf.setFont('Document', 'normal').setFontSize(size);
        if (pdf.getTextWidth(value) <= width) return value;
        let result = value;
        while (result.length && pdf.getTextWidth(`${result}…`) > width)
            result = result.slice(0, -1);
        return `${result}…`;
    }

    function cover() {
        if (options.cover) y += 40;
        if (options.logo && logoData) {
            pdf.addImage(logoData, 'PNG', margin, y, 24, 24);
            y += 30;
        }
        write(title, 25, true);
        y += 3;
        write([company.name, site.name].filter(Boolean).join(' · '), 11);
        write(`Ambito: ${scope ? scope.name : 'Intera sede'}`, 11, true);
        write(
            `Generato il ${new Date(data.generatedAt).toLocaleString('it-IT', { timeZone: 'Europe/Rome', dateStyle: 'long', timeStyle: 'short' })} · Europe/Rome`,
            8,
        );
        if (options.summary) {
            y += 4;
            write(
                `${data.racks.length} rack     /     ${data.devices.length} ${data.devices.length === 1 ? 'dispositivo' : 'dispositivi'}     /     ${data.cables.length} ${data.cables.length === 1 ? 'cavo' : 'cavi'}`,
                10,
            );
        }
        const notes = options.notes.trim();
        if (notes) {
            y += 8;
            write('Note', 11, true);
            y += 1;
            write(notes, 9);
        }
        if (options.cover) page();
        else y += 4;
    }

    function inventory(section: PlanSection) {
        room(25);
        heading(section.title);
        const columns = visibleParts(section);
        const selectedRacks = new Set(data.racks.map((rack) => rack.id));
        const sorted = data.devices.toSorted((a, b) => {
            const aIndex = data.racks.findIndex(
                (rack) => rack.id === a.rack?.id,
            );
            const bIndex = data.racks.findIndex(
                (rack) => rack.id === b.rack?.id,
            );
            if (aIndex !== bIndex)
                return (
                    (aIndex < 0 ? Infinity : aIndex) -
                    (bIndex < 0 ? Infinity : bIndex)
                );
            const position = (device: DocumentDevice) =>
                device.placement
                    ? itemBounds(device.placement, [
                          device.placement,
                          ...device.supports,
                      ]).top
                    : -1;
            return (
                (a.rack?.name ?? '').localeCompare(b.rack?.name ?? '', 'it') ||
                position(b) - position(a) ||
                a.name.localeCompare(b.name, 'it')
            );
        });
        const rows: RowInput[] = [];
        let previousGroup = '';
        for (const device of sorted) {
            const group =
                device.rack && selectedRacks.has(device.rack.id)
                    ? `Rack · ${device.rack.name}`
                    : 'Dispositivi collegati esterni all’ambito';
            if (previousGroup !== group)
                rows.push([
                    {
                        content: group,
                        colSpan: columns.length,
                        styles: { fontStyle: 'bold', fillColor: '#DFE7EB' },
                    },
                ]);
            previousGroup = group;
            const cells: Record<string, string> = {
                name: device.name,
                manufacturer: device.manufacturer ?? '—',
                model: device.model?.name ?? '—',
                serial: device.serial_number || '—',
                assetTag: device.asset_tag || '—',
                rack: device.rack?.name ?? 'Fuori rack',
                units: devicePosition(device),
            };
            rows.push(columns.map((column) => cells[column]));
        }
        table(
            columns.map((column) => partLabels[column]),
            rows,
            columns.map((column) => inventoryWidths[column]),
        );
    }

    function cables(section: PlanSection, type: DocumentCable['type']) {
        room(35);
        heading(section.title);
        const columns = visibleParts(section);
        const items = cablesOfType(data.cables, type);
        const end = (value: DocumentCable['a']) =>
            `${value?.device ?? '—'}\n${value?.port || 'Porta non documentata'}`;
        const colorColumn = columns.indexOf('color');
        table(
            columns.map((column) =>
                column === 'reference' && topology
                    ? 'Cavo · tratta'
                    : partLabels[column],
            ),
            items.map((cable) => {
                const cells: Record<string, string> = {
                    reference: [
                        cableCode(cable),
                        topology && linkForCable.get(cable.id),
                    ]
                        .filter(Boolean)
                        .join(' · '),
                    name: cable.name || '—',
                    a: end(cable.a),
                    b: end(cable.b),
                    color: '',
                    purpose: cable.purpose || '—',
                };
                return columns.map((column) => cells[column]);
            }),
            columns.map((column) => cableWidths[column]),
            ({ section: part, column, row, cell }) => {
                if (part !== 'body' || column.index !== colorColumn) return;
                const color = items[row.index]?.color;
                if (!color) return;
                pdf.setFillColor(color).setDrawColor(muted).setLineWidth(0.2);
                pdf.circle(cell.x + 4, cell.y + cell.height / 2, 1.8, 'FD');
            },
        );
    }

    async function racks(plan: PlanSection) {
        const parts = visibleParts(plan);
        const faces = (['front', 'rear'] as Face[]).filter((face) =>
            parts.includes(face),
        );
        for (const rack of data.racks) {
            for (const [sectionIndex, section] of (faces.length
                ? rackSections(rack)
                : []
            ).entries()) {
                page();
                heading(rack.name);
                write(
                    `${plan.title} · ${rack.height_u}U · ${rack.numbering === 'bottom-up' ? 'Numerazione dal basso (bottom-up)' : 'Numerazione dall’alto (top-down)'}`,
                    9,
                );
                if (rack.height_u > 42)
                    write(
                        `Tavola ${sectionIndex + 1} · U ${unitRange(section.bottom, section.top, rack)}`,
                        9,
                    );
                y += 12;
                const chartTop = y;
                const unit = Math.min(
                    5,
                    (bottom - chartTop - 5) / (section.top - section.bottom),
                );
                const width = (unit * RACK_WIDTH) / RACK_UNIT_HEIGHT;
                const chartHeight = (section.top - section.bottom) * unit;
                const column = (contentWidth - 12) / faces.length;
                for (const [faceIndex, face] of faces.entries()) {
                    const left =
                        margin +
                        12 +
                        faceIndex * column +
                        (column - 12 - width) / 2;
                    pdf.setFont('Document', 'bold')
                        .setFontSize(9)
                        .setTextColor(ink);
                    pdf.text(
                        face === 'front' ? 'FRONTE' : 'RETRO',
                        left + width / 2,
                        chartTop - 5,
                        { align: 'center' },
                    );
                    pdf.setDrawColor(rule)
                        .setFillColor(paper)
                        .setLineWidth(0.4);
                    pdf.roundedRect(
                        left - 2,
                        chartTop - 2,
                        width + 4,
                        chartHeight + 4,
                        1,
                        1,
                        'FD',
                    );
                    pdf.setFillColor('#FFFFFF');
                    pdf.rect(left, chartTop, width, chartHeight, 'F');
                    for (let u = section.bottom; u < section.top; u++) {
                        const rowY = chartTop + (section.top - u - 1) * unit;
                        pdf.setLineWidth(0.15)
                            .setDrawColor(rule)
                            .line(left, rowY, left + width, rowY);
                        pdf.setFont('Document', 'normal')
                            .setFontSize(Math.min(8, unit * 2))
                            .setTextColor(muted);
                        pdf.text(
                            String(unitLabel(u, rack)),
                            left - 4,
                            rowY + unit / 2 + 0.9,
                            { align: 'right' },
                        );
                    }
                    for (const item of rack.items.toSorted(
                        (a, b) =>
                            Number(a.kind === 'shelf') -
                            Number(b.kind === 'shelf'),
                    )) {
                        const bounds = itemBounds(item, rack.items);
                        if (
                            bounds.bottom < section.bottom ||
                            bounds.bottom >= section.top
                        )
                            continue;
                        const itemTop =
                            item.kind === 'shelf'
                                ? bounds.bottom + 1
                                : bounds.top;
                        const itemY = chartTop + (section.top - itemTop) * unit;
                        const itemX =
                            left +
                            ((face === 'rear'
                                ? 100 - item.x - item.width
                                : item.x) /
                                100) *
                                width;
                        const itemWidth = (item.width / 100) * width;
                        const itemHeight =
                            (item.kind === 'shelf' ? 1 : item.height) * unit;
                        if (item.kind === 'shelf') {
                            pdf.setDrawColor(muted).setLineWidth(0.6);
                            pdf.line(itemX, itemY, itemX, itemY + itemHeight);
                            pdf.line(
                                itemX,
                                itemY + itemHeight,
                                itemX + itemWidth,
                                itemY + itemHeight,
                            );
                            pdf.line(
                                itemX + itemWidth,
                                itemY + itemHeight,
                                itemX + itemWidth,
                                itemY,
                            );
                            continue;
                        }
                        const device = item.device_id
                            ? devices.get(item.device_id)
                            : undefined;
                        pdf.setFillColor('#FFFFFF')
                            .setDrawColor(rule)
                            .setLineWidth(0.2)
                            .rect(itemX, itemY, itemWidth, itemHeight, 'FD');
                        const image = device
                            ? await panelImage(
                                  device,
                                  face,
                                  (RACK_WIDTH * item.width) / 100,
                                  RACK_UNIT_HEIGHT * item.height,
                              )
                            : null;
                        if (image)
                            pdf.addImage(
                                image,
                                'PNG',
                                itemX,
                                itemY,
                                itemWidth,
                                itemHeight,
                            );
                        else {
                            const size = Math.min(8, itemHeight * 1.6);
                            pdf.setTextColor(ink);
                            pdf.text(
                                short(
                                    device?.name ?? 'Cassetto',
                                    itemWidth - 3,
                                    size,
                                ),
                                itemX + 1.5,
                                itemY + itemHeight / 2 + size * 0.12,
                            );
                            const side = device?.model?.panel?.faces[face];
                            if (device && side?.image && !side.image.hidden)
                                warnings.add(
                                    `${device.name} · ${face === 'front' ? 'fronte' : 'retro'}`,
                                );
                        }
                    }
                }
                y = chartTop + chartHeight + 8;
            }
            if (!parts.includes('occupancy')) continue;
            room(35);
            heading(`${rack.name} · Occupazione U`);
            table(
                [
                    'U / unità interessate',
                    'Elemento / dispositivo',
                    'Tipologia',
                ],
                rackOccupancy(rack, data.devices),
                [34, 88, 60],
            );
        }
    }

    function topologyMap(section: PlanSection) {
        room(35);
        if (!links.length) {
            heading(section.title);
            write('Nessun collegamento fisico da rappresentare.', 9);
        }
        // Small adjacency plates keep every documented edge legible, without inventing network roles.
        const remaining = new Set(links);
        let plate = 0;
        while (remaining.size) {
            const counts = new Map<number, number>();
            for (const link of remaining)
                for (const id of new Set([link.a, link.b]))
                    counts.set(id, (counts.get(id) ?? 0) + 1);
            const anchor = [...counts].sort(
                (a, b) => b[1] - a[1] || a[0] - b[0],
            )[0][0];
            const neighbors = new Map<number, typeof links>();
            const selfLink = [...remaining].some(
                (link) => link.a === anchor && link.b === anchor,
            );
            for (const link of remaining) {
                if (link.a !== anchor && link.b !== anchor) continue;
                if (selfLink && (link.a !== anchor || link.b !== anchor))
                    continue;
                const peer = link.a === anchor ? link.b : link.a;
                if (!neighbors.has(peer) && neighbors.size === 4) continue;
                neighbors.set(peer, [...(neighbors.get(peer) ?? []), link]);
                remaining.delete(link);
            }
            const nodeLines = (id: number) => {
                const device = devices.get(id);
                pdf.setFont('Document', 'normal').setFontSize(9);
                return pdf.splitTextToSize(
                    `${device?.name ?? '—'}\n${device?.rack?.name ?? 'Fuori rack'}`,
                    50,
                ) as string[];
            };
            const anchorLines = nodeLines(anchor);
            const peers = [...neighbors].map(([id, connections]) => ({
                id,
                connections,
                lines: nodeLines(id),
            }));
            const rowHeight = Math.max(
                36,
                anchorLines.length * 4 + (selfLink ? 28 : 8),
                ...peers.map((peer) =>
                    Math.max(
                        peer.lines.length * 4 + 8,
                        peer.connections.length * 8 + 10,
                    ),
                ),
            );
            room(Math.min(rowHeight * peers.length, bottom - top - 24) + 24);
            heading(`${section.title} · Tavola ${++plate}`);
            y += 6;
            const drawNode = (lines: string[], x: number, centerY: number) => {
                const height = lines.length * 4 + 8;
                pdf.setFillColor(paper)
                    .setDrawColor(rule)
                    .setLineWidth(0.3)
                    .roundedRect(
                        x,
                        centerY - height / 2,
                        56,
                        height,
                        2,
                        2,
                        'FD',
                    );
                pdf.setFont('Document', 'normal')
                    .setFontSize(9)
                    .setTextColor(ink);
                pdf.text(lines, x + 3, centerY - height / 2 + 7, {
                    lineHeightFactor: 1.26,
                });
            };
            // Very long names need their own plate, rather than shrinking or clipping nodes.
            let row = 0;
            while (row < peers.length) {
                const capacity = Math.max(
                    1,
                    Math.floor((bottom - y) / rowHeight),
                );
                const batch = peers.slice(row, row + capacity);
                const centerY = y + (batch.length * rowHeight) / 2;
                drawNode(anchorLines, margin, centerY);
                for (const [index, peer] of batch.entries()) {
                    const peerY = y + rowHeight * (index + 0.5);
                    pdf.setDrawColor(muted).setLineWidth(0.35);
                    if (peer.id === anchor) {
                        const returnY = centerY + anchorLines.length * 2 + 9;
                        pdf.line(margin + 56, centerY, margin + 126, centerY);
                        pdf.line(margin + 126, centerY, margin + 126, returnY);
                        pdf.line(margin + 126, returnY, margin + 28, returnY);
                        pdf.line(
                            margin + 28,
                            returnY,
                            margin + 28,
                            centerY + anchorLines.length * 2 + 4,
                        );
                    } else {
                        pdf.line(margin + 56, centerY, margin + 65, peerY);
                        pdf.line(margin + 65, peerY, margin + 126, peerY);
                        drawNode(peer.lines, margin + 126, peerY);
                    }
                    pdf.setFont('Document', 'normal')
                        .setFontSize(8)
                        .setTextColor(ink);
                    const labels = peer.connections.map(
                        (link) =>
                            `${link.reference} · ${cableTypes[link.type]} × ${link.cables.length}`,
                    );
                    const labelY = peerY - labels.length * 4 - 2;
                    pdf.setFillColor('#FFFFFF').rect(
                        margin + 66,
                        labelY - 3,
                        59,
                        labels.length * 4 + 3,
                        'F',
                    );
                    pdf.text(labels, margin + 67, labelY, {
                        lineHeightFactor: 1.4,
                    });
                }
                row += batch.length;
                y += batch.length * rowHeight + 6;
                if (row < peers.length) {
                    page();
                    heading(`${section.title} · Tavola ${++plate}`);
                    y += 6;
                }
            }
        }
    }

    cover();
    for (const section of sections) {
        anchor = section.id;
        if (section.id === 'inventory') inventory(section);
        else if (section.id === 'racks') await racks(section);
        else if (section.id === 'topology') topologyMap(section);
        else if (isCableSection(section.id)) cables(section, section.id);
    }
    if (warnings.size) {
        page();
        heading('Immagini non disponibili');
        write(
            'Per questi pannelli è stata usata la rappresentazione con il nome del dispositivo:',
            9,
        );
        y += 4;
        for (const warning of warnings) write(warning, 9);
    }
    for (let number = 1; number <= pdf.getNumberOfPages(); number++) {
        pdf.setPage(number);
        pdf.setTextColor(muted).setFont('Document', 'normal').setFontSize(8);
        pdf.setDrawColor(rule).setLineWidth(0.2);
        if (options.pageHeader) {
            pdf.text(headerLines, margin, 11, { lineHeightFactor: 1.24 });
            pdf.setFont('Document', 'bold').text(
                'NetVibe',
                pageWidth - margin,
                11,
                { align: 'right' },
            );
            if (logoData)
                pdf.addImage(
                    logoData,
                    'PNG',
                    pageWidth - margin - pdf.getTextWidth('NetVibe') - 6,
                    7.6,
                    4.8,
                    4.8,
                );
            pdf.line(margin, top - 4, pageWidth - margin, top - 4);
        }
        pdf.line(margin, pageHeight - 13, pageWidth - margin, pageHeight - 13);
        pdf.setFont('Document', 'normal').setFontSize(7.5);
        pdf.text(title, margin, pageHeight - 8);
        if (options.pageNumbers)
            pdf.text(
                `${number} / ${pdf.getNumberOfPages()}`,
                pageWidth - margin,
                pageHeight - 8,
                { align: 'right' },
            );
    }
    const filename = [
        title,
        company.name,
        site.name,
        scope?.name ?? 'rack-sede',
        data.generatedAt.slice(0, 10),
    ]
        .filter(Boolean)
        .join('-')
        .replace(/[<>:"/\\|?*\x00-\x1F]/g, '-');
    return {
        blob: pdf.output('blob'),
        filename: `${filename}.pdf`,
        pages: pdf.getNumberOfPages(),
        anchors,
        warnings: [...warnings],
    };
}
