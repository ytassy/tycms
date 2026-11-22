let mermaidPromise;
let sketchPromise;
const pending = new Set();
const lastSources = new WeakMap();
let rendering = false;
let renderQueue = Promise.resolve();

// Une seule génération à la fois, y compris entre plusieurs instances Vue.
export function renderElement(element) {
    const next = renderQueue.then(() => renderNow(element));
    renderQueue = next.catch(() => {});
    return next;
}
const looks = new Set(['classic', 'handDrawn', 'neo']);
const themes = new Set([
    'default', 'base', 'dark', 'forest', 'neutral', 'neo', 'neo-dark',
    'redux', 'redux-dark', 'redux-color', 'redux-dark-color',
]);

function getRenderInput(element, source) {
    const look = element.dataset.mermaidLook;
    const theme = element.dataset.mermaidTheme;
    const sketch = element.dataset.mermaidSketch;
    const wrapWidth = Number.parseInt(element.dataset.mermaidWrapWidth, 10);
    const config = [];
    if (looks.has(look)) config.push(`  look: ${look}`);
    if (themes.has(theme)) config.push(`  theme: ${theme}`);
    if (Number.isInteger(wrapWidth) && wrapWidth >= 40 && wrapWidth <= 1000) {
        config.push('  flowchart:');
        config.push(`    wrappingWidth: ${wrapWidth}`);
    }

    // La configuration présente dans la source Mermaid conserve sa priorité.
    const renderedSource = config.length && !/^---\s*\n/.test(source)
        ? `---\nconfig:\n${config.join('\n')}\n---\n${source}`
        : source;

    return { renderedSource, signature: JSON.stringify([source, look, theme, sketch, wrapWidth]) };
}

function getMermaid() {
    mermaidPromise ??= import('./lib/mermaid.mjs').then(({ default: mermaid }) => {
        mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            suppressErrorRendering: true,
            maxTextSize: 30000,
            maxEdges: 500,
            secure: ['secure', 'securityLevel', 'startOnLoad', 'maxTextSize', 'maxEdges', 'suppressErrorRendering', 'dompurifyConfig', 'themeCSS', 'fontFamily'],
        });
        return mermaid;
    });
    return mermaidPromise;
}

function getSketchConverter() {
    sketchPromise ??= import('./svg2roughjs.mjs');
    return sketchPromise;
}

async function applySketch(output) {
    const original = output.querySelector(':scope > svg');
    if (!original) throw new Error('Le SVG Mermaid est introuvable.');

    const { Svg2Roughjs } = await getSketchConverter();
    const converter = new Svg2Roughjs(output);
    converter.svg = original;
    const sketched = await converter.sketch();
    if (!(sketched instanceof SVGSVGElement)) {
        throw new Error('La conversion Hand Draw n’a pas produit de SVG.');
    }
    if (original !== sketched) original.remove();

    // La conversion écrit des pixels ; le viewBox conserve un affichage responsive.
    if (!sketched.hasAttribute('viewBox')) {
        const width = Number.parseFloat(sketched.getAttribute('width'));
        const height = Number.parseFloat(sketched.getAttribute('height'));
        if (width > 0 && height > 0) sketched.setAttribute('viewBox', `0 0 ${width} ${height}`);
    }
    sketched.setAttribute('width', '100%');
    sketched.setAttribute('height', '100%');
}

function enqueue(element) {
    if (!(element instanceof HTMLElement) || !element.matches('[data-shortcode-diagram]')) return;
    pending.add(element);
    if (!rendering) void drain();
}

function collect(node) {
    if (node instanceof Element) {
        if (node.matches('[data-shortcode-diagram]')) enqueue(node);
        node.querySelectorAll('[data-shortcode-diagram]').forEach(enqueue);
    } else if (node instanceof Document) {
        node.querySelectorAll('[data-shortcode-diagram]').forEach(enqueue);
    }
}

function showError(element, output, error) {
    output.replaceChildren();
    const message = document.createElement('div');
    message.className = 'alert alert-warning';
    message.textContent = 'Impossible de générer le diagramme Mermaid.';
    if (element.dataset.showDetails === '1') {
        const details = document.createElement('pre');
        details.textContent = error instanceof Error ? error.message : String(error);
        message.appendChild(details);
    }
    output.appendChild(message);
    element.dataset.mermaidStatus = 'error';
    console.error('TyTools Mermaid:', error);
}

async function renderNow(element) {
    if (!element.isConnected) return;
    const sourceElement = element.querySelector(':scope > .tytools-mermaid-source');
    const output = element.querySelector(':scope > .tytools-mermaid-output');
    if (!sourceElement || !output) return;

    const source = sourceElement.textContent.trim();
    const { renderedSource, signature } = getRenderInput(element, source);
    if (lastSources.get(element) === signature &&
        (element.dataset.mermaidStatus === 'error' || output.querySelector('svg'))) return;
    lastSources.set(element, signature);
    element.dataset.mermaidStatus = 'rendering';

    try {
        if (!source) throw new Error('Diagramme vide.');
        const mermaid = await getMermaid();
        if (!element.isConnected) return;
        const { svg, bindFunctions } = await mermaid.render('svg-' + element.id, renderedSource);
        if (!element.isConnected) return;
        if (getRenderInput(element, sourceElement.textContent.trim()).signature !== signature) {
            enqueue(element);
            return;
        }
        output.innerHTML = svg;
        if (element.dataset.mermaidSketch === '1') {
            await applySketch(output);
        } else {
            bindFunctions?.(output);
        }
        if (getRenderInput(element, sourceElement.textContent.trim()).signature !== signature) {
            enqueue(element);
            return;
        }
        element.dataset.mermaidStatus = 'ready';
    } catch (error) {
        if (element.isConnected) showError(element, output, error);
    }
}

async function drain() {
    if (rendering) return;
    rendering = true;
    try {
        while (pending.size) {
            const element = pending.values().next().value;
            pending.delete(element);
            await renderElement(element);
        }
    } finally {
        rendering = false;
    }
}

export function start() {
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes') {
                enqueue(mutation.target);
                continue;
            }
            for (const node of mutation.addedNodes) collect(node);
            const parent = mutation.target instanceof Element
                ? mutation.target
                : mutation.target.parentElement;
            const source = parent?.closest('.tytools-mermaid-source');
            if (source) enqueue(source.closest('[data-shortcode-diagram]'));
            const output = parent?.closest('.tytools-mermaid-output');
            if (output && !output.querySelector('svg')) enqueue(output.closest('[data-shortcode-diagram]'));
        }
    });
    observer.observe(document.documentElement, {
        childList: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['data-mermaid-look', 'data-mermaid-theme', 'data-mermaid-sketch', 'data-mermaid-wrap-width'],
        subtree: true,
    });
    collect(document);
}

