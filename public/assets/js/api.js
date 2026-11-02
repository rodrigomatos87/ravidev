/**
 * Ravi Developer - API Client
 * Cliente para comunicação com backend via Fetch API
 */

class RaviAPI {
    constructor(baseUrl = '') {
        this.baseUrl = baseUrl;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    /**
     * Headers padrão para requisições
     */
    getHeaders(isJson = true) {
        const headers = {
            'X-CSRF-TOKEN': this.csrfToken,
            'X-Requested-With': 'XMLHttpRequest'
        };
        
        if (isJson) {
            headers['Content-Type'] = 'application/json';
            headers['Accept'] = 'application/json';
        }
        
        return headers;
    }

    /**
     * Requisição genérica
     */
    async request(method, endpoint, data = null, options = {}) {
        const url = this.baseUrl + endpoint;
        const config = {
            method,
            headers: this.getHeaders(options.isJson !== false),
            credentials: 'same-origin',
            ...options
        };

        if (data && method !== 'GET') {
            config.body = options.isJson !== false ? JSON.stringify(data) : data;
        }

        try {
            const response = await fetch(url, config);
            
            // Handle auth errors
            if (response.status === 401) {
                window.location.href = '/login';
                throw new Error('Sessão expirada');
            }

            // Handle CSRF errors
            if (response.status === 419) {
                window.location.reload();
                throw new Error('Token CSRF inválido');
            }

            const contentType = response.headers.get('content-type');
            let result;

            if (contentType && contentType.includes('application/json')) {
                result = await response.json();
            } else {
                result = await response.text();
            }

            if (!response.ok) {
                throw new APIError(
                    result.message || result.error || 'Erro na requisição',
                    response.status,
                    result
                );
            }

            return result;
        } catch (error) {
            if (error instanceof APIError) {
                throw error;
            }
            throw new APIError(error.message || 'Erro de conexão', 0);
        }
    }

    get(endpoint, options) {
        return this.request('GET', endpoint, null, options);
    }

    post(endpoint, data, options) {
        return this.request('POST', endpoint, data, options);
    }

    put(endpoint, data, options) {
        return this.request('PUT', endpoint, data, options);
    }

    patch(endpoint, data, options) {
        return this.request('PATCH', endpoint, data, options);
    }

    delete(endpoint, options) {
        return this.request('DELETE', endpoint, null, options);
    }

    // ==================== Projects ====================

    async getProjects() {
        return this.get('/api/projects');
    }

    async getProject(uuid) {
        return this.get(`/api/projects/${uuid}`);
    }

    async createProject(data) {
        return this.post('/api/projects', data);
    }

    async updateProject(uuid, data) {
        return this.put(`/api/projects/${uuid}`, data);
    }

    async deleteProject(uuid) {
        return this.delete(`/api/projects/${uuid}`);
    }

    async archiveProject(uuid) {
        return this.post(`/api/projects/${uuid}/archive`);
    }

    // ==================== Conversations ====================

    async getConversations(projectUuid) {
        return this.get(`/api/projects/${projectUuid}/conversations`);
    }

    async getConversation(uuid) {
        return this.get(`/api/conversations/${uuid}`);
    }

    async createConversation(projectUuid, data) {
        return this.post(`/api/projects/${projectUuid}/conversations`, data);
    }

    async deleteConversation(uuid) {
        return this.delete(`/api/conversations/${uuid}`);
    }

    async cancelConversation(uuid) {
        return this.post(`/api/conversations/${uuid}/cancel`);
    }

    // ==================== Messages ====================

    async getMessages(conversationUuid) {
        return this.get(`/api/conversations/${conversationUuid}/messages`);
    }

    async sendMessage(conversationUuid, content) {
        return this.post(`/api/conversations/${conversationUuid}/messages`, { content });
    }

    // ==================== Files ====================

    async getFiles(conversationUuid) {
        return this.get(`/api/conversations/${conversationUuid}/files`);
    }

    async downloadFile(fileUuid) {
        return this.get(`/api/files/${fileUuid}/download`);
    }

    async downloadAllFiles(conversationUuid) {
        window.location.href = `/api/conversations/${conversationUuid}/files/download-all`;
    }

    // ==================== User ====================

    async getProfile() {
        return this.get('/api/user/profile');
    }

    async updateProfile(data) {
        return this.put('/api/user/profile', data);
    }

    async updatePassword(data) {
        return this.put('/api/user/password', data);
    }

    async updateApiKey(apiKey) {
        return this.put('/api/user/api-key', { api_key: apiKey });
    }

    async getUsage() {
        return this.get('/api/user/usage');
    }
}

/**
 * Erro customizado para API
 */
class APIError extends Error {
    constructor(message, status, data = null) {
        super(message);
        this.name = 'APIError';
        this.status = status;
        this.data = data;
    }
}

// Instância global
window.api = new RaviAPI();
window.APIError = APIError;
