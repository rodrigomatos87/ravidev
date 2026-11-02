# Módulos opcionais

Cada pasta aqui é um módulo que o sistema carrega se existir e ignora se não existir.
É o mecanismo que separa o que é do produto (vai para o repositório público) do que é só da
operação da Ravi Systems (fica no repositório privado).

## Como funciona

`src/Core/Modulos.php` varre `modulos/*/modulo.php` no bootstrap. Cada `modulo.php` devolve um
manifesto com o que o módulo oferece:

| Chave | O que faz |
|---|---|
| `nome`, `titulo` | identificação |
| `namespace` | classes em `src/` do módulo, carregadas por PSR-4 (ex.: `Ravi\Modulos\Vpn`) |
| `rotas` | rotas públicas, fora de qualquer grupo (sem autenticação) |
| `rotas_auth` | rotas dentro do grupo autenticado (auth + csrf) |
| `rotas_admin` | rotas dentro de `/admin` (auth + csrf + admin) |
| `menu_admin` | itens que aparecem no menu lateral do administrador |
| `views` | pasta de views; usar `$this->view('nome::view')` |
| `migrations` | `*.sql` aplicados pelo `bin/db-init.php` junto com os do núcleo (numeração única) |
| `ganchos` | funções chamadas pelo núcleo em pontos de extensão |

Ganchos existentes:

- `ide.container.env` recebe `['workspace', 'user_id', 'container', 'project_uuid']` e devolve
  `['env' => [...], 'volumes' => [host => destino]]` para o `docker run` da IDE. Uma pasta montada
  em `/etc/ravi/entrypoint.d` tem seus `*.sh` executados no boot do container.
- `ide.container.removido` recebe o nome do container quando ele é descartado.

## Estrutura sugerida

```
modulos/<nome>/
  modulo.php      manifesto
  src/            classes PHP
  views/          templates
  migrations/     *.sql
  public/         front controller próprio, se o módulo tiver vhost dedicado
  bin/, docker/   scripts e units de systemd do host
```

## Módulos só da Ravi Systems (não vão para o público)

- `vpn`: VPN de suporte da casa (concentrador WireGuard, API interna, kill switch no painel).
- `ioncube`: comando `ioncube-proteger` nas IDEs, com o encoder licenciado no host.

A lista do que fica de fora da exportação está em `bin/exportar-publico.sh`.
