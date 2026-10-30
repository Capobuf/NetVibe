import type { ConnectorType } from './document';

const rj45 = 'M -17 -14 H 17 V 8 H 6 V 14 H -6 V 8 H -17 Z';

// Both the canvas and insertion menus use these paths, in connector coordinates.
export const connectorShapes: Record<
    ConnectorType,
    { outline: string; detail?: string }
> = {
    rj45: { outline: rj45 },
    console: { outline: rj45 },
    sfp: {
        outline: 'M -13 -9 H 13 V 9 H -13 Z',
        detail: 'M -10 6 H 10',
    },
    qsfp: {
        outline: 'M -19 -10 H 19 V 10 H -19 Z',
        detail: 'M -16 7 H 16',
    },
    usbA: { outline: 'M -15 -7 H 15 V 7 H -15 Z', detail: 'M -10 0 H 10' },
    usbC: {
        outline: 'M -6 -5 H 6 A 5 5 0 0 1 6 5 H -6 A 5 5 0 0 1 -6 -5 Z',
        detail: 'M -6 0 H 6',
    },
    usbMiniB: {
        outline: 'M -12 -7 H 12 V 2 L 7 7 H -7 L -12 2 Z',
        detail: 'M -7 -1 H 7',
    },
    usbMicroB: {
        outline: 'M -9 -5 H 9 L 12 1 V 5 H -12 V 1 Z',
        detail: 'M -7 1 H 7',
    },
    ac: {
        outline: 'M -20 -8 L -12 -16 H 12 L 20 -8 V 16 H -20 Z',
        detail: 'M -1.5 -11 H 1.5 V -3 H -1.5 Z M -11.5 2 H -8.5 V 10 H -11.5 Z M 8.5 2 H 11.5 V 10 H 8.5 Z',
    },
    acC19: {
        outline:
            'M -20 -17 H 20 Q 23 -17 23 -14 V 14 Q 23 17 20 17 H -20 Q -23 17 -23 14 V -14 Q -23 -17 -20 -17 Z',
        detail: 'M -4 -8.5 H 4 V -5.5 H -4 Z M -14 4.5 H -6 V 7.5 H -14 Z M 6 4.5 H 14 V 7.5 H 6 Z',
    },
    acC7: {
        outline: 'M 0 -5.657 A 9 9 0 1 0 0 5.657 A 9 9 0 1 0 0 -5.657 Z',
        detail: 'M -9.5 0 A 2.5 2.5 0 1 0 -4.5 0 A 2.5 2.5 0 1 0 -9.5 0 M 4.5 0 A 2.5 2.5 0 1 0 9.5 0 A 2.5 2.5 0 1 0 4.5 0',
    },
    acC5: {
        outline:
            'M -7 -4 L -7 -7 A 7 7 0 0 1 7 -7 L 7 -4 C 12 -4 15 0 15 6 C 15 11 11 14 7 14 C 4 14 2 13 0 11 C -2 13 -4 14 -7 14 C -11 14 -15 11 -15 6 C -15 0 -12 -4 -7 -4 Z',
        detail: 'M -2.5 -7 A 2.5 2.5 0 1 0 2.5 -7 A 2.5 2.5 0 1 0 -2.5 -7 M -9.5 6 A 2.5 2.5 0 1 0 -4.5 6 A 2.5 2.5 0 1 0 -9.5 6 M 4.5 6 A 2.5 2.5 0 1 0 9.5 6 A 2.5 2.5 0 1 0 4.5 6',
    },
    dc: {
        outline: 'M -11 0 A 11 11 0 1 0 11 0 A 11 11 0 1 0 -11 0 Z',
        detail: 'M -2.5 0 A 2.5 2.5 0 1 0 2.5 0 A 2.5 2.5 0 1 0 -2.5 0 Z',
    },
};
