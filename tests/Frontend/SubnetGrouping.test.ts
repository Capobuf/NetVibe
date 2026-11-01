import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupSubnetsByVlan } from '../../resources/js/lib/subnet-list.ts';
import { sortTableRows } from '../../resources/js/lib/table-sort.ts';

test('VLAN grouping preserves sorted rows and unassigned subnets across page boundaries', () => {
    const rows = Array.from({ length: 57 }, (_, id) => ({
        id,
        cidr: `10.0.${id}.0/24`,
        name: null,
        description: null,
        vlan_id: id % 3 === 0 ? null : id % 3,
    }));
    const vlans = [
        { id: 1, tag: 20, name: 'Uffici' },
        { id: 2, tag: 10, name: 'Ospiti' },
    ];
    const sorted = sortTableRows(
        rows,
        { column: 'cidr', direction: 'desc' },
        (row) => row.cidr,
    );
    const grouped = groupSubnetsByVlan(sorted, vlans);

    assert.deepEqual(grouped, [
        ...sorted.filter((row) => row.vlan_id === 2),
        ...sorted.filter((row) => row.vlan_id === 1),
        ...sorted.filter((row) => row.vlan_id === null),
    ]);
    for (const size of [25, 50]) {
        const pages = Array.from(
            { length: Math.ceil(grouped.length / size) },
            (_, page) => grouped.slice(page * size, (page + 1) * size),
        );
        assert.deepEqual(pages.flat(), grouped);
        assert.equal(
            new Set(pages.flat().map((row) => row.id)).size,
            rows.length,
        );
    }
    assert.equal(rows[0].id, 0);
    assert.deepEqual(
        groupSubnetsByVlan(
            rows.filter((row) => row.vlan_id === null),
            [],
        ),
        rows.filter((row) => row.vlan_id === null),
    );
});
