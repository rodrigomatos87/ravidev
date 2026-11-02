-- Compartilhamento de projeto: membros além do dono. Acesso TOTAL (trabalham juntos);
-- o projeto aparece na conta de cada membro como se fosse dele e todos veem os chats.
CREATE TABLE IF NOT EXISTS project_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'editor',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member (project_id, user_id),
    INDEX idx_pm_user (user_id),
    INDEX idx_pm_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
