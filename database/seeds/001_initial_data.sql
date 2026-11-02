-- ============================================================================
-- Ravi Developer - Database Seeds
-- Version: 1.0.0
-- Created: 2025-01-04
-- 
-- Só configurações padrão. Este seed NÃO cria usuários: nenhuma conta nasce com senha
-- conhecida. O primeiro administrador é criado com:
--   php bin/criar-admin.php
-- ============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================================
-- Additional System Settings
-- ============================================================================

INSERT INTO system_settings (`key`, value, type, description) VALUES
('welcome_message', 'Bem-vindo ao Ravi Developer! Comece criando um projeto.', 'string', 'Welcome message for new users'),
('support_email', 'support@ravidev.local', 'string', 'Support email address'),
('analytics_enabled', 'true', 'boolean', 'Enable usage analytics'),
('registration_enabled', 'true', 'boolean', 'Enable new user registration'),
('api_rate_limit', '60', 'integer', 'API requests per minute limit'),
('websocket_heartbeat', '30', 'integer', 'WebSocket heartbeat interval in seconds'),
('file_max_size', '10485760', 'integer', 'Maximum file size in bytes (10MB)'),
('allowed_file_types', '["php","js","ts","py","html","css","json","xml","md","txt","sql","yml","yaml","sh","bash"]', 'json', 'Allowed file extensions')
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============================================================================
-- Seed Complete
-- ============================================================================

SELECT 'Seed concluído' AS status;
