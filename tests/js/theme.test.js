// theme(): applies the _theme signal to <html data-theme>, saves it, and follows the system in 'system' mode.
import { beforeAll, expect, test } from 'vitest';

let datastar;
const listeners = [];
const media = { matches: true, addEventListener: (_type, listener) => listeners.push(listener) };

beforeAll(async () => {
    window.matchMedia = () => media; // jsdom has no matchMedia: a dark system, switchable below
    localStorage.setItem('starlite-theme', JSON.stringify({ _theme: 'light' }));
    document.body.innerHTML = '<div data-signals:_theme="\'system\'"></div>';
    datastar = await import('datastar');
    const { theme } = await import('../../resources/js/starlite.js');
    await theme();
});

test('the saved choice wins over the page default and the system', () => {
    expect(datastar.getPath('_theme')).toBe('light');
    expect(document.documentElement.dataset.theme).toBe('light');
});

test('a new choice is applied and saved in the format the <head> script reads', () => {
    media.matches = false; // a light system: 'dark' must come from the choice
    datastar.mergePatch({ _theme: 'dark' });

    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(JSON.parse(localStorage.getItem('starlite-theme'))).toEqual({ _theme: 'dark' });
});

test("'system' follows the operating system as it switches", () => {
    media.matches = true;
    datastar.mergePatch({ _theme: 'system' });
    expect(document.documentElement.dataset.theme).toBe('dark');

    media.matches = false;
    listeners.forEach((listener) => listener());
    expect(document.documentElement.dataset.theme).toBe('light');
});

test('anything else counts as system', () => {
    media.matches = true;
    datastar.mergePatch({ _theme: 'purple' });

    expect(document.documentElement.dataset.theme).toBe('dark');
});
