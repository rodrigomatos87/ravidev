-- Contexto permanente do projeto: memórias (CLAUDE.md) + fontes externas (SSH) + acesso por membro.
-- Memórias: arquivos de instrução que o Claude Code usa por padrão (injetados em ~/.claude/CLAUDE.md),
-- fora do workspace (invisíveis na IDE).
CREATE TABLE IF NOT EXISTS project_memories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    content LONGTEXT,
    position INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pmem_project (project_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fontes externas (SSH): nome amigável + credenciais (senha CIFRADA) + diretório remoto.
-- enabled = o Claude pode acessar (ravi-ssh); auto_deploy = sincroniza as entregas aqui (rsync).
CREATE TABLE IF NOT EXISTS project_ssh_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL DEFAULT 22,
    username VARCHAR(150) NOT NULL,
    password_encrypted TEXT,
    remote_dir VARCHAR(500) NOT NULL DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    auto_deploy TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pssh_project (project_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seleção POR MEMBRO: o dono liga/desliga o acesso de cada membro às memórias/servidores.
ALTER TABLE project_members ADD COLUMN IF NOT EXISTS context_access TINYINT(1) NOT NULL DEFAULT 0;
