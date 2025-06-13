class GitHubAPI {
    constructor(token, owner, repo) {
        this.token = token;
        this.owner = owner;
        this.repo = repo;
        this.baseURL = 'https://api.github.com';
        this.headers = {
            'Authorization': `token ${token}`,
            'Accept': 'application/vnd.github.v3+json',
            'Content-Type': 'application/json'
        };
    }
    
    async makeRequest(endpoint, method = 'GET', data = null) {
        const url = `${this.baseURL}${endpoint}`;
        const options = {
            method,
            headers: this.headers
        };
        
        if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
            options.body = JSON.stringify(data);
        }
        
        try {
            const response = await fetch(url, options);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error en petición a GitHub API:', error);
            throw error;
        }
    }
    
    // Obtener información del repositorio
    async getRepo() {
        return await this.makeRequest(`/repos/${this.owner}/${this.repo}`);
    }
    
    // Listar archivos en el repositorio
    async getContents(path = '') {
        return await this.makeRequest(`/repos/${this.owner}/${this.repo}/contents/${path}`);
    }
    
    // Crear o actualizar archivo
    async createOrUpdateFile(path, content, message, sha = null) {
        const data = {
            message,
            content: btoa(unescape(encodeURIComponent(content))), // Base64 encode
        };
        
        if (sha) {
            data.sha = sha; // Para actualizar archivo existente
        }
        
        return await this.makeRequest(
            `/repos/${this.owner}/${this.repo}/contents/${path}`,
            'PUT',
            data
        );
    }
    
    // Obtener archivo específico
    async getFile(path) {
        const response = await this.makeRequest(`/repos/${this.owner}/${this.repo}/contents/${path}`);
        
        // Decodificar contenido de Base64
        const content = decodeURIComponent(escape(atob(response.content)));
        
        return {
            ...response,
            decodedContent: content
        };
    }
    
    // Crear issue
    async createIssue(title, body, labels = []) {
        const data = {
            title,
            body,
            labels
        };
        
        return await this.makeRequest(`/repos/${this.owner}/${this.repo}/issues`, 'POST', data);
    }
    
    // Listar issues
    async getIssues(state = 'open') {
        return await this.makeRequest(`/repos/${this.owner}/${this.repo}/issues?state=${state}`);
    }
    
    // Obtener commits
    async getCommits(page = 1, perPage = 30) {
        return await this.makeRequest(`/repos/${this.owner}/${this.repo}/commits?page=${page}&per_page=${perPage}`);
    }
    
    // Crear una API JSON dinámica
    async createJSONAPI(apiData) {
        const timestamp = new Date().toISOString();
        const content = JSON.stringify({
            ...apiData,
            lastUpdated: timestamp,
            version: '1.0.0'
        }, null, 2);
        
        try {
            // Intentar obtener el archivo existente
            const existingFile = await this.getFile('api/data.json');
            
            // Actualizar archivo existente
            return await this.createOrUpdateFile(
                'api/data.json',
                content,
                `Actualizar API data - ${timestamp}`,
                existingFile.sha
            );
        } catch (error) {
            // Crear nuevo archivo si no existe
            return await this.createOrUpdateFile(
                'api/data.json',
                content,
                `Crear API data - ${timestamp}`
            );
        }
    }
}

// Ejemplo de uso
async function ejemploUso() {
    // Reemplaza con tu token personal de GitHub
    const token = 'ghp_tu_token_aqui';
    const owner = 'tu-usuario';
    const repo = 'tu-repositorio';
    
    const github = new GitHubAPI(token, owner, repo);
    
    try {
        // Obtener información del repo
        console.log('📁 Información del repositorio:');
        const repoInfo = await github.getRepo();
        console.log(`Nombre: ${repoInfo.name}`);
        console.log(`Descripción: ${repoInfo.description}`);
        console.log(`Estrellas: ${repoInfo.stargazers_count}`);
        
        // Crear datos para la API
        const apiData = {
            users: [
                { id: 1, name: 'Juan', email: 'juan@example.com' },
                { id: 2, name: 'María', email: 'maria@example.com' }
            ],
            products: [
                { id: 1, name: 'Producto A', price: 99.99 },
                { id: 2, name: 'Producto B', price: 149.99 }
            ]
        };
        
        // Crear/actualizar API
        console.log('🚀 Creando API JSON...');
        const result = await github.createJSONAPI(apiData);
        console.log('✅ API creada exitosamente:', result.content.html_url);
        
        // Listar commits recientes
        console.log('📝 Commits recientes:');
        const commits = await github.getCommits(1, 5);
        commits.forEach(commit => {
            console.log(`- ${commit.commit.message} (${commit.commit.author.name})`);
        });
        
    } catch (error) {
        console.error('❌ Error:', error.message);
    }
}

// Ejecutar ejemplo
ejemploUso();
