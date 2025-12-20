define([], function() {
    'use strict';

    // Minimal HTML escaping fallback (used only if markdown-it isn't present).
    const escapeHtml = (text) => {
        const div = document.createElement('div');
        div.textContent = String(text ?? '');
        return div.innerHTML;
    };

    // Extract LaTeX into placeholders so markdown rendering does not mangle it.
    const protectLatex = (text) => {
        const latexPlaceholders = new Map();

        // If katex isn't present, just return original.
        if (!window.katex) {
            return {text: String(text ?? ''), placeholders: latexPlaceholders};
        }

        let out = String(text ?? '');

        // Display math: \[ ... \]
        out = out.replace(/\\\[(\s|\S)*?\\\]/g, (match) => {
            const latex = match.slice(2, -2).trim();
            try {
                const rendered = window.katex.renderToString(latex, {
                    displayMode: true,
                    throwOnError: false
                });
                const placeholder = `@@KATEX_DISPLAY_${latexPlaceholders.size}@@`;
                latexPlaceholders.set(placeholder, rendered);
                return placeholder;
            } catch (e) {
                return match;
            }
        });

        // Inline math: \( ... \)
        out = out.replace(/\\\((\s|\S)*?\\\)/g, (match) => {
            const latex = match.slice(2, -2).trim();
            try {
                const rendered = window.katex.renderToString(latex, {
                    displayMode: false,
                    throwOnError: false
                });
                const placeholder = `@@KATEX_INLINE_${latexPlaceholders.size}@@`;
                latexPlaceholders.set(placeholder, rendered);
                return placeholder;
            } catch (e) {
                return match;
            }
        });

        return {text: out, placeholders: latexPlaceholders};
    };

    const restoreLatex = (html, placeholders) => {
        let out = html;
        placeholders.forEach((rendered, placeholder) => {
            const escapedPlaceholder = placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            out = out.replace(new RegExp(escapedPlaceholder, 'g'), rendered);
        });
        return out;
    };

    /**
     * Renders text to HTML using markdown-it if available; then restores KaTeX placeholders.
     * Keeps behavior close to your current code to avoid breaking output.
     */
    const renderToHtml = (text) => {
        const {text: protectedText, placeholders} = protectLatex(text);

        // Keep current behavior: allow markdown-it with HTML if it's already on the page.
        // (Later we can make a proper loader so it's not a global.)
        let html;
        if (window.markdownit) {
            const md = window.markdownit({html: true, breaks: true});
            html = md.render(protectedText);
        } else {
            html = escapeHtml(protectedText).replace(/\n/g, '<br>');
        }

        return restoreLatex(html, placeholders);
    };

    return {
        renderToHtml: renderToHtml,
    };
});
