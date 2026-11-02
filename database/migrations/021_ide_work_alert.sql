-- Alerta de trabalho longo na IDE (WhatsApp).
-- working_since = início da janela CONTÍNUA em que a sessão está "working".
-- O monitor (bin/ide-alert-monitor.php) seta ao detectar working e zera quando o
-- trabalho termina (idle) ou pausa aguardando o usuário (waiting); se a janela durou
-- mais que o limite do usuário, dispara o WhatsApp nessa transição.
ALTER TABLE ide_sessions
  ADD COLUMN working_since DATETIME NULL DEFAULT NULL COMMENT 'Início da janela contínua de trabalho (working) para o alerta de job longo';
