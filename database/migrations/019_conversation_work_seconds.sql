-- Tempo de trabalho do Dev autonomo acumulado por conversa (plano + quick + build).
-- Somado por projeto na pagina do projeto; exibido ao vivo no topo do chat.
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS work_seconds INT DEFAULT 0;
