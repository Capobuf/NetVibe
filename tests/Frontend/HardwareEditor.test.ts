import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    arrangePorts,
    blankDocument,
    createBlock,
    previewBlock,
    resizeBlockSpacing,
    reshapeBlock,
    boundsOf,
    faceLayers,
    reorderLayers,
    cropImage,
    imageBounds,
    type PanelImage,
    duplicatePorts,
    pastePorts,
    labelConflict,
    makePort,
    numberingLabels,
    portBounds,
    outsideShell,
    snapDelta,
} from '../../resources/js/lib/hardware-editor/document.ts';

test('automatic paste preserves grouped symbols and avoids occupied bounds on repeated pastes', () => {
    const doc = blankDocument();
    const source = createBlock('rj45', 4, 2, 16, 30, 90, { x: 50, y: 40 }).map(
        (port) => ({
            ...port,
            group: 'source-bank',
            scale: 2,
            opacity: 0.6,
            borderColor: '#ffffff',
        }),
    );
    const original = structuredClone(source);
    doc.faces.front.ports = [
        ...source,
        {
            ...makePort('rj45', { x: 150, y: 140 }),
            scale: 4,
            hidden: true,
            locked: true,
        },
    ];

    for (let i = 0; i < 3; i++) {
        const pasted = pastePorts(doc, 'front', source);
        const box = boundsOf(pasted.map(portBounds));
        for (const occupied of doc.faces.front.ports.map(portBounds)) {
            assert.ok(
                box.x >= occupied.x + occupied.width + 12 ||
                    box.x + box.width + 12 <= occupied.x ||
                    box.y >= occupied.y + occupied.height + 12 ||
                    box.y + box.height + 12 <= occupied.y,
            );
        }
        assert.ok(pasted[0].x > source[0].x);
        assert.ok(pasted[0].y > source[0].y);
        assert.ok(pasted[0].group);
        assert.notEqual(pasted[0].group, source[0].group);
        const dx = pasted[0].x - source[0].x;
        const dy = pasted[0].y - source[0].y;
        pasted.forEach((port, index) => {
            assert.deepEqual(port, {
                ...source[index],
                id: port.id,
                group: pasted[0].group,
                x: source[index].x + dx,
                y: source[index].y + dy,
            });
        });
        doc.faces.front.ports.push(...pasted);
        assert.equal(
            new Set(doc.faces.front.ports.map((port) => port.id)).size,
            doc.faces.front.ports.length,
        );
    }
    assert.deepEqual(source, original);
});

test('paste centers on the requested point and preserves only labels still available after a cut', () => {
    const doc = blankDocument();
    const source = createBlock('rj45', 2, 2, 16, 30, 0, { x: 50, y: 40 }).map(
        (port, index) => ({ ...port, label: `ether${index + 1}` }),
    );
    doc.faces.front.ports = source;
    const copied = pastePorts(doc, 'rear', source, { x: 400, y: 200 });
    const box = boundsOf(copied.map(portBounds));
    assert.equal(box.x + box.width / 2, 400);
    assert.equal(box.y + box.height / 2, 200);
    assert.deepEqual(
        copied.map((port) => port.label),
        ['', ''],
    );
    assert.equal(copied[1].x - copied[0].x, source[1].x - source[0].x);

    doc.faces.front.ports = [];
    const cut = pastePorts(doc, 'rear', source);
    assert.deepEqual(
        cut.map((port) => port.label),
        ['ether1', 'ether2'],
    );
    doc.faces.rear.ports = cut;
    assert.equal(labelConflict(doc), null);
    assert.deepEqual(
        pastePorts(doc, 'rear', source).map((port) => port.label),
        ['', ''],
    );
});

test('numbering follows geometry and direction without changing identities and detects conflicts on the other face', () => {
    const doc = blankDocument();
    const ports = createBlock('rj45', 6, 3, 16, 30, 0, { x: 50, y: 40 });
    const options = {
        start: 1,
        prefix: 'ether',
        order: 'snake' as const,
        reverseX: false,
        reverseY: false,
    };
    const labels = numberingLabels([...ports].reverse(), options);
    assert.deepEqual(
        ports.map((p) => labels.get(p.id)),
        ['ether1', 'ether2', 'ether3', 'ether6', 'ether5', 'ether4'],
    );
    const columns = numberingLabels(ports, {
        ...options,
        order: 'columns',
        reverseY: true,
    });
    assert.deepEqual(
        ports.map((p) => columns.get(p.id)),
        ['ether2', 'ether4', 'ether6', 'ether1', 'ether3', 'ether5'],
    );
    assert.ok(ports.every((p) => p.label === ''));
    doc.faces.front.ports = ports.map((p) => ({
        ...p,
        label: labels.get(p.id)!,
        group: 'bank',
    }));
    doc.faces.rear.ports = [
        { ...makePort('rj45', { x: 20, y: 20 }), label: 'ether1' },
    ];
    assert.equal(labelConflict(doc), 'ether1');
    doc.faces.rear.ports[0].label = '';
    const copies = duplicatePorts(doc.faces.front.ports);
    doc.faces.front.ports.push(...copies);
    assert.equal(labelConflict(doc), null);
    assert.equal(new Set(doc.faces.front.ports.map((p) => p.id)).size, 12);
    assert.ok(
        copies.every(
            (p) =>
                p.group === copies[0].group &&
                p.group !== 'bank' &&
                p.label === '',
        ),
    );
});

test('spacing a grid preserves symbols and snapping uses the same screen threshold at different zoom levels', () => {
    const ports = createBlock('rj45', 4, 2, 16, 30, 90, { x: 50, y: 40 });
    const spaced = arrangePorts(ports, 'gap-x', 20);
    assert.deepEqual(
        spaced.map((p) => [p.x, p.y]),
        [
            [50, 40],
            [98, 40],
            [50, 104],
            [98, 104],
        ],
    );
    assert.deepEqual(
        spaced.map((p) => [p.id, p.scale, p.rotation]),
        ports.map((p) => [p.id, p.scale, p.rotation]),
    );
    assert.deepEqual(portBounds(spaced[0]), {
        x: 36,
        y: 23,
        width: 28,
        height: 34,
    });
    const stationary = [{ ...ports[0], id: 'reference', x: 300, y: 200 }];
    const delta = { x: 248, y: 160 };
    const near = snapDelta(
        [ports[0]],
        stationary,
        blankDocument().shell,
        delta,
        2,
    );
    const far = snapDelta(
        [ports[0]],
        stationary,
        blankDocument().shell,
        delta,
        4,
    );
    assert.equal(near.delta.x, 250);
    assert.equal(far.delta.x, 248);
    assert.equal(ports[1].x, 94);
});

test('layer ordering moves a group as one unit and keeps member order when ungrouped', () => {
    const face = blankDocument().faces.front;
    face.ports = ['a', 'b', 'c', 'd'].map((id) => ({
        ...makePort('rj45', { x: 50, y: 40 }),
        id,
        group: id === 'b' || id === 'd' ? 'pair' : null,
    }));
    face.order = ['a', 'b', 'c', 'd'];

    face.order = reorderLayers(faceLayers(face), ['b', 'd'], 'backward');

    assert.deepEqual(face.order, ['a', 'b', 'd', 'c']);
    assert.deepEqual(reorderLayers(faceLayers(face), ['b'], 'forward'), [
        'a',
        'd',
        'b',
        'c',
    ]);
    face.ports = face.ports.map((p) => ({ ...p, group: null }));
    assert.deepEqual(
        faceLayers(face).map((layer) => layer.id),
        ['a', 'b', 'd', 'c'],
    );
    assert.deepEqual(
        reorderLayers(faceLayers(face), ['c'], 'forward'),
        face.order,
    );
});

test('crop keeps source pixels and maps the visible frame correctly after moving and scaling the image', () => {
    const image: PanelImage = {
        assetId: 'photo',
        x: 20,
        y: 30,
        width: 2000,
        height: 1000,
        scale: 0.25,
        opacity: 1,
        hidden: false,
        locked: false,
        clip: false,
        crop: { x: 0, y: 0, width: 2000, height: 1000 },
    };
    const frame = { x: 70, y: 55, width: 300, height: 150 };

    const cropped = cropImage(image, frame);

    assert.deepEqual(cropped.crop, {
        x: 200,
        y: 100,
        width: 1200,
        height: 600,
    });
    assert.deepEqual(imageBounds(cropped), frame);
    assert.deepEqual(imageBounds({ ...cropped, x: 40, y: 50, scale: 0.5 }), {
        x: 140,
        y: 100,
        width: 600,
        height: 300,
    });
    assert.deepEqual(image.crop, { x: 0, y: 0, width: 2000, height: 1000 });
    assert.equal(cropped.assetId, image.assetId);
    assert.deepEqual(
        cropImage(image, { x: -30, y: 0, width: 1000, height: 1000 }).crop,
        image.crop,
    );
});

test('shell bounds and snapping follow its origin after resizing from the top or left', () => {
    const shell = { ...blankDocument().shell, x: -100, y: -50 };
    const port = makePort('rj45', { x: -81, y: -34 });

    assert.equal(outsideShell(port, shell), false);
    assert.equal(outsideShell({ ...port, x: -84 }, shell), true);
    assert.equal(outsideShell({ ...port, y: -37 }, shell), true);
    assert.equal(outsideShell({ ...port, x: 784 }, shell), true);
    assert.equal(outsideShell({ ...port, y: 117 }, shell), true);
    const snapped = snapDelta([port], [], shell, { x: 0, y: 0 }, 1);
    assert.deepEqual(snapped.delta, { x: -2, y: -2 });
    assert.deepEqual(snapped.guides, [
        { axis: 'x', value: -100 },
        { axis: 'y', value: -50 },
    ]);
});

test('alignment and spacing move groups as units and only change their members when entered', () => {
    const ports = [
        { ...makePort('rj45', { x: 50, y: 50 }), group: 'a' },
        { ...makePort('rj45', { x: 100, y: 50 }), group: 'a' },
        makePort('rj45', { x: 200, y: 50 }),
        { ...makePort('rj45', { x: 400, y: 50 }), group: 'b' },
        { ...makePort('rj45', { x: 470, y: 50 }), group: 'b' },
    ];

    const aligned = arrangePorts(ports, 'left');
    const distributed = arrangePorts(ports, 'distribute-x');
    const spaced = arrangePorts(ports, 'gap-x', 20);

    assert.deepEqual(
        aligned.map((p) => p.x),
        [50, 100, 50, 50, 120],
    );
    assert.deepEqual(
        distributed.map((p) => p.x),
        [50, 100, 250, 400, 470],
    );
    assert.deepEqual(
        spaced.map((p) => p.x),
        [50, 100, 154, 208, 278],
    );
    assert.deepEqual(
        arrangePorts(ports.slice(0, 2), 'left'),
        ports.slice(0, 2),
    );
    assert.deepEqual(
        arrangePorts(ports.slice(0, 2), 'left', 12, 'a').map((p) => p.x),
        [50, 50],
    );
    assert.deepEqual(
        ports.map((p) => p.x),
        [50, 100, 200, 400, 470],
    );
});

test('block preview stays centered with scaled rotated ports and preserves requested gaps', () => {
    const options = {
        type: 'rj45' as const,
        count: 5,
        columns: 3,
        gapX: 10,
        gapY: 20,
        rotation: 90,
        columnFirst: false,
        scale: 2,
        x: 500,
        y: 300,
    };

    const ports = previewBlock(options);
    const box = boundsOf(ports.map(portBounds));
    const moved = previewBlock({ ...options, x: 540, y: 280 });

    assert.deepEqual(box, { x: 406, y: 222, width: 188, height: 156 });
    assert.equal(ports.length, 5);
    assert.equal(ports[1].x - ports[0].x - portBounds(ports[0]).width, 10);
    assert.equal(ports[3].y - ports[0].y - portBounds(ports[0]).height, 20);
    assert.ok(ports[0].group);
    assert.ok(
        ports.every(
            (p) =>
                p.group === ports[0].group &&
                p.scale === 2 &&
                p.rotation === 90,
        ),
    );
    assert.deepEqual(
        moved.map((p) => [p.x, p.y]),
        ports.map((p) => [p.x + 40, p.y - 20]),
    );
});

test('block edge handles change spacing with fixed opposite edges and unchanged connector sizes', () => {
    const options = {
        type: 'rj45' as const,
        count: 5,
        columns: 4,
        gapX: 10,
        gapY: 20,
        rotation: 90,
        columnFirst: true,
        scale: 2,
        x: 500,
        y: 300,
    };
    const original = boundsOf(previewBlock(options).map(portBounds));
    const left = resizeBlockSpacing(options, 'left', -40);
    const right = resizeBlockSpacing(options, 'right', 40);
    const top = resizeBlockSpacing(options, 'top', -30);
    const bottom = resizeBlockSpacing(options, 'bottom', 30);

    assert.equal(left.gapX, 30);
    assert.equal(right.gapX, 30);
    assert.equal(top.gapY, 50);
    assert.equal(bottom.gapY, 50);
    for (const changed of [left, right, top, bottom]) {
        const ports = previewBlock(changed);
        const box = boundsOf(ports.map(portBounds));
        assert.ok(
            ports.every(
                (port) =>
                    portBounds(port).width === 56 &&
                    portBounds(port).height === 68,
            ),
        );
        if (changed === left || changed === right) {
            assert.equal(box.width, original.width + 40);
            assert.equal(box.height, original.height);
            assert.equal(
                box.x,
                changed === left ? original.x - 40 : original.x,
            );
        } else {
            assert.equal(box.height, original.height + 30);
            assert.equal(box.width, original.width);
            assert.equal(box.y, changed === top ? original.y - 30 : original.y);
        }
    }
    assert.equal(resizeBlockSpacing(options, 'right', -10000).gapX, 0);
    assert.equal(resizeBlockSpacing(options, 'bottom', 10000).gapY, 300);
    const column = { ...options, columns: 1 };
    const row = { ...options, columns: options.count };
    assert.deepEqual(resizeBlockSpacing(column, 'left', 50), column);
    assert.deepEqual(resizeBlockSpacing(row, 'top', 50), row);
});

test('block corners reflow rows and columns while preserving the opposite corner and port size', () => {
    const options = {
        type: 'rj45' as const,
        count: 8,
        columns: 4,
        gapX: 10,
        gapY: 20,
        rotation: 90,
        columnFirst: false,
        scale: 2,
        x: 500,
        y: 300,
    };
    const original = boundsOf(previewBlock(options).map(portBounds));
    for (const corner of [
        'top-left',
        'top-right',
        'bottom-left',
        'bottom-right',
    ] as const) {
        const left = corner.endsWith('left');
        const top = corner.startsWith('top');
        const changed = reshapeBlock(options, corner, {
            x: left ? 132 : -132,
            y: top ? -176 : 176,
        });
        const ports = previewBlock(changed);
        const box = boundsOf(ports.map(portBounds));
        assert.equal(changed.columns, 2);
        assert.equal(ports.length, 8);
        assert.equal(new Set(ports.map((port) => port.x)).size, 2);
        assert.equal(new Set(ports.map((port) => port.y)).size, 4);
        assert.equal(box.width, 122);
        assert.equal(box.height, 332);
        assert.equal(
            box.x + (left ? box.width : 0),
            original.x + (left ? original.width : 0),
        );
        assert.equal(
            box.y + (top ? box.height : 0),
            original.y + (top ? original.height : 0),
        );
        assert.ok(
            ports.every(
                (port) =>
                    port.scale === 2 &&
                    port.rotation === 90 &&
                    portBounds(port).width === 56 &&
                    portBounds(port).height === 68,
            ),
        );
        assert.deepEqual(
            reshapeBlock(changed, corner, { x: 0, y: 0 }),
            changed,
        );
    }
    const wide = reshapeBlock(options, 'bottom-right', { x: 264, y: -88 });
    assert.equal(wide.columns, 8);
    assert.equal(new Set(previewBlock(wide).map((port) => port.y)).size, 1);
    for (const columnFirst of [true, false]) {
        const partial = { ...options, count: 5, columns: 3, columnFirst };
        const tall = reshapeBlock(partial, 'bottom-right', { x: -132, y: 264 });
        assert.equal(tall.columns, 1);
        assert.equal(previewBlock(tall).length, 5);
        const clamped = reshapeBlock(partial, 'top-left', {
            x: 10000,
            y: 10000,
        });
        assert.ok(clamped.gapX >= 0 && clamped.gapY >= 0);
        const expanded = reshapeBlock(partial, 'bottom-right', {
            x: 10000,
            y: 10000,
        });
        assert.ok(expanded.gapX <= 300 && expanded.gapY <= 300);
    }
    const single = { ...options, count: 1, columns: 1 };
    assert.deepEqual(
        reshapeBlock(single, 'bottom-right', { x: 100, y: 100 }),
        single,
    );
});
