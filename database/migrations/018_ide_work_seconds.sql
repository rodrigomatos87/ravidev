-- Tempo trabalhado na IDE por membro/projeto (acumulado pelo amostrador bin/ide-worktime.php
-- enquanto o sensor do Claude estiver em "working"). Usado no card de eficiência da equipe.
CREATE TABLE IF NOT EXISTS ide_work_seconds (
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    seconds INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
