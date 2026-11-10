import { Head } from '@inertiajs/react';
import {
    File,
    LayoutList,
    PanelBottom,
    PanelTop,
    RectangleHorizontal,
    RectangleVertical,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import {
    OptionGroup,
    ResetButton,
    SortableOptions,
    VisibilityField,
} from '@/components/print/print-options';
import { PrintWorkspace } from '@/components/print/print-workspace';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    defaultPlanOptions,
    defaultSection,
    PLAN_TITLE,
    partLabels,
    planProblem,
    sectionLabels,
    type PlanOptions,
    type PlanPart,
    type PlanSection,
} from '@/lib/installation-plan';
import { index, show } from '@/routes/companies/sites/racks';
import type { RackDocumentProps } from '@/types/rack-document';

export default function Documentation(props: RackDocumentProps) {
    return <InstallationPlan key={props.document.generatedAt} {...props} />;
}

function InstallationPlan(props: RackDocumentProps) {
    const { company, site, rack, document } = props;
    const [defaults] = useState(() => defaultPlanOptions(document));
    const [options, setOptions] = useState<PlanOptions>(defaults);
    const route = { company: company.id, site: site.id };
    const generate = useCallback(
        () =>
            import('@/lib/rack-document-pdf').then(({ rackDocumentPdf }) =>
                rackDocumentPdf({ company, site, rack, document }, options),
            ),
        [company, site, rack, document, options],
    );
    const change = (value: Partial<PlanOptions>) =>
        setOptions((current) => ({ ...current, ...value }));
    const changeSection = (id: string, value: Partial<PlanSection>) =>
        setOptions((current) => ({
            ...current,
            sections: current.sections.map((section) =>
                section.id === id ? { ...section, ...value } : section,
            ),
        }));
    const visibleCount = options.sections.filter((s) => s.visible).length;

    function partVisibility(
        section: PlanSection,
        id: string,
        visible: boolean,
    ) {
        changeSection(section.id, {
            parts: section.parts.map((part) =>
                part.id === id ? { ...part, visible } : part,
            ),
        });
    }

    function sectionOptions(section: PlanSection) {
        const shown = section.parts.filter((part) => part.visible).length;
        const canHide = (part: PlanPart) => !part.visible || shown > 1;
        return (
            <div className="flex flex-col gap-3">
                <Field>
                    <FieldLabel htmlFor={`plan-title-${section.id}`}>
                        Titolo
                    </FieldLabel>
                    <Input
                        id={`plan-title-${section.id}`}
                        value={section.title}
                        maxLength={120}
                        aria-invalid={!section.title.trim()}
                        onChange={(event) =>
                            changeSection(section.id, {
                                title: event.target.value,
                            })
                        }
                    />
                </Field>
                {section.id === 'racks' &&
                    section.parts.map((part) => (
                        <VisibilityField
                            key={part.id}
                            label={partLabels[part.id]}
                            visible={part.visible}
                            disabled={!canHide(part)}
                            onChange={(visible) =>
                                partVisibility(section, part.id, visible)
                            }
                        />
                    ))}
                {section.id !== 'racks' && section.parts.length > 0 && (
                    <Field>
                        <FieldLabel asChild>
                            <span>Colonne</span>
                        </FieldLabel>
                        <SortableOptions
                            label={`Colonne di ${section.title}`}
                            options={section.parts.map((part) => ({
                                id: part.id,
                                label: partLabels[part.id],
                                visible: part.visible,
                                canHide: canHide(part),
                            }))}
                            onVisibilityChange={(id, visible) =>
                                partVisibility(section, id, visible)
                            }
                            onOrderChange={(ids) =>
                                changeSection(section.id, {
                                    parts: ids.map((id) =>
                                        section.parts.find(
                                            (part) => part.id === id,
                                        )!,
                                    ),
                                })
                            }
                        />
                    </Field>
                )}
            </div>
        );
    }

    return (
        <>
            <Head title={`${PLAN_TITLE} · ${company.name}`} />
            <PrintWorkspace
                title={PLAN_TITLE}
                context={[company.name, site.name, rack?.name ?? 'Intera sede']
                    .filter(Boolean)
                    .join(' · ')}
                back={
                    rack
                        ? {
                              href: show({ ...route, rack: rack.id }).url,
                              label: 'Torna al rack',
                          }
                        : { href: index(route).url, label: 'Torna ai rack' }
                }
                generate={generate}
                problem={planProblem(options)}
                notice={(preview) =>
                    preview.warnings.length > 0 && (
                        <Alert>
                            <AlertTitle>
                                Alcune immagini non sono disponibili
                            </AlertTitle>
                            <AlertDescription>
                                Nel PDF sono sostituite dal nome del
                                dispositivo: {preview.warnings.join(', ')}.
                            </AlertDescription>
                        </Alert>
                    )
                }
            >
                <OptionGroup
                    icon={File}
                    label="Formato A4"
                    defaultOpen
                    onReset={() =>
                        change({ orientation: defaults.orientation })
                    }
                >
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={options.orientation}
                        aria-label="Orientamento"
                        onValueChange={(value) => {
                            if (value === 'portrait' || value === 'landscape')
                                change({ orientation: value });
                        }}
                    >
                        <ToggleGroupItem value="portrait">
                            <RectangleVertical data-icon="inline-start" />
                            Verticale
                        </ToggleGroupItem>
                        <ToggleGroupItem value="landscape">
                            <RectangleHorizontal data-icon="inline-start" />
                            Orizzontale
                        </ToggleGroupItem>
                    </ToggleGroup>
                </OptionGroup>
                <OptionGroup
                    icon={PanelTop}
                    label="Copertina"
                    onReset={() =>
                        change({
                            title: defaults.title,
                            cover: defaults.cover,
                            logo: defaults.logo,
                            summary: defaults.summary,
                            notes: defaults.notes,
                        })
                    }
                >
                    <Field>
                        <FieldLabel htmlFor="plan-title">Titolo</FieldLabel>
                        <Input
                            id="plan-title"
                            value={options.title}
                            maxLength={120}
                            aria-invalid={!options.title.trim()}
                            onChange={(event) =>
                                change({ title: event.target.value })
                            }
                        />
                    </Field>
                    <VisibilityField
                        label="Pagina di copertina"
                        visible={options.cover}
                        onChange={(cover) => change({ cover })}
                    />
                    <VisibilityField
                        label="Logo NetVibe"
                        visible={options.logo}
                        onChange={(logo) => change({ logo })}
                    />
                    <VisibilityField
                        label="Riepilogo rack, dispositivi e cavi"
                        visible={options.summary}
                        onChange={(summary) => change({ summary })}
                    />
                    <Field>
                        <FieldLabel htmlFor="plan-notes">Note</FieldLabel>
                        <Textarea
                            id="plan-notes"
                            value={options.notes}
                            maxLength={2000}
                            rows={4}
                            onChange={(event) =>
                                change({ notes: event.target.value })
                            }
                        />
                    </Field>
                </OptionGroup>
                <OptionGroup
                    icon={LayoutList}
                    label="Contenuto"
                    defaultOpen
                    onReset={() => change({ sections: defaults.sections })}
                >
                    <SortableOptions
                        label="Sezioni del documento"
                        options={options.sections.map((section) => ({
                            id: section.id,
                            label: sectionLabels[section.id],
                            visible: section.visible,
                            canHide: !section.visible || visibleCount > 1,
                            actions: (
                                <ResetButton
                                    label={sectionLabels[section.id]}
                                    onClick={() =>
                                        changeSection(section.id, {
                                            ...defaultSection(
                                                section.id,
                                                document,
                                            ),
                                            visible: true,
                                        })
                                    }
                                />
                            ),
                            content:
                                section.id === 'topology'
                                    ? undefined
                                    : sectionOptions(section),
                        }))}
                        onVisibilityChange={(id, visible) =>
                            changeSection(id, { visible })
                        }
                        onOrderChange={(ids) =>
                            setOptions((current) => ({
                                ...current,
                                sections: ids.map((id) =>
                                    current.sections.find(
                                        (section) => section.id === id,
                                    )!,
                                ),
                            }))
                        }
                    />
                </OptionGroup>
                <OptionGroup
                    icon={PanelBottom}
                    label="Intestazione e piè di pagina"
                    onReset={() =>
                        change({
                            pageHeader: defaults.pageHeader,
                            pageNumbers: defaults.pageNumbers,
                        })
                    }
                >
                    <VisibilityField
                        label="Intestazione con azienda e sede"
                        visible={options.pageHeader}
                        onChange={(pageHeader) => change({ pageHeader })}
                    />
                    <VisibilityField
                        label="Numeri di pagina"
                        visible={options.pageNumbers}
                        onChange={(pageNumbers) => change({ pageNumbers })}
                    />
                </OptionGroup>
            </PrintWorkspace>
        </>
    );
}

Documentation.layout = ({ company, site, rack }: RackDocumentProps) => ({
    breadcrumbs: [
        { title: 'Rack', href: index({ company: company.id, site: site.id }) },
        ...(rack
            ? [
                  {
                      title: rack.name,
                      href: show({
                          company: company.id,
                          site: site.id,
                          rack: rack.id,
                      }),
                  },
              ]
            : []),
        { title: PLAN_TITLE, href: '#' },
    ],
});
