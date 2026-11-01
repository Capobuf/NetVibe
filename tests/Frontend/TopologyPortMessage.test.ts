import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    topologyPortLabel,
    topologyPortMessage,
    type TopologyEdge,
    type TopologyNode,
} from '../../resources/js/types/topology.ts';

test('missing remote names, unknown devices and unmapped reported ports have distinct messages', () => {
    const node = { id: 'remote', device_id: 2, model_id: 6 } as TopologyNode;
    const edge = {
        source: 'local',
        target: 'remote',
        a: null,
        b: null,
        evidence: [
            {
                device_id: 1,
                local_port: 'ether1',
                remote_port: null,
                remote_port_description: 'NIC 1/10/25Gb 20',
                remote_port_mac: '02:00:00:00:00:02',
            },
        ],
    } as TopologyEdge;

    assert.equal(topologyPortLabel(edge, node), null);
    assert.equal(
        topologyPortMessage(edge, node),
        'La rilevazione non riporta la porta remota',
    );
    assert.equal(
        topologyPortMessage(edge, { ...node, device_id: null }),
        'Dispositivo rilevato, non associato all’inventario',
    );

    edge.evidence[0].remote_port = 'sfp-sfpplus1';
    assert.equal(topologyPortLabel(edge, node, false), 'sfp-sfpplus1');
    assert.equal(
        topologyPortMessage(edge, node),
        'Porta rilevata, non associata al disegno',
    );
    assert.equal(
        topologyPortMessage(edge, { ...node, model_id: null }),
        'Dispositivo senza modello associato',
    );
});
