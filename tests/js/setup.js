// jsdom has no MathML support, but Datastar checks `el instanceof MathMLElement` when the DOM changes.
// No element is ever one here, so an empty class is enough.
globalThis.MathMLElement ??= class MathMLElement extends Element {};
