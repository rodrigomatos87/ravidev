-- Fontes externas (SSH): IPs descobertos do servidor, estilo de coleta de sistemas de monitoramento.
-- Ao testar a conexão, rodamos `ip a` NO SERVIDOR e guardamos TODOS os IPs dele (LAN, VPN/WireGuard
-- 10.x, etc.), separados por vírgula. O `ravi-ssh` usa o `host` como primário e cai para esses IPs
-- como FALLBACK quando o primário não responde — então o IP da VPN entra automaticamente.
-- Aditivo e anulável: linhas antigas ficam NULL e o comportamento continua idêntico (só o host).
ALTER TABLE project_ssh_sources
    ADD COLUMN discovered_ips TEXT NULL AFTER host;
