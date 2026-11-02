/**
 * Ravi Developer - Syntax Highlighting
 * Carrega e configura highlight.js para blocos de código
 */

(function() {
    // Highlight.js CDN
    const HLJS_VERSION = '11.9.0';
    const HLJS_CSS_URL = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/${HLJS_VERSION}/styles/atom-one-dark.min.css`;
    const HLJS_JS_URL = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/${HLJS_VERSION}/highlight.min.js`;

    // Languages to load
    const LANGUAGES = [
        'php', 'javascript', 'typescript', 'python', 'bash', 'shell',
        'sql', 'json', 'xml', 'css', 'html', 'yaml', 'markdown',
        'dockerfile', 'nginx', 'apache', 'ini', 'go', 'rust', 'java'
    ];

    let loaded = false;
    let loadPromise = null;

    /**
     * Load highlight.js from CDN
     */
    function loadHighlightJS() {
        if (loadPromise) return loadPromise;

        loadPromise = new Promise((resolve, reject) => {
            // Load CSS
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = HLJS_CSS_URL;
            document.head.appendChild(css);

            // Load JS
            const script = document.createElement('script');
            script.src = HLJS_JS_URL;
            script.onload = () => {
                loaded = true;
                
                // Configure
                if (window.hljs) {
                    hljs.configure({
                        ignoreUnescapedHTML: true,
                        throwUnescapedHTML: false
                    });
                }
                
                resolve(window.hljs);
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });

        return loadPromise;
    }

    /**
     * Highlight all code blocks on the page
     */
    async function highlightAll() {
        if (!loaded) {
            await loadHighlightJS();
        }

        if (window.hljs) {
            document.querySelectorAll('pre code').forEach(block => {
                if (!block.classList.contains('hljs')) {
                    hljs.highlightElement(block);
                }
            });
        }
    }

    /**
     * Highlight a specific element
     */
    async function highlight(element) {
        if (!loaded) {
            await loadHighlightJS();
        }

        if (window.hljs && element) {
            hljs.highlightElement(element);
        }
    }

    /**
     * Highlight code string and return HTML
     */
    async function highlightCode(code, language = 'plaintext') {
        if (!loaded) {
            await loadHighlightJS();
        }

        if (window.hljs) {
            try {
                const result = hljs.highlight(code, { 
                    language: language,
                    ignoreIllegals: true 
                });
                return result.value;
            } catch (e) {
                // Fallback if language not supported
                return hljs.highlightAuto(code).value;
            }
        }

        return escapeHtml(code);
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Get language from file extension
     */
    function getLanguageFromExtension(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const map = {
            'js': 'javascript',
            'mjs': 'javascript',
            'cjs': 'javascript',
            'jsx': 'javascript',
            'ts': 'typescript',
            'tsx': 'typescript',
            'py': 'python',
            'pyw': 'python',
            'php': 'php',
            'phtml': 'php',
            'rb': 'ruby',
            'erb': 'ruby',
            'java': 'java',
            'kt': 'kotlin',
            'go': 'go',
            'rs': 'rust',
            'c': 'c',
            'h': 'c',
            'cpp': 'cpp',
            'cc': 'cpp',
            'hpp': 'cpp',
            'cs': 'csharp',
            'swift': 'swift',
            'css': 'css',
            'scss': 'scss',
            'sass': 'sass',
            'less': 'less',
            'html': 'html',
            'htm': 'html',
            'vue': 'html',
            'xml': 'xml',
            'svg': 'xml',
            'json': 'json',
            'yaml': 'yaml',
            'yml': 'yaml',
            'toml': 'ini',
            'ini': 'ini',
            'cfg': 'ini',
            'conf': 'nginx',
            'md': 'markdown',
            'markdown': 'markdown',
            'sql': 'sql',
            'sh': 'bash',
            'bash': 'bash',
            'zsh': 'bash',
            'fish': 'shell',
            'ps1': 'powershell',
            'dockerfile': 'dockerfile',
            'makefile': 'makefile',
            'cmake': 'cmake',
            'gradle': 'gradle',
            'r': 'r',
            'lua': 'lua',
            'perl': 'perl',
            'pl': 'perl'
        };
        
        // Handle special filenames
        const specialFiles = {
            'dockerfile': 'dockerfile',
            'makefile': 'makefile',
            'cmakelists.txt': 'cmake',
            '.gitignore': 'plaintext',
            '.env': 'ini',
            '.htaccess': 'apache',
            'nginx.conf': 'nginx',
            'docker-compose.yml': 'yaml',
            'docker-compose.yaml': 'yaml',
            'package.json': 'json',
            'composer.json': 'json',
            'tsconfig.json': 'json'
        };

        const basename = filename.split('/').pop().toLowerCase();
        return specialFiles[basename] || map[ext] || 'plaintext';
    }

    /**
     * Create a highlighted code block element
     */
    async function createCodeBlock(code, language = 'plaintext', options = {}) {
        const highlighted = await highlightCode(code, language);
        
        const container = document.createElement('div');
        container.className = 'code-block-container';
        
        let header = '';
        if (options.filename || options.showLanguage !== false) {
            header = `
                <div class="code-block-header">
                    <span class="code-language">${options.filename || language}</span>
                    <button class="code-copy-btn" title="Copiar código">📋</button>
                </div>
            `;
        }

        container.innerHTML = `
            ${header}
            <pre class="code-block"><code class="hljs language-${language}">${highlighted}</code></pre>
        `;

        // Add copy functionality
        const copyBtn = container.querySelector('.code-copy-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => {
                navigator.clipboard.writeText(code).then(() => {
                    copyBtn.textContent = '✅';
                    setTimeout(() => {
                        copyBtn.textContent = '📋';
                    }, 2000);
                });
            });
        }

        return container;
    }

    /**
     * Line numbers support
     */
    function addLineNumbers(element) {
        const code = element.textContent;
        const lines = code.split('\n');
        const lineNumbers = lines.map((_, i) => `<span class="line-number">${i + 1}</span>`).join('');
        
        const wrapper = document.createElement('div');
        wrapper.className = 'code-with-lines';
        wrapper.innerHTML = `
            <div class="line-numbers">${lineNumbers}</div>
            <div class="code-content">${element.outerHTML}</div>
        `;
        
        element.parentNode.replaceChild(wrapper, element);
    }

    // Auto-load and highlight on DOM ready
    document.addEventListener('DOMContentLoaded', () => {
        // Check if there are code blocks to highlight
        if (document.querySelectorAll('pre code').length > 0) {
            highlightAll();
        }
    });

    // Watch for dynamically added code blocks
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1) { // Element node
                    const codeBlocks = node.querySelectorAll?.('pre code:not(.hljs)');
                    if (codeBlocks?.length > 0) {
                        codeBlocks.forEach(block => highlight(block));
                    }
                }
            });
        });
    });

    observer.observe(document.body, { 
        childList: true, 
        subtree: true 
    });

    // Export API
    window.SyntaxHighlight = {
        load: loadHighlightJS,
        highlightAll,
        highlight,
        highlightCode,
        createCodeBlock,
        addLineNumbers,
        getLanguageFromExtension
    };
})();
