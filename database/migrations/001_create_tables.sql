-- ============================================================================
-- Ravi Developer - Database Migrations
-- Version: 1.0.0
-- Created: 2025-01-04
-- ============================================================================

-- Set charset and collation
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================================
-- 001: Users Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    api_key_encrypted TEXT NULL COMMENT 'Chave API Claude do usuário (criptografada)',
    avatar_url VARCHAR(500) NULL,
    plan ENUM('free', 'pro', 'enterprise') DEFAULT 'free',
    tokens_used INT DEFAULT 0,
    tokens_limit INT DEFAULT 100000,
    is_active BOOLEAN DEFAULT TRUE,
    email_verified_at TIMESTAMP NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_uuid (uuid),
    INDEX idx_remember_token (remember_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 002: Password Resets Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 003: Projects Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(50) DEFAULT '📁',
    color VARCHAR(7) DEFAULT '#6366f1',
    status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
    settings JSON NULL COMMENT 'Configurações específicas do projeto',
    last_activity_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status),
    INDEX idx_uuid (uuid),
    INDEX idx_last_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 004: Conversations Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    project_id INT NOT NULL,
    title VARCHAR(300) NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    model_used VARCHAR(50) DEFAULT 'claude-sonnet-5',
    total_tokens_input INT DEFAULT 0,
    total_tokens_output INT DEFAULT 0,
    total_cost DECIMAL(10, 6) DEFAULT 0 COMMENT 'Custo estimado em USD',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    metadata JSON NULL COMMENT 'Metadados adicionais da conversa',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_status (project_id, status),
    INDEX idx_uuid (uuid),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 005: Messages Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    conversation_id INT NOT NULL,
    role ENUM('user', 'assistant', 'system', 'tool_use', 'tool_result') NOT NULL,
    content LONGTEXT NOT NULL,
    tool_name VARCHAR(100) NULL COMMENT 'Nome da ferramenta se role=tool_use/tool_result',
    tool_input JSON NULL COMMENT 'Input da ferramenta',
    tool_output LONGTEXT NULL COMMENT 'Output da ferramenta',
    tool_status ENUM('pending', 'running', 'success', 'error') NULL COMMENT 'Status da execução da ferramenta',
    tokens_input INT DEFAULT 0,
    tokens_output INT DEFAULT 0,
    thinking_content LONGTEXT NULL COMMENT 'Conteúdo do thinking do Claude se extended thinking ativo',
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation_role (conversation_id, role),
    INDEX idx_uuid (uuid),
    INDEX idx_created_at (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 006: Generated Files Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS generated_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    conversation_id INT NOT NULL,
    message_id INT NULL,
    file_path VARCHAR(500) NOT NULL COMMENT 'Caminho relativo dentro do sandbox',
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NULL,
    mime_type VARCHAR(100) NULL,
    file_size INT DEFAULT 0,
    content LONGTEXT NULL COMMENT 'Conteúdo do arquivo se texto',
    is_binary BOOLEAN DEFAULT FALSE,
    storage_path VARCHAR(500) NULL COMMENT 'Caminho no storage persistente',
    checksum VARCHAR(64) NULL COMMENT 'SHA256 hash do arquivo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_conversation (conversation_id),
    INDEX idx_uuid (uuid),
    INDEX idx_file_name (file_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 007: Execution Logs Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS execution_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    message_id INT NULL,
    level ENUM('debug', 'info', 'warning', 'error') DEFAULT 'info',
    category VARCHAR(50) DEFAULT 'general',
    message TEXT NOT NULL,
    context JSON NULL,
    duration_ms INT NULL COMMENT 'Duração da operação em milissegundos',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_conversation_level (conversation_id, level),
    INDEX idx_category (category),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 008: User Sessions Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 009: Job Queue Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS job_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    queue VARCHAR(100) NOT NULL DEFAULT 'default',
    job_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    priority INT DEFAULT 0 COMMENT 'Higher = more important',
    available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reserved_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_available (queue, available_at, reserved_at),
    INDEX idx_queue_priority (queue, priority DESC, created_at ASC),
    INDEX idx_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 010: API Tokens Table (for API access)
-- ============================================================================

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    abilities JSON NULL COMMENT 'Permissões do token',
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 011: Usage Statistics Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS usage_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    tokens_input INT DEFAULT 0,
    tokens_output INT DEFAULT 0,
    requests_count INT DEFAULT 0,
    conversations_count INT DEFAULT 0,
    files_generated INT DEFAULT 0,
    estimated_cost DECIMAL(10, 6) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY idx_user_date (user_id, date),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 012: Sandbox Instances Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS sandbox_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    project_id INT NOT NULL,
    container_id VARCHAR(100) NULL,
    container_name VARCHAR(200) NULL,
    status ENUM('creating', 'running', 'stopped', 'error', 'destroyed') DEFAULT 'creating',
    ip_address VARCHAR(45) NULL,
    port INT NULL,
    last_activity_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id),
    INDEX idx_status (status),
    INDEX idx_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 013: System Settings Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) UNIQUE NOT NULL,
    value TEXT NULL,
    type ENUM('string', 'integer', 'float', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 014: Audit Log Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id INT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Create Views
-- ============================================================================

-- User Dashboard Stats View
CREATE OR REPLACE VIEW vw_user_dashboard_stats AS
SELECT 
    u.id as user_id,
    u.uuid as user_uuid,
    COUNT(DISTINCT p.id) as total_projects,
    COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as active_projects,
    COUNT(DISTINCT c.id) as total_conversations,
    COUNT(DISTINCT CASE WHEN c.status = 'completed' THEN c.id END) as completed_conversations,
    COUNT(DISTINCT gf.id) as total_files_generated,
    COALESCE(SUM(c.total_tokens_input), 0) as total_tokens_input,
    COALESCE(SUM(c.total_tokens_output), 0) as total_tokens_output,
    COALESCE(SUM(c.total_cost), 0) as total_cost
FROM users u
LEFT JOIN projects p ON p.user_id = u.id
LEFT JOIN conversations c ON c.project_id = p.id
LEFT JOIN generated_files gf ON gf.conversation_id = c.id
GROUP BY u.id, u.uuid;

-- Project Summary View
CREATE OR REPLACE VIEW vw_project_summary AS
SELECT 
    p.id as project_id,
    p.uuid as project_uuid,
    p.user_id,
    p.name,
    p.status,
    COUNT(DISTINCT c.id) as conversation_count,
    COUNT(DISTINCT CASE WHEN c.status = 'completed' THEN c.id END) as completed_count,
    COUNT(DISTINCT CASE WHEN c.status = 'running' THEN c.id END) as running_count,
    COUNT(DISTINCT gf.id) as files_count,
    COALESCE(SUM(c.total_tokens_input + c.total_tokens_output), 0) as total_tokens,
    MAX(c.updated_at) as last_conversation_at
FROM projects p
LEFT JOIN conversations c ON c.project_id = p.id
LEFT JOIN generated_files gf ON gf.conversation_id = c.id
GROUP BY p.id, p.uuid, p.user_id, p.name, p.status;

-- ============================================================================
-- Create Triggers
-- ============================================================================

-- Update project last_activity_at when conversation is updated
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_update_project_activity
AFTER UPDATE ON conversations
FOR EACH ROW
BEGIN
    UPDATE projects 
    SET last_activity_at = NOW() 
    WHERE id = NEW.project_id;
END//
DELIMITER ;

-- Update user tokens_used when conversation tokens change
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_update_user_tokens
AFTER UPDATE ON conversations
FOR EACH ROW
BEGIN
    DECLARE token_diff INT;
    SET token_diff = (NEW.total_tokens_input + NEW.total_tokens_output) - 
                     (OLD.total_tokens_input + OLD.total_tokens_output);
    
    IF token_diff != 0 THEN
        UPDATE users u
        INNER JOIN projects p ON p.user_id = u.id
        SET u.tokens_used = u.tokens_used + token_diff
        WHERE p.id = NEW.project_id;
    END IF;
END//
DELIMITER ;

-- ============================================================================
-- 015: Project Contexts Table (para ContextManager)
-- ============================================================================

CREATE TABLE IF NOT EXISTS project_contexts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL UNIQUE,
    context_data LONGTEXT NOT NULL COMMENT 'JSON com análise do projeto e cache de sumários',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Insert Default System Settings
-- ============================================================================

INSERT IGNORE INTO system_settings (`key`, value, type, description) VALUES
('app_name', 'Ravi Developer', 'string', 'Application name'),
('app_version', '1.0.0', 'string', 'Application version'),
('maintenance_mode', 'false', 'boolean', 'Enable/disable maintenance mode'),
('default_model', 'claude-sonnet-5', 'string', 'Default Claude model'),
('max_tokens_per_request', '8096', 'integer', 'Maximum tokens per API request'),
('max_agent_iterations', '50', 'integer', 'Maximum agent loop iterations'),
('sandbox_timeout', '1800', 'integer', 'Sandbox timeout in seconds'),
('free_plan_token_limit', '100000', 'integer', 'Token limit for free plan'),
('pro_plan_token_limit', '1000000', 'integer', 'Token limit for pro plan'),
('enterprise_plan_token_limit', '10000000', 'integer', 'Token limit for enterprise plan');

-- ============================================================================
-- Migration Complete
-- ============================================================================

SELECT 'Migration completed successfully!' as status;
