/**
 * Ravi Developer - Markdown Parser
 * Parser leve de Markdown para mensagens do chat
 */

class MarkdownParser {
    constructor(options = {}) {
        this.options = {
            sanitize: true,
            breaks: true,
            linkTarget: '_blank',
            ...options
        };
    }

    parse(text) {
        if (!text) return '';

        let html = text;

        // Escape HTML if sanitize enabled
        if (this.options.sanitize) {
            html = this.escapeHtml(html);
        }

        // Code blocks (must be first to protect content)
        html = this.parseCodeBlocks(html);

        // Inline code
        html = this.parseInlineCode(html);

        // Headers
        html = this.parseHeaders(html);

        // Bold and italic
        html = this.parseBoldItalic(html);

        // Strikethrough
        html = this.parseStrikethrough(html);

        // Links
        html = this.parseLinks(html);

        // Images
        html = this.parseImages(html);

        // Lists
        html = this.parseLists(html);

        // Blockquotes
        html = this.parseBlockquotes(html);

        // Horizontal rules
        html = this.parseHorizontalRules(html);

        // Line breaks
        if (this.options.breaks) {
            html = this.parseLineBreaks(html);
        }

        // Paragraphs
        html = this.parseParagraphs(html);

        return html;
    }

    escapeHtml(text) {
        const escapeMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, char => escapeMap[char]);
    }

    parseCodeBlocks(text) {
        // Fenced code blocks with language
        return text.replace(/```(\w*)\n([\s\S]*?)```/g, (match, lang, code) => {
            const language = lang || 'plaintext';
            const trimmedCode = code.trim();
            return `<pre class="code-block" data-language="${language}"><code class="language-${language}">${trimmedCode}</code><button type="button" class="code-copy-btn" data-tooltip="Copiar código" aria-label="Copiar código">Copiar</button></pre>`;
        });
    }

    parseInlineCode(text) {
        return text.replace(/`([^`\n]+)`/g, '<code class="inline-code">$1</code>');
    }

    parseHeaders(text) {
        // H1-H6
        return text
            .replace(/^###### (.+)$/gm, '<h6>$1</h6>')
            .replace(/^##### (.+)$/gm, '<h5>$1</h5>')
            .replace(/^#### (.+)$/gm, '<h4>$1</h4>')
            .replace(/^### (.+)$/gm, '<h3>$1</h3>')
            .replace(/^## (.+)$/gm, '<h2>$1</h2>')
            .replace(/^# (.+)$/gm, '<h1>$1</h1>');
    }

    parseBoldItalic(text) {
        // Bold and italic combined
        text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        text = text.replace(/___(.+?)___/g, '<strong><em>$1</em></strong>');
        
        // Bold
        text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/__(.+?)__/g, '<strong>$1</strong>');
        
        // Italic
        text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
        text = text.replace(/_([^_\n]+)_/g, '<em>$1</em>');
        
        return text;
    }

    parseStrikethrough(text) {
        return text.replace(/~~(.+?)~~/g, '<del>$1</del>');
    }

    parseLinks(text) {
        // Named links [text](url)
        text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, text, url) => {
            const safeUrl = this.sanitizeUrl(url);
            return `<a href="${safeUrl}" target="${this.options.linkTarget}" rel="noopener noreferrer">${text}</a>`;
        });

        // Auto-link URLs
        text = text.replace(/(^|[^"'>])(https?:\/\/[^\s<]+)/g, (match, prefix, url) => {
            return `${prefix}<a href="${url}" target="${this.options.linkTarget}" rel="noopener noreferrer">${url}</a>`;
        });

        return text;
    }

    parseImages(text) {
        return text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (match, alt, src) => {
            const safeSrc = this.sanitizeUrl(src);
            return `<img src="${safeSrc}" alt="${alt}" class="markdown-image" loading="lazy">`;
        });
    }

    parseLists(text) {
        // Unordered lists
        text = text.replace(/^(\s*)[*\-+] (.+)$/gm, (match, indent, content) => {
            return `<li class="ul-item" style="margin-left: ${indent.length * 20}px">${content}</li>`;
        });

        // Ordered lists
        text = text.replace(/^(\s*)\d+\. (.+)$/gm, (match, indent, content) => {
            return `<li class="ol-item" style="margin-left: ${indent.length * 20}px">${content}</li>`;
        });

        // Wrap consecutive list items
        text = text.replace(/((?:<li class="ul-item"[^>]*>[^<]+<\/li>\n?)+)/g, '<ul>$1</ul>');
        text = text.replace(/((?:<li class="ol-item"[^>]*>[^<]+<\/li>\n?)+)/g, '<ol>$1</ol>');

        return text;
    }

    parseBlockquotes(text) {
        // Single line blockquotes
        text = text.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
        
        // Merge consecutive blockquotes
        text = text.replace(/<\/blockquote>\n<blockquote>/g, '\n');
        
        return text;
    }

    parseHorizontalRules(text) {
        return text.replace(/^(-{3,}|\*{3,}|_{3,})$/gm, '<hr>');
    }

    parseLineBreaks(text) {
        // Don't add breaks inside code blocks or other block elements
        return text.replace(/(?<!<\/pre>|<\/blockquote>|<\/li>|<\/h\d>)\n(?!<)/g, '<br>\n');
    }

    parseParagraphs(text) {
        // Split by double newlines and wrap in paragraphs
        const blocks = text.split(/\n\n+/);
        return blocks.map(block => {
            block = block.trim();
            if (!block) return '';
            
            // Don't wrap block elements
            if (block.match(/^<(pre|ul|ol|blockquote|h[1-6]|hr|div)/)) {
                return block;
            }
            
            return `<p>${block}</p>`;
        }).join('\n');
    }

    sanitizeUrl(url) {
        // Basic URL sanitization
        url = url.trim();
        
        // Block javascript: and data: URLs
        if (url.match(/^(javascript|data|vbscript):/i)) {
            return '#';
        }
        
        return url;
    }
}

// Singleton instance
window.markdown = new MarkdownParser();

// Helper function
window.parseMarkdown = function(text) {
    return window.markdown.parse(text);
};

// Export class
window.MarkdownParser = MarkdownParser;
