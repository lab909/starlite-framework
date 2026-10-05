// persist() and ready against the real Datastar client. One page per file: Datastar's signals are
// module-level state, so each file gets a fresh environment (see vitest.config.js).
//
// Vitest loads modules asynchronously, so Datastar has already scanned the page when a test starts:
// the browser timing persist() is built for (a page module runs before that scan, so restoring must
// wait for `ready`) is covered by the skeleton's Playwright tests.
import { beforeAll, expect, test } from 'vitest';

let datastar;
let starlite;

beforeAll(async () => {
    localStorage.setItem('demo', JSON.stringify({ _tone: { volume: 0.2, playing: true }, injected: 'x' }));
    document.body.innerHTML = '<div data-signals="{_tone: {volume: 0.5, playing: false}}"><span id="out" data-text="$_tone.volume"></span></div>';
    datastar = await import('datastar');
    starlite = await import('../../resources/js/starlite.js');
});

test('ready resolves once Datastar has applied the page, even for a module loaded late', async () => {
    await starlite.ready;
    expect(datastar.getPath('_tone.volume')).toBe(0.5);
});

test('persist restores only the listed signals, over the page defaults', async () => {
    await starlite.persist(['_tone.volume'], { key: 'demo' });

    expect(datastar.getPath('_tone.volume')).toBe(0.2);
    expect(datastar.getPath('_tone.playing')).toBe(false); // stored, but not listed
    expect(datastar.getPath('injected')).toBeUndefined();
    expect(document.getElementById('out').textContent).toBe('0.2');
});

test('persist saves every change of the listed signals, and only those', () => {
    datastar.mergePatch({ _tone: { volume: 0.9, playing: true } });

    expect(JSON.parse(localStorage.getItem('demo'))).toEqual({ _tone: { volume: 0.9 } });
});

test('a pattern selects signals too', async () => {
    datastar.mergePatch({ _mixer: { rain: 1, wind: 2 }, other: 3 });
    await starlite.persist(/^_mixer\./, { key: 'mixer' });

    expect(JSON.parse(localStorage.getItem('mixer'))).toEqual({ _mixer: { rain: 1, wind: 2 } });
});
