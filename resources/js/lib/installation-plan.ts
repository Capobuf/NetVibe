import type { DocumentCable, RackDocument } from '@/types/rack-document';

export type PlanCableType = DocumentCable['type'];
export type PlanSectionId = 'inventory' | 'racks' | PlanCableType | 'topology';
export type PlanPart = { id: string; visible: boolean };
export type PlanSection = {
    id: PlanSectionId;
    title: string;
    visible: boolean;
    parts: PlanPart[];
};
export type PlanOptions = {
    orientation: 'portrait' | 'landscape';
    title: string;
    cover: boolean;
    logo: boolean;
    summary: boolean;
    notes: string;
    pageHeader: boolean;
    pageNumbers: boolean;
    sections: PlanSection[];
};

export const PLAN_TITLE = 'Piano di Installazione';

export const sectionLabels: Record<PlanSectionId, string> = {
    inventory: 'Inventario dispositivi',
    racks: 'Viste rack',
    copper: 'Collegamenti rame / rete',
    fiber: 'Collegamenti in fibra',
    power: 'Collegamenti di alimentazione',
    usb: 'Collegamenti USB',
    topology: 'Topologia dei collegamenti',
};

export const partLabels: Record<string, string> = {
    name: 'Nome',
    manufacturer: 'Produttore',
    model: 'Modello',
    serial: 'Numero di serie',
    assetTag: 'Asset tag',
    rack: 'Rack',
    units: 'U',
    front: 'Fronte',
    rear: 'Retro',
    occupancy: 'Occupazione U',
    reference: 'Cavo',
    a: 'Estremità A',
    b: 'Estremità B',
    color: 'Colore',
    purpose: 'Scopo',
};

const cableTypes: PlanCableType[] = ['copper', 'fiber', 'power', 'usb'];
const sectionParts: Record<PlanSectionId, string[]> = {
    inventory: [
        'name',
        'manufacturer',
        'model',
        'serial',
        'assetTag',
        'rack',
        'units',
    ],
    racks: ['front', 'rear', 'occupancy'],
    ...(Object.fromEntries(
        cableTypes.map((type) => [
            type,
            ['reference', 'name', 'a', 'b', 'color', 'purpose'],
        ]),
    ) as Record<PlanCableType, string[]>),
    topology: [],
};

export function isCableSection(id: PlanSectionId): id is PlanCableType {
    return (cableTypes as string[]).includes(id);
}

export function defaultSection(
    id: PlanSectionId,
    document: Pick<RackDocument, 'cables'>,
): PlanSection {
    return {
        id,
        title: sectionLabels[id],
        // Cable plates for types the scope does not use would only print empty tables.
        visible:
            !isCableSection(id) ||
            document.cables.some((cable) => cable.type === id),
        parts: sectionParts[id].map((part) => ({ id: part, visible: true })),
    };
}

export function defaultPlanOptions(
    document: Pick<RackDocument, 'cables'>,
): PlanOptions {
    return {
        orientation: 'portrait',
        title: PLAN_TITLE,
        cover: true,
        logo: true,
        summary: true,
        notes: '',
        pageHeader: true,
        pageNumbers: true,
        sections: (Object.keys(sectionParts) as PlanSectionId[]).map((id) =>
            defaultSection(id, document),
        ),
    };
}

export const visibleSections = (options: PlanOptions) =>
    options.sections.filter((section) => section.visible);

export const visibleParts = (section: PlanSection) =>
    section.parts.filter((part) => part.visible).map((part) => part.id);

export const cablesOfType = (cables: DocumentCable[], type: PlanCableType) =>
    cables.filter((cable) => cable.type === type);

export function planProblem(options: PlanOptions) {
    if (!options.title.trim()) return 'Inserisci il titolo del documento.';
    const untitled = visibleSections(options).find(
        (section) => !section.title.trim(),
    );
    if (untitled)
        return `Inserisci il titolo della sezione «${sectionLabels[untitled.id]}».`;
    return null;
}
