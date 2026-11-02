-- #3 — Ligação entre projetos (ecossistemas diferentes). O projeto A pode ser ligado ao projeto B
-- para que o Claude (no chat e na IDE de A) tenha acesso aos arquivos de B em /linked/<slug>:
--   access = 'read'  → só leitura (entender como B funciona)
--   access = 'write' → leitura e escrita (alterar B também)
-- Só vale enquanto enabled = 1. B continua sendo um SISTEMA SEPARADO.
CREATE TABLE IF NOT EXISTS project_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    linked_project_id INT NOT NULL,
    access VARCHAR(10) NOT NULL DEFAULT 'read',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_project_link (project_id, linked_project_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_project_id) REFERENCES projects(id) ON DELETE CASCADE
);
