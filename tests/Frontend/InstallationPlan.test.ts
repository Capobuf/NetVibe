import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    cablesOfType,
    defaultPlanOptions,
    planPage,
    planProblem,
    visibleParts,
    visibleSections,
} from '../../resources/js/lib/installation-plan.ts';
import type { DocumentCable } from '../../resources/js/types/rack-document.ts';

const cable = (id: number, type: DocumentCable['type']): DocumentCable => ({
    id,
    type,
    name: null,
    color: '#000000',
    purpose: null,
});

test('installation plan keeps chosen section order, visibility and columns, and reopens the preview where a hidden part was', () => {
    const cables = [cable(1, 'power'), cable(2, 'copper'), cable(3, 'power')];
    const options = defaultPlanOptions({ cables });
    assert.deepEqual(
        visibleSections(options).map((section) => section.id),
        ['inventory', 'racks', 'copper', 'power', 'topology'],
    );
    assert.deepEqual(
        cablesOfType(cables, 'power').map((item) => item.id),
        [1, 3],
    );

    const [inventory, racks, ...rest] = options.sections;
    const power = rest.find((section) => section.id === 'power')!;
    const reordered = {
        ...options,
        sections: [
            { ...power, parts: power.parts.toReversed() },
            racks,
            { ...inventory, visible: false },
            ...rest.filter((section) => section !== power),
        ],
    };
    assert.deepEqual(
        visibleSections(reordered).map((section) => section.id),
        ['power', 'racks', 'copper', 'topology'],
    );
    assert.deepEqual(visibleParts(reordered.sections[0]), [
        'purpose',
        'color',
        'b',
        'a',
        'name',
        'reference',
    ]);

    const anchors = { cover: 1, power: 2, racks: 3, copper: 5, topology: 6 };
    assert.equal(planPage(reordered, anchors, 'racks'), 3);
    // Hidden inventory sat between racks and copper: show where copper starts now.
    assert.equal(planPage(reordered, anchors, 'inventory'), 5);
    assert.equal(planPage(reordered, { cover: 1, power: 2 }, 'topology'), 2);
    assert.equal(planPage(reordered, anchors, 'cover'), 1);

    assert.equal(planProblem(reordered), null);
    assert.match(planProblem({ ...reordered, title: ' ' })!, /titolo/);
});
