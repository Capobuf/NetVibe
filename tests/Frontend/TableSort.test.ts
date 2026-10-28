import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    nextTableSort,
    sortTableRows,
} from '../../resources/js/lib/table-sort.ts';

test('network tables sort numerically without mutating the default order and restore it after the third click', () => {
    const rows = [
        { cidr: '192.168.10.0/24', vlan: 100 },
        { cidr: '192.168.2.0/24', vlan: 2 },
        { cidr: '10.0.0.0/24', vlan: 10 },
    ];
    const ascending = nextTableSort(null, 'cidr');
    const descending = nextTableSort(ascending, 'cidr');
    const restored = nextTableSort(descending, 'cidr');

    assert.deepEqual(
        sortTableRows(rows, ascending, (row) => row.cidr),
        [rows[2], rows[1], rows[0]],
    );
    assert.deepEqual(
        sortTableRows(rows, descending, (row) => row.cidr),
        [rows[0], rows[1], rows[2]],
    );
    assert.equal(restored, null);
    assert.equal(
        sortTableRows(rows, restored, (row) => row.cidr),
        rows,
    );
    assert.deepEqual(
        sortTableRows(
            rows,
            nextTableSort(descending, 'vlan'),
            (row) => row.vlan,
        ),
        [rows[1], rows[2], rows[0]],
    );
    assert.equal(rows[0].cidr, '192.168.10.0/24');
});
