// Icon.vue's markup as [tag, attributes] pairs, parsed once per icon. Every
// icon is a run of self-closing shapes with quoted attributes
// (`<path d="…"/><circle cx="…"/>`), which is all this reads.
const SHAPE = /<(\w+)((?:\s+[\w-]+="[^"]*")*)\s*\/>/g;
const ATTRIBUTE = /([\w-]+)="([^"]*)"/g;

export function svgShapes(markup) {
    return [...markup.matchAll(SHAPE)].map(([, tag, attributes]) => [
        tag,
        Object.fromEntries([...attributes.matchAll(ATTRIBUTE)].map(([, name, value]) => [name, value])),
    ]);
}
