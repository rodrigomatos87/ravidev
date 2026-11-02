# CLAUDE.md

Guia para quem (pessoa ou agente) mexe no código do Ravi Developer. Visão do produto e
instalação estão no [README.md](README.md); fluxos e tabelas em
[docs/arquitetura.md](docs/arquitetura.md).

## Mapa do código

```
public/index.php        front controller do painel (nginx aponta a raiz para public/)
public/ide-auth.php     auth_request do nginx para /ide/<token>/
gateway-public/         front isolado do Gateway de API (vhost próprio)
src/bootstrap.php       .env, constantes *_PATH, configs, sessão, cabeçalhos, CSRF, módulos
src/routes.php          todas as rotas do núcleo; as dos módulos entram no fim
src/Core/               Application, Router, Request, Response, JsonResponse, View, Database,
                        Session, Validator, Encryption, Logger, Lang, Modulos
src/Controllers/        um controller por área; base em Controller.php
src/Models/             Active Record (base Model.php + QueryBuilder)
src/Services/           regra de negócio (IdeService, MaestroService, LicenseService...)
src/Gateway/            Gateway de API (controller, serviço, backends, semáforo)
src/Agent/              ferramentas e agentes legados, SpecialistRegistry do Maestro
src/WebSocket/          servidor Ratchet do chat (ChatHandler, ConnectionManager)
src/Middleware/         AuthMiddleware, CsrfMiddleware, AdminMiddleware
src/Helpers/            funções globais (env, config, t, e, url, uuid, formatação)
views/                  templates PHP; layouts em views/layouts/{painel,auth,chat}.php
public/assets/d2/       design system d2 (css, js, ícones, fontes, idiomas do cliente)
public/assets/js/paginas/   um módulo ES por tela
public/assets/css/paginas/  CSS específico de tela
lang/                   textos do servidor em JSON (t('chave', 'padrão'))
config/                 arquivos PHP que leem o .env
database/migrations/    SQL numerado; database/seeds/
bin/                    CLI: db-init, criar-admin, queue-worker, scheduler, websocket-server,
                        proxies Python, limpezas, exportar-publico e varrer-segredos
docker/                 Dockerfiles das imagens, modelos de nginx e de systemd
modulos/                módulos opcionais (ver modulos/README.md)
tests/Unitarios/        testes PHPUnit
```

### Rotas e middlewares

`src/routes.php` usa `$router->get/post/put/patch/delete` e `group(['prefix' => ..., 'middleware'
=> [...]])`. Os apelidos são `auth`, `csrf` e `admin`. Parâmetros entram como `{uuid}` e chegam
ao método do controller na ordem. Todo POST de formulário passa por `csrf` (campo `_csrf_token`
ou cabeçalho `X-CSRF-TOKEN`).

### Controllers

Herdam de `Ravi\Controllers\Controller`: `view()`, `json()`, `jsonError()`, `redirect()`,
`back()`, `redirectWithSuccess()`, `redirectWithError()`, `validate()`, `abort()`,
`wantsJson()`. O controller busca os dados e entrega prontos para a view.

### Models

Active Record em `src/Models/Model.php`: `find`, `findBy`, `where(...)->get()`, `whereIn`,
`orderBy`, `create`, `save`, `update`, `delete`, `toArray`. Cada model declara `$table`,
`$fillable`, `$hidden` e `$casts`. Para SQL direto use `Database::getInstance()` com
parâmetros (`query`, `fetch`, `fetchAll`, `fetchColumn`, `insert`, `update`, `transaction`),
nunca concatenando entrada.

### Views

`src/Core/View.php`: `$this->layout('layouts/painel', [...])`, `section`/`endSection`/`yield`,
`include`, `component` e `e()` para escapar. O nome `modulo::tela` resolve para
`modulos/modulo/views/tela.php`.

O layout do painel recebe `title`, `currentPage`, `area`, `subtitulo`, `acoes`,
`hidePageTitle`, `scripts` (módulos ES da página) e `estilos`. O menu vem pronto de
`MenuService::montar()`.

## Convenções

- Textos, comentários, commits e documentação em português do Brasil.
- Identificadores novos em português (`montarMenu`, `$projetos`). Código antigo em inglês
  continua como está; não renomeie sem motivo.
- Sem travessão em texto nenhum (tela, comentário, commit, doc): use vírgula, ponto ou
  dois-pontos.
- `declare(strict_types=1);` e PSR-12 (`composer estilo`).
- Texto de tela nunca nasce fixo no PHP nem no JS: servidor usa `t('chave', 'padrão')` com
  `lang/*.json`; cliente usa as chaves de `public/assets/d2/idiomas/{pt,en,es}.json`.
- Segredo nunca no código nem em log. Credenciais de terceiros ficam cifradas no banco com
  `Encryption` (AES-256-GCM, chave `APP_KEY`).

## Regras de interface (design system d2)

- Toda tela nova usa o layout `layouts/painel` e as classes `d2-*`. Cores só pelas variáveis
  `--d2-*`; teste o tema claro e o escuro.
- Nada de `<style>` nem `<script>` com código dentro das views. CSS vai para
  `public/assets/css/paginas/`, JS para `public/assets/js/paginas/` (módulo ES), e os dados que
  o JS precisa vão num `<script type="application/json" id="dados-pagina">` gerado com
  `json_encode` e as flags `JSON_HEX_*`.
- Nada de `alert`, `confirm` ou `prompt`: use `D2UI.modal`, `D2UI.confirmar` e `D2UI.toast`
  (`public/assets/d2/js/d2ui.js`, exposto também em `window.D2UI`).
- Dica de botão por `data-tooltip` (o d2 desenha o `.d2-floattip`), nunca `title`.
- Dado de usuário no DOM só por `textContent`, nunca `innerHTML`.
- Toda lista vazia mostra um estado vazio com a ação que resolve (ex.: "Criar projeto"),
  pelo `D2UI.vazio`.
- Toda espera tem retorno visual (botão em carregamento, barra, texto de progresso).
- A tela não expõe tecnologia interna: nada de nome de container, porta, caminho de disco,
  stack trace ou comando de shell para o usuário rodar.
- Nenhuma consulta ao banco dentro da view. A view só formata o que o controller entregou.

## Rodar localmente

```bash
composer install
cp .env.example .env              # preencha DB_* e gere a APP_KEY (ver README)
mysql -e "CREATE DATABASE ravi_developer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php bin/db-init.php               # migrations + seeds
php bin/criar-admin.php           # primeiro administrador
composer start                    # http://localhost:8080
composer ws                       # WebSocket do chat, em outro terminal
php bin/queue-worker.php          # tarefas do chat, em outro terminal
```

Sem Docker, o painel, o login, os usuários, as licenças e o Gateway (tela) funcionam; IDE,
preview e Dev Autônomo precisam do Docker e das imagens (ver docs/instalacao.md).

## Testes e verificação

```bash
composer verificar    # lint + phpunit + phpstan (o mesmo que o CI roda)
composer test         # só phpunit
vendor/bin/phpunit --filter RouterTest
```

`phpunit.xml` aponta para `tests/Unitarios` (Core, Helpers, Middleware, Scripts, com dublês em
`Apoio/`) e usa `tests/bootstrap.php` com `APP_ENV=testing`. Os testes não usam banco. O
phpstan roda no nível 5 com `phpstan-baseline.neon`: código novo não entra na baseline.

## Migrations

- Arquivos `NNN_descricao.sql` em `database/migrations/` e em `modulos/*/migrations/`.
- A numeração é **única** entre núcleo e módulos (a 029 e a 034 são de módulos). Antes de
  criar, liste as duas pastas e pegue o próximo número livre.
- `bin/db-init.php` mescla as duas fontes, ordena pelo nome e registra cada arquivo aplicado
  em `schema_migrations`. Opções: `--migrate`, `--seed`, `--status`, `--fresh` (com
  `--force`), `--marcar-aplicadas[=ARQ]`.
- Migration nova deve rodar em banco vazio e em banco existente (`IF NOT EXISTS`,
  `ADD COLUMN IF NOT EXISTS`). Só MariaDB 10.6+.
- Nunca edite uma migration já publicada: crie outra.

## Criar um módulo opcional

1. Crie `modulos/<nome>/modulo.php` devolvendo o manifesto (`nome`, `titulo`, `namespace`,
   `rotas`, `rotas_auth`, `rotas_admin`, `menu_admin`, `views`, `migrations`, `ganchos`).
2. Classes em `modulos/<nome>/src/`, carregadas por PSR-4 pelo `namespace` declarado.
3. Views em `modulos/<nome>/views/`, chamadas como `$this->view('<nome>::tela')`.
4. Migrations em `modulos/<nome>/migrations/`, com número livre na sequência global.
5. Ganchos disponíveis: `ide.container.env` e `ide.container.removido`.
6. O núcleo nunca referencia o módulo por nome: tudo passa por `Ravi\Core\Modulos`.

Detalhes e exemplo em `modulos/README.md`; o teste `tests/Unitarios/Core/ModulosTest.php`
mostra o contrato.

## Segredos e versão pública

- `bin/varrer-segredos.sh <pasta>` procura chaves, tokens, IPs internos e nomes que não podem
  sair. Falha com código diferente de zero.
- `bin/exportar-publico.sh <destino>` copia só os arquivos versionados, tira o que é privado
  (módulos privados, `privado/`, configurações locais de editor) e roda a varredura.
- O CI roda os dois em todo push. Se a varredura acusar, corrija o arquivo: não amplie a
  lista de exceções para fazer passar.
- Nunca versione `.env`, dumps, chaves SSH, `auth.json` ou arquivos de `storage/`.
