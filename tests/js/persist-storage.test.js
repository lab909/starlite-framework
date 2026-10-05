// When storage is corrupt or unavailable, the page starts from its defaults instead of breaking.
import { beforeAll, expect, test } from 'vitest';

let datastar;
let starlite;

beforeAll(async () => {
    localStorage.setItem('broken', 'not json');
    document.body.innerHTML = '<div data-signals="{volume: 0.5}"></div>';
    datastar = await import('datastar');
    starlite = await import('../../resources/js/starlite.js');
});

test('corrupt storage is ignored, then overwritten', async () => {
    await starlite.persist(['volume'], { key: 'broken' });

    expect(datastar.getPath('volume')).toBe(0.5);
    expect(JSON.parse(localStorage.getItem('broken'))).toEqual({ volume: 0.5 });
});

test('storage that throws (private mode, quota) does not break the page', async () => {
    const storage = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };

    await expect(starlite.persist(['volume'], { key: 'x', storage })).resolves.toBeTypeOf('function');
    datastar.mergePatch({ volume: 0.7 });
    expect(datastar.getPath('volume')).toBe(0.7);
});
