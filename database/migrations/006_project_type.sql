-- Tipo do projeto: web (padrão), app (mobile Flutter) ou both (web + app).
-- Define o toolchain/sandbox e o preview usados pelo motor.
ALTER TABLE projects
    ADD COLUMN type VARCHAR(10) NOT NULL DEFAULT 'web' AFTER description;
