-- user_prefs.pref_value passa a MEDIUMTEXT: a casca guarda nela a disposição das janelas
-- (até 60 KB) e as disposições nomeadas (até 120 KB), longe dos 255 caracteres de antes.
-- Sem isso o REPLACE falha e a disposição não volta ao recarregar.
ALTER TABLE user_prefs MODIFY COLUMN pref_value MEDIUMTEXT NULL;
