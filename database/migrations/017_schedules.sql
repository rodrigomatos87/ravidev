-- #6 — Ambiente de agendamento por usuário. Dois modos:
--   kind='command' → executa um arquivo/comando do workspace do projeto (no sandbox)
--   kind='claude'  → aciona o Claude com um prompt no projeto
-- Recorrência: 'interval' (a cada N min) ou 'daily' (todo dia em HH:MM). Histórico em schedule_runs.
CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    project_id INT NOT NULL,
    kind VARCHAR(12) NOT NULL DEFAULT 'command',
    title VARCHAR(200) NOT NULL,
    payload TEXT NOT NULL,
    schedule_type VARCHAR(12) NOT NULL DEFAULT 'interval',
    interval_minutes INT NOT NULL DEFAULT 60,
    daily_time VARCHAR(5) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_due (enabled, next_run_at),
    KEY idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS schedule_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'running',
    exit_code INT NULL,
    output MEDIUMTEXT NULL,
    duration_ms INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sched (schedule_id, created_at),
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);
