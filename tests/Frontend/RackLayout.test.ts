import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    clampDeviceDrag,
    dropDevice,
    itemBounds,
    layoutError,
    moveItem,
    supportChain,
} from '../../resources/js/lib/rack-layout.ts';
import type { RackItem } from '../../resources/js/types/rack.ts';

test('free dragging stays inside the posts and transfers a stack only onto a valid support', () => {
    const shelf: RackItem = {
        key: 's',
        kind: 'shelf',
        device_id: null,
        shelf_key: null,
        position: 0,
        height: 1,
        width: 100,
        x: 0,
    };
    const upper = { ...shelf, key: 'upper', position: 10 };
    const base: RackItem = {
        ...shelf,
        key: 'base',
        kind: 'device',
        device_id: 1,
        position: 5,
        height: 2,
    };
    const nas: RackItem = {
        ...base,
        key: 'nas',
        device_id: 2,
        shelf_key: 's',
        position: 0,
        width: 40,
        x: 20,
    };
    const top: RackItem = {
        ...nas,
        key: 'top',
        device_id: 3,
        shelf_key: 'nas',
        height: 1,
        width: 80,
        x: 10,
    };
    const items = [shelf, upper, base, nas, top];
    assert.deepEqual(clampDeviceDrag(nas, items, -100, -10, 16), {
        x: 10,
        bottom: 0,
    });
    assert.deepEqual(clampDeviceDrag(nas, items, 200, 30, 16), {
        x: 30,
        bottom: 13,
    });
    const onShelf = dropDevice(nas, items, 30, 10.2, 16, []);
    assert.ok(onShelf);
    const moved = onShelf.find((item) => item.key === 'nas')!;
    const movedTop = onShelf.find((item) => item.key === 'top')!;
    assert.equal(moved.shelf_key, 'upper');
    assert.equal(itemBounds(moved, onShelf).bottom, 10);
    assert.equal(movedTop.position, 10);
    assert.equal(movedTop.x, 20);
    const onDevice = dropDevice(nas, items, 20, 7, 16, []);
    assert.ok(onDevice);
    assert.equal(
        onDevice.find((item) => item.key === 'nas')?.shelf_key,
        'base',
    );
    assert.equal(dropDevice(nas, items, 20, 4, 16, []), null);
    assert.equal(dropDevice(nas, items, 20, 3, 16, []), null);
    const obstacle: RackItem = {
        ...shelf,
        key: 'obstacle',
        kind: 'drawer',
        position: 10,
    };
    assert.equal(dropDevice(nas, [...items, obstacle], 20, 10, 16, []), null);
    assert.equal(nas.shelf_key, 's');
    assert.equal(top.x, 10);
});

test('shelf base adds no U to supported devices while keeping its ears inside the rack', () => {
    const shelf: RackItem = {
        key: 's',
        kind: 'shelf',
        device_id: null,
        shelf_key: null,
        position: 0,
        height: 1,
        width: 100,
        x: 0,
    };
    const nas: RackItem = {
        ...shelf,
        key: 'n',
        kind: 'device',
        device_id: 1,
        shelf_key: 's',
        height: 4,
        width: 40,
        x: 20,
    };
    const top: RackItem = {
        ...nas,
        key: 't',
        device_id: 2,
        shelf_key: 'n',
        height: 1,
    };
    const items = [shelf, nas, top];
    assert.equal(itemBounds(nas, items).bottom, 0);
    assert.equal(itemBounds(top, items).bottom, 4);
    assert.equal(layoutError([shelf, nas], 4, []), null);
    assert.equal(layoutError(items, 5, []), null);
    assert.notEqual(layoutError(items, 4, []), null);
    assert.notEqual(
        layoutError([shelf, { ...shelf, key: 'duplicate' }], 4, []),
        null,
    );
    assert.notEqual(layoutError([{ ...shelf, position: 4 }], 4, []), null);
});

test('stacked devices follow their support and cannot form cycles or lose contact', () => {
    const base: RackItem = {
        key: 'a',
        kind: 'device',
        device_id: 1,
        shelf_key: null,
        position: 2,
        height: 2,
        width: 100,
        x: 0,
    };
    const nas: RackItem = {
        ...base,
        key: 'b',
        device_id: 2,
        shelf_key: 'a',
        height: 4,
        width: 40,
        x: 20,
    };
    const top: RackItem = {
        ...nas,
        key: 'c',
        device_id: 3,
        shelf_key: 'b',
        height: 1,
        width: 20,
        x: 25,
    };
    const items = [top, base, nas];
    assert.deepEqual(itemBounds(top, items), {
        bottom: 8,
        top: 9,
        left: 25,
        right: 45,
    });
    assert.equal(layoutError(items, 12, []), null);
    const moved = moveItem(items, 'b', 2, 30);
    assert.equal(moved.find((item) => item.key === 'c')?.x, 35);
    const raised = moveItem(moved, 'a', 5);
    assert.deepEqual(
        raised.map((item) => item.position),
        [5, 5, 5],
    );
    assert.equal(itemBounds(raised[0], raised).top, 12);
    assert.equal(layoutError(raised, 12, []), null);
    assert.notEqual(layoutError(raised, 11, []), null);
    assert.notEqual(layoutError([base, nas, { ...top, x: 80 }], 12, []), null);
    assert.notEqual(layoutError([base, top], 12, []), null);
    const cycle = [base, { ...nas, shelf_key: 'c' }, top];
    assert.equal(supportChain(top, cycle), null);
    assert.notEqual(layoutError(cycle, 12, []), null);
});
