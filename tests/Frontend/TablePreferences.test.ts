import assert from 'node:assert/strict';
import { test } from 'node:test';
import { restoreTablePreferences } from '../../resources/js/lib/table-preferences.ts';

const defaults = {
    columns: [
        { id: 'hostname', label: 'Hostname', visible: true },
        { id: 'ip', label: 'IP', visible: true },
        { id: 'tags', label: 'Tag', visible: true },
    ],
    separators: true,
};

test('restores saved order, visibility and separators while retaining current column definitions', () => {
    const stored = JSON.stringify({
        columns: [
            { id: 'ip', visible: true, label: 'Obsolete label' },
            { id: 'hostname', visible: false },
            { id: 'removed', visible: true },
            { id: 'ip', visible: false },
        ],
        separators: false,
    });

    assert.deepEqual(restoreTablePreferences(defaults, stored), {
        columns: [
            defaults.columns[1],
            { ...defaults.columns[0], visible: false },
            defaults.columns[2],
        ],
        separators: false,
    });
});

test('invalid preferences cannot break the table or hide every column', () => {
    for (const stored of [null, '{', 'null', '{"columns":false}']) {
        assert.deepEqual(restoreTablePreferences(defaults, stored), defaults);
    }

    assert.deepEqual(
        restoreTablePreferences(
            defaults,
            JSON.stringify({
                columns: defaults.columns.map(({ id }) => ({
                    id,
                    visible: false,
                })),
                separators: 'invalid',
            }),
        ),
        defaults,
    );
});
