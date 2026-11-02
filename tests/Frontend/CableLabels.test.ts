import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    defaultEndpointFormat,
    defaultLabelSettings,
    labelPreset,
    labelPrintSides,
    layoutLabel,
    minimumWrap,
    resolveLabelText,
    sampleLabelData,
} from '../../resources/js/lib/cable-labels.ts';

const measure = (text: string, size: number) => [...text].length * size * 0.6;

test('labels resolve current and explicit endpoints without substituting missing documented data', () => {
    assert.equal(
        resolveLabelText(
            '{{estremita}}',
            sampleLabelData,
            'a',
            defaultEndpointFormat,
        ).text,
        '[PT] RACK-01\\SW-CORE@24',
    );
    assert.equal(
        resolveLabelText(
            '{{estremita}}',
            sampleLabelData,
            'b',
            defaultEndpointFormat,
        ).text,
        '[P-1] RACK-02\\PH0@12',
    );
    assert.equal(
        resolveLabelText(
            '{{a.piano}} / {{b.dispositivo}}',
            sampleLabelData,
            'b',
            defaultEndpointFormat,
        ).text,
        'PT / PH0',
    );
    const outside = {
        ...sampleLabelData,
        b: { ...sampleLabelData.b, piano: null, rack: null, porta: null },
    };
    const result = resolveLabelText(
        '{{estremita_b}}',
        outside,
        'a',
        defaultEndpointFormat,
    );
    assert.equal(result.errors.length, 3);
    assert.ok(!result.text.includes('undefined'));
    assert.equal(
        resolveLabelText(
            '{{toString}}',
            sampleLabelData,
            'a',
            defaultEndpointFormat,
        ).errors.length,
        1,
    );
});

test('optional names remove their block and adjacent separators, while blank labels cannot print', () => {
    const settings = {
        ...defaultLabelSettings(18, 'copper'),
        front: labelPreset(18, 'inline'),
    };
    const layout = layoutLabel(
        settings,
        18,
        { ...sampleLabelData, name: null },
        'a',
        measure,
    );
    assert.deepEqual(layout.errors, []);
    assert.equal(
        layout.faces[0].filter((text) => text.text === ' | ').length,
        1,
    );
    settings.front = labelPreset(18, 'name');
    assert.ok(
        layoutLabel(
            settings,
            18,
            { ...sampleLabelData, name: null },
            'a',
            measure,
        ).errors.some((error) => error.includes('vuota')),
    );
});

test('long text grows the face without truncation; wrap and both face compositions control print validity', () => {
    const settings = defaultLabelSettings(12, 'fiber');
    const short = layoutLabel(settings, 12, sampleLabelData, 'a', measure);
    const data = {
        ...sampleLabelData,
        a: { ...sampleLabelData.a, dispositivo: 'SW-CORE-'.repeat(5) },
    };
    const long = layoutLabel(settings, 12, data, 'a', measure);
    assert.ok(long.faceLength > short.faceLength);
    assert.deepEqual(long.errors, []);
    assert.ok(
        long.faces[0]
            .map((text) => text.text)
            .join('')
            .includes(data.a.dispositivo),
    );
    assert.equal(long.length, long.faceLength * 2 + settings.wrap);
    assert.ok(
        layoutLabel(
            { ...settings, max_length: 20, face_length: 20 },
            12,
            data,
            'a',
            measure,
        ).errors.some((error) => error.includes('non entra')),
    );
    assert.ok(minimumWrap(2, 'duplex') > minimumWrap(2, 'round'));
    assert.ok(
        layoutLabel(
            { ...settings, profile: 'duplex', wrap: 7 },
            12,
            sampleLabelData,
            'a',
            measure,
        ).errors.some((error) => error.includes('Avvolgimento')),
    );
    const opposite = layoutLabel(
        { ...settings, back_mode: 'opposite' },
        12,
        sampleLabelData,
        'a',
        measure,
    );
    assert.ok(
        opposite.faces[1]
            .map((text) => text.text)
            .join('')
            .includes('PH0'),
    );
});

test('automatic faces keep the complete 12 mm identification on one line and fixed lengths use only the chosen fitting policy', () => {
    const settings = defaultLabelSettings(12, 'copper');
    const automatic = layoutLabel(settings, 12, sampleLabelData, 'a', measure);
    assert.deepEqual(automatic.errors, []);
    assert.equal(automatic.faces[0].length, 1);
    assert.equal(automatic.faces[0][0].text, '[PT] RACK-01\\SW-CORE@24');
    assert.ok(automatic.faceLength > 17.5);
    const fixed = {
        ...settings,
        length_mode: 'fixed' as const,
        face_length: 30,
    };
    const extended = layoutLabel(fixed, 12, sampleLabelData, 'a', measure);
    assert.equal(extended.faceLength, automatic.faceLength);
    const shrunk = layoutLabel(
        { ...fixed, fit: 'shrink' },
        12,
        sampleLabelData,
        'a',
        measure,
    );
    assert.deepEqual(shrunk.errors, []);
    assert.equal(shrunk.faceLength, 30);
    assert.equal(shrunk.faces[0].length, 1);
    assert.ok(
        shrunk.faces[0][0].size >= 2 &&
            shrunk.faces[0][0].size < automatic.faces[0][0].size,
    );
    const wrapped = layoutLabel(
        { ...fixed, face_length: 35, fit: 'wrap' },
        12,
        sampleLabelData,
        'a',
        measure,
    );
    assert.deepEqual(wrapped.errors, []);
    assert.ok(wrapped.faces[0].length > 1);
    assert.ok(
        layoutLabel(
            { ...fixed, face_length: 8, fit: 'shrink' },
            12,
            sampleLabelData,
            'a',
            measure,
        ).errors.length > 0,
    );
    assert.ok(
        defaultLabelSettings(24, 'copper').front[0].blocks[0].size >
            defaultLabelSettings(18, 'copper').front[0].blocks[0].size,
    );
    assert.deepEqual(labelPrintSides('single'), ['a']);
    assert.deepEqual(labelPrintSides('both'), ['a', 'b']);
    assert.deepEqual(labelPrintSides('b'), ['b']);
});
