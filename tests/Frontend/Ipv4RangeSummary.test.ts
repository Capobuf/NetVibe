import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ipv4RangeSummary } from '../../resources/js/lib/ipv4.ts';

test('range preview counts inclusive addresses and locates them within the subnet', () => {
    assert.deepEqual(
        ipv4RangeSummary('10.0.0.0/24', '10.0.0.100', '10.0.0.150'),
        {
            count: 51,
            total: 256,
            offset: 39.0625,
        },
    );
    assert.deepEqual(
        ipv4RangeSummary('10.0.0.0/23', '10.0.0.255', '10.0.1.0'),
        {
            count: 2,
            total: 512,
            offset: (255 / 512) * 100,
        },
    );
    assert.deepEqual(
        ipv4RangeSummary(
            '255.255.255.255/32',
            '255.255.255.255',
            '255.255.255.255',
        ),
        {
            count: 1,
            total: 1,
            offset: 0,
        },
    );
    for (const [start, end] of [
        ['10.0.0.', '10.0.0.150'],
        ['10.0.0.151', '10.0.0.150'],
        ['10.0.0.100', '10.0.1.0'],
        ['9.255.255.255', '10.0.0.150'],
    ]) {
        assert.equal(ipv4RangeSummary('10.0.0.0/24', start, end), null);
    }
});
