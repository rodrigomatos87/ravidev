<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Agent\SpecialistRegistry;
use Ravi\Models\Conversation;
use Ravi\Models\Project;

/**
 * Maestro, o "cérebro" autônomo do Ravi Developer.
 *
 * Em vez de uma única chamada ao Claude Code (que para quando ELE acha que terminou),
 * o Maestro dirige várias invocações em fases com PORTÕES verificados de forma independente,
 * sem interação humana após a aprovação do plano:
 *
 *   1. PLANO     → gera /workspace/PLAN.md e aguarda aprovação (único ponto de interação)
 *   2. EXECUÇÃO  → loop autônomo: build → test → security → deliver
 *                  cada fase roda até o portão (verificador cético) ficar verde;
 *                  se falhar, devolve os detalhes e tenta de novo.
 *
 * Estado por conversa em /workspace/.maestro/conv-{id}.json (sobrevive entre jobs).
 * Cada fase é uma invocação stateless que lê PLAN.md + PROGRESS.md + os arquivos do projeto —
 * isso evita estourar a janela de contexto em projetos enormes.
 */
class MaestroService
{
    /** @var callable|null */
    private $onEvent = null;

    private ClaudeCodeService $cc;
    private int $maxAttempts;

    /** Especialista ativo ({key,name,initial,color}); carimbado nos eventos próprios do Maestro. */
    private ?array $activeAgent = null;
    /** Session-id do Arquiteto VIVO; os agentes forkam dele para herdar o contexto inteiro. */
    private string $arqSid = '';
    /** Instante (epoch) em que esta passada de TRABALHO comecou (plano/quick/build); 0 = conversa pura. */
    private int $buildStartedAt = 0;

    /** Conversa em processamento (para titular ao começar o trabalho). */
    private ?Conversation $conv = null;

    /** Usuário que disparou a tarefa (a quem atribuir o custo no histórico). */
    private int $actingUserId = 0;

    /** Tipo do projeto (web|app|both). Ramifica diretivas (web inalterado; app/both = RN/Expo). */
    private string $projectType = 'web';

    public function __construct(SandboxService $sandbox)
    {
        $this->cc = new ClaudeCodeService($sandbox);
        $this->maxAttempts = max(1, (int) env('MAESTRO_MAX_ATTEMPTS', 3));
    }

    public function onUpdate(callable $cb): void
    {
        $this->onEvent = $cb;
        $this->cc->onUpdate($cb); // os eventos do Claude Code fluem pelo mesmo canal
    }

    /** Define o usuário que disparou a tarefa (a quem o custo é atribuído no histórico). */
    public function setActingUser(int $userId): void
    {
        $this->actingUserId = $userId;
        // Define o acesso ao contexto (memórias/servidores) na injeção do Claude Code.
        $this->cc->actingUserId = $userId;
    }

    /** Projeto mobile (RN/Expo)? Ramifica plano/finalização/verificação; web é o default. */
    private function isMobile(): bool
    {
        return $this->projectType === 'app' || $this->projectType === 'both';
    }

    /** Especialista com a persona da PLATAFORMA do projeto (web por padrão; RN/Expo se mobile). */
    private function spec(string $key): ?array
    {
        return SpecialistRegistry::get($key, $this->projectType);
    }

    private function emit(array $data): void
    {
        if ($this->onEvent) {
            // Carimba quem está falando (especialista ativo) se o evento ainda não trouxer.
            if ($this->activeAgent !== null && !isset($data['agent'])) {
                $data['agent'] = $this->activeAgent;
            }
            ($this->onEvent)($data);
        }
    }

    /** Define o especialista ativo: carimba os eventos do Maestro e os do Claude Code. */
    private function setActiveAgent(string $key): array
    {
        $this->activeAgent = SpecialistRegistry::ui($key);
        return $this->activeAgent;
    }

    /**
     * Ponto de entrada. Decide entre planejar, executar (após aprovação) ou re-planejar.
     * Envolve runTask para SEMPRE persistir o uso (tokens/custo) ao final, inclusive em erro.
     */
    public function executeTask(Conversation $conversation, string $message): array
    {
        try {
            return $this->runTask($conversation, $message);
        } finally {
            $this->persistUsage($conversation);
        }
    }

    /**
     * Gera, uma única vez por conversa, um título curto pela IA (depois de entender o pedido).
     * Detecta "ainda sem título de verdade" sem flag: o título atual é vazio, "Nova conversa",
     * ou exatamente o prefixo da 1ª mensagem (placeholder que o ChatHandler grava). Se o usuário
     * já renomeou manualmente, o título não bate com o prefixo → respeitamos e não sobrescrevemos.
     */
    private function maybeTitle(string $message): void
    {
        $conversation = $this->conv;
        $message = trim($message);
        if ($conversation === null || $message === '') {
            return;
        }
        $cur       = trim((string) $conversation->title);
        $msgPrefix = mb_substr($message, 0, 60);
        $isPlaceholder = ($cur === '' || $cur === 'Nova conversa' || $cur === 'Nova Conversa' || $cur === $msgPrefix);
        if (!$isPlaceholder) {
            return; // já tem título (gerado pela IA antes, ou renomeado pelo usuário)
        }

        $project = Project::find($conversation->project_id);
        if (!$project) {
            return;
        }
        $ws = $this->cc->ensureWorkspace($project->uuid);

        try {
            $prompt = "Gere um TÍTULO curto (3 a 5 palavras, em português, sem aspas e sem ponto final) que resuma o "
                . "objetivo desta conversa de desenvolvimento. Responda APENAS o título, nada mais.\n\nPedido do usuário:\n\"\"\"\n"
                . mb_substr($message, 0, 600) . "\n\"\"\"";
            $res = $this->cc->run($ws, $project->uuid, $prompt, [
                'quiet'  => true,
                'system' => 'Você gera títulos curtos e claros para conversas. Responda somente o título, sem comentários.',
            ]);
            $title = trim((string) ($res['summary'] ?? ''));
            $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
            $title = trim($title, " \t\n\r\0\x0B\"'`.…");
            $title = mb_substr($title, 0, 60);
            if ($title === '') {
                return;
            }
            $conversation->update(['title' => $title]);
            $this->emit(['type' => 'title', 'title' => $title, 'conversation' => $conversation->uuid]);
        } catch (\Throwable $e) {
            // Título é best-effort: nunca derruba o trabalho.
        }
    }

    /**
     * Lógica de fato: planejar, executar (após aprovação) ou re-planejar.
     */
    /**
     * Prepara a sessao VIVA do Arquiteto: dir de transcripts (host, persistente) + session-id.
     * doPlan estabelece (sid novo); runAutonomous retoma (sid salvo no estado). Os agentes forkam dela.
     */
    private function setupArqSession(?string $sid = null): void
    {
        try {
            $cid = (int) ($this->conv->id ?? 0);
            $dir = STORAGE_PATH . '/claude-sessions/' . $cid;
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
            $this->cc->sessionDir = $dir;
            $this->arqSid = ($sid !== null && $sid !== '') ? $sid : uuid();
        } catch (\Throwable $e) {
            $this->arqSid = '';
        }
    }

    private function runTask(Conversation $conversation, string $message): array
    {
        $this->conv = $conversation;
        $project = Project::find($conversation->project_id);
        if (!$project) {
            throw new \RuntimeException("Projeto não encontrado para a conversa {$conversation->id}");
        }

        // Motor escolhe a imagem do sandbox pelo TIPO do projeto: app/both → ravi-sandbox-mobile
        // (RN/Expo + Android SDK); web (default) → imagem padrão, sem nenhuma mudança de comportamento.
        $ptype = strtolower(trim((string) ($project->type ?? 'web')));
        $this->projectType = ($ptype === 'app' || $ptype === 'both') ? $ptype : 'web';
        $this->cc->selectImageForType($this->projectType);

        $this->setActiveAgent('arquiteto'); // o ponto de entrada é sempre o Arquiteto

        $ws        = $this->cc->ensureWorkspace($project->uuid);
        $this->ensureMaestroDir($ws); // 0777 para o container (uid sandbox) gravar o marcador de conclusão
        $stateFile = $ws . '/.maestro/conv-' . $conversation->id . '.json';
        $state     = $this->loadState($stateFile);
        $phase     = $state['phase'] ?? 'new';

        // RESILIÊNCIA: o worker pode ter reiniciado no meio de um job longo (estado ficou 'running').
        // Ao reprocessar, RETOMA de onde parou em vez de recomeçar. Trabalho 'full' relê PLAN.md/
        // PROGRESS.md e continua as ondas; 'quick' é curto e barato de refazer (cai no fluxo normal).
        if ($phase === 'running' && !$this->cancelled($ws)) {
            $mode = $state['mode'] ?? (is_file($ws . '/PLAN.md') ? 'full' : 'quick');
            if ($mode === 'full') {
                $this->emit(['type' => 'status', 'message' => 'Retomando o trabalho de onde parei…']);
                return $this->runAutonomous($ws, $project->uuid, $stateFile, $state);
            }
            // quick → segue para refazer a tarefa pequena abaixo
        }

        // FLUXO CANONICO (sem pular etapas): se aprovou um plano -> desenvolve; senao o Arquiteto
        // CONVERSA para entender OU propoe um PLANO para aprovacao. Nada e construido nem renomeado
        // antes de um plano aprovado.
        if ($phase === 'awaiting_approval' && $this->isApproval($message)) {
            return $this->runAutonomous($ws, $project->uuid, $stateFile, $state);
        }

        // Pedido EXPLICITO de revalidar o QA visual num projeto web ja entregue: abre o navegador agora.
        if (!$this->isMobile() && $this->isVisualQaRequest($message) && $this->hasWebUi($ws)) {
            return $this->runVisualQaOnDemand($ws, $project->uuid);
        }

        // POS-ENTREGA: se o projeto JA foi entregue e temos a sessao do Arquiteto, ele CONTINUA
        // VIVO e decide+age sobre o follow-up (aplica o ajuste, responde, chama o necessario, ou so
        // propoe plano se for um pedido NOVO e grande) — sem recomecar do zero nem re-planejar um detalhe.
        if (is_file($ws . '/.maestro/DELIVERY_COMPLETE') && !empty($state['arq_sid'])) {
            return $this->runFollowup($ws, $project->uuid, $stateFile, $state, $message);
        }

        // O Arquiteto (Claude natural, com TODO o contexto da conversa) decide: conversar para
        // entender (responder/perguntar) OU, ja entendendo o objetivo, propor um plano para aprovacao.
        $decision = $this->decideEntry($ws, $project->uuid, $message);
        if (($decision['mode'] ?? 'talk') === 'plan') {
            // Se havia um plano aguardando (reprovado/ajuste), doPlan incorpora o feedback e reapresenta.
            $prev = (($state['phase'] ?? '') === 'awaiting_approval') ? $state : null;
            return $this->doPlan($ws, $project->uuid, $stateFile, $message, $prev);
        }
        // Conversa: responde naturalmente (pode perguntar). Sem renomear, sem construir, sem banner.
        return $this->runConversational($ws, $project->uuid, $stateFile, $state ?? [], $message, (string) ($decision['reply'] ?? ''));
    }

    /** Lê e ESVAZIA a inbox de steering desta conversa (recados enviados DURANTE o build). */
    private function readInbox(string $ws): string
    {
        if (!$this->conv) {
            return '';
        }
        $file = $ws . '/.maestro/inbox-' . (int) $this->conv->id . '.jsonl';
        if (!is_file($file)) {
            return '';
        }
        $raw = (string) @file_get_contents($file);
        @unlink($file);
        $msgs = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $d = json_decode($line, true);
            $t = is_array($d) ? trim((string) ($d['text'] ?? '')) : '';
            if ($t !== '') {
                $msgs[] = $t;
            }
        }
        return implode("\n", $msgs);
    }

    /**
     * Persiste o uso desta tarefa: calcula o CUSTO REAL (com cache), soma na conversa,
     * grava uma linha no HISTÓRICO (ledger) atribuída ao usuário que executou, e emite
     * o evento 'usage' para a UI. Roda no finally do executeTask (todos os caminhos + erro).
     */
    private function persistUsage(Conversation $conversation): void
    {
        try {
            // Tempo do Dev: contado por SEGUNDO pelo servidor WS (tickWorkSeconds) enquanto a
            // conversa esta 'running', persistido no banco a cada segundo (nunca perde).
            $u = $this->cc->consumeUsage();
            if ((int) ($u['runs'] ?? 0) === 0) {
                return; // nenhuma invocação do Claude Code nesta passada
            }
            $in = (int) $u['input']; $cw = (int) $u['cache_write']; $cr = (int) $u['cache_read']; $out = (int) $u['output'];
            $cost      = realApiCost($in, $cw, $cr, $out);   // custo real com cache
            $totalSent = $in + $cw + $cr;                    // tokens de envio (inclui cache)

            $conversation->addUsage($totalSent, $out, $cost);

            // Histórico por usuário/projeto/conversa (para relatórios e gestão da equipe).
            $project = Project::find($conversation->project_id);
            $userId  = $this->actingUserId ?: (int) ($project->user_id ?? 0);
            \Ravi\Models\UsageLedger::record(
                $userId,
                $project->id ?? null,
                (int) $conversation->id,
                \Ravi\Services\ModelService::forUser($userId, 'autonomous'),
                $in, $cw, $cr, $out, $cost
            );

            $this->emit([
                'type'   => 'usage',
                'input'  => (int) ($conversation->total_tokens_input ?? 0),
                'output' => (int) ($conversation->total_tokens_output ?? 0),
                'cost'   => round((float) ($conversation->total_cost ?? 0), 6), // custo real acumulado
            ]);
        } catch (\Throwable $e) {
            // Métrica não pode derrubar a tarefa.
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CAMINHO RÁPIDO, ajustes pequenos resolvem direto, sem plano nem aprovação
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Triagem barata: o Arquiteto classifica o pedido como 'quick' (pequeno/localizado)
     * ou 'full' (substancial → plano+aprovação). Na dúvida, 'full'.
     */
    /**
     * DECISAO DE ENTRADA (Claude natural, com todo o contexto da conversa): ou CONVERSA para
     * entender (responde/pergunta) ou ja tem contexto para propor um PLANO. Nao existe execucao
     * sem plano aprovado.
     * @return array{mode:string, reply?:string}
     */
    private function decideEntry(string $ws, string $uuid, string $message): array
    {
        $prompt = $this->convContext()
            . "Mensagem atual do usuário:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "Explore o MÍNIMO necessário em /workspace (pode estar vazio ou já ter um projeto). Você conversa "
            . "com o usuário para entender o que ele quer construir ou ajustar. Decida UMA coisa:\n"
            . "- 'talk': ainda é conversa (saudação/pergunta) OU você ainda NÃO entende o objetivo o suficiente "
            . "para propor um plano concreto. Escreva em 'reply' uma resposta natural e breve em português; se "
            . "faltar informação essencial, faça as perguntas necessárias (sem perguntas desnecessárias). NÃO se "
            . "apresente, NÃO cite marca, NÃO diga que entregou nada.\n"
            . "- 'plan': você JÁ entende o objetivo o suficiente para propor um plano de ação/projeto concreto "
            . "(não escreva o plano agora, só sinalize).\n"
            . "Regra: vá para 'plan' assim que o objetivo estiver claro o bastante para agir sem adivinhar errado; "
            . "senão fique em 'talk' e pergunte. Nada é construído antes de um plano aprovado.\n\n"
            . "Responda EXCLUSIVAMENTE com JSON: {\"mode\":\"talk|plan\",\"reply\":\"<só se talk>\"}";
        try {
            $res = $this->cc->run($ws, $uuid, $prompt, [
                'quiet'  => true,
                'system' => 'Você é um engenheiro de software sênior conversando com o usuário para entender o que '
                    . 'construir. Natural e direto, em português do Brasil, sem se apresentar e sem citar marca. '
                    . 'Você só responde com JSON válido.',
            ]);
            $parsed = $this->parseJson((string) ($res['summary'] ?? ''));
            if (is_array($parsed)) {
                $mode = strtolower(trim((string) ($parsed['mode'] ?? '')));
                if ($mode === 'plan') {
                    return ['mode' => 'plan'];
                }
                if ($mode === 'talk') {
                    return ['mode' => 'talk', 'reply' => trim((string) ($parsed['reply'] ?? ''))];
                }
            }
        } catch (\Throwable $e) {
            // falhou a decisao -> conversa (seguro: nunca constroi sem entender)
        }
        return ['mode' => 'talk', 'reply' => ''];
    }

    private function triage(string $ws, string $uuid, string $message): array
    {
        $prompt = $this->convContext()
            . "Você é o ARQUITETO/ENGENHEIRO de software. Explore o MÍNIMO necessário em /workspace "
            . "(pode estar VAZIO, ou já ter um projeto entregue) e analise o PEDIDO do usuário:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "Decida, com bom senso de engenheiro, se isto precisa de CERIMÔNIA (plano formal + aprovação + vários "
            . "especialistas/ondas) ou se você resolve DIRETO:\n"
            . "- 'talk': a mensagem NAO pede para criar nem alterar software (saudacao, agradecimento, conversa, "
            . "pergunta sobre o que voce faz, ou algo vago/sem instrucao acionavel). NAO construa nada.\n"
            . "- 'quick': tarefa PEQUENA e de baixo risco que um engenheiro faz direto, sem cerimônia, trocar um texto/"
            . "rótulo, ajustar um estilo/cor, renomear, mudar uma config, um util/função pequeno, um script simples, uma correção pontual.\n"
            . "- 'full': trabalho SUBSTANCIAL, um sistema/app, várias telas/endpoints/camadas, algo que realmente se "
            . "beneficia de plano, de especialistas (ex.: o de design pra UI caprichada) e/ou de paralelismo.\n"
            . "Princípio: a cerimônia só vale quando AJUDA (qualidade ou velocidade). Se atrapalha mais do que ajuda, é 'quick'. "
            . "Entre os dois, na dúvida real, escolha 'full'.\n\n"
            . "Se 'quick', diga TAMBÉM qual ESPECIALISTA deve executar (campo 'specialist'), escolha o MAIS QUALIFICADO, "
            . "mesmo sendo tarefa pequena, para sair com QUALIDADE:\n"
            . "  - UI / formulário / layout / estilo / componente visual → 'frontend' (domina design e acessibilidade)\n"
            . "  - lógica / API / regra de negócio → 'backend'    - schema / SQL / migração → 'banco'\n"
            . "  - testes → 'qa'    - revisão de segurança → 'seguranca'\n"
            . "  - 'arquiteto' SÓ quando for trivial e NENHUM especialista agrega (ex.: trocar um texto, mudar uma config simples).\n"
            . "Escreva uma DIRETIVA precisa: exatamente o que fazer e em qual(is) arquivo(s), sem tocar no resto.\n\n"
            . "Se 'talk', escreva no campo 'reply' uma resposta NATURAL e curta em portugues (1 a 2 frases): "
            . "cumprimente e pergunte, de forma simples, o que a pessoa quer construir ou ajustar. NAO se apresente, "
            . "NAO diga quem voce e (nada de 'sou o engenheiro/o arquiteto/o time'), NAO cite nome de produto ou "
            . "marca, NAO diga que entregou ou concluiu nada. So uma resposta humana e direta.\n\n"
            . "Responda EXCLUSIVAMENTE com JSON: {\"mode\":\"talk|quick|full\",\"specialist\":\"frontend|backend|banco|qa|seguranca|arquiteto\",\"directive\":\"...\",\"reply\":\"...\",\"reason\":\"curto\"}";

        try {
            $res    = $this->cc->run($ws, $uuid, $prompt, [
                'quiet'  => true,
                'system' => 'Você é um engenheiro de software sênior, direto e natural. Responda em português do Brasil. Você só responde com JSON válido.',
            ]);
            $parsed = $this->parseJson((string) ($res['summary'] ?? ''));
            if (is_array($parsed) && ($parsed['mode'] ?? '') === 'talk') {
                return ['mode' => 'talk', 'reply' => trim((string) ($parsed['reply'] ?? ''))];
            }
            if (is_array($parsed) && ($parsed['mode'] ?? '') === 'quick' && trim((string) ($parsed['directive'] ?? '')) !== '') {
                $spec = strtolower(trim((string) ($parsed['specialist'] ?? 'arquiteto')));
                if ($spec !== 'arquiteto' && SpecialistRegistry::get($spec) === null) {
                    $spec = 'arquiteto';
                }
                return ['mode' => 'quick', 'specialist' => $spec, 'directive' => (string) $parsed['directive']];
            }
        } catch (\Throwable $e) {
            // Falhou a triagem → segue pelo caminho seguro (plano).
        }
        return ['mode' => 'full'];
    }

    /**
     * Contexto da conversa para o arquiteto saber DO QUE se trata e EM QUE PONTO estamos.
     * Injeta SÓ o que é sinal: as mensagens do USUÁRIO (intenção, correções, respostas) e as
     * DEVOLUTIVAS substanciais da equipe (resumos de entrega/onda). Ignora a narração curta
     * dos agentes, o estado real do projeto já está nos arquivos do /workspace. Exclui a
     * mensagem ATUAL (já vai no prompt). Vazio quando é o 1º pedido da conversa.
     */
    private function convContext(): string
    {
        if (!$this->conv) {
            return '';
        }
        try {
            $msgs = $this->conv->messages();
        } catch (\Throwable $e) {
            return '';
        }
        if (!is_array($msgs) || count($msgs) < 2) {
            return '';
        }
        array_pop($msgs); // remove a mensagem atual (ja vai no prompt)

        $lines = [];
        foreach ($msgs as $m) {
            $role    = (string) ($m->role ?? '');
            $content = trim((string) ($m->content ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'user') {
                $lines[] = 'USUÁRIO: ' . $content;
            } elseif ($role === 'assistant') {
                $lines[] = 'ARQUITETO: ' . (mb_strlen($content) > 800 ? mb_substr($content, 0, 800) . '...' : $content);
            }
        }
        if ($lines === []) {
            return '';
        }
        $text = implode("\n\n", $lines);
        if (mb_strlen($text) > 6000) {
            $text = "...(início da conversa omitido)...\n\n" . mb_substr($text, -6000);
        }
        return "CONVERSA ATÉ AQUI (todo o histórico desta conversa, para você ter continuidade como numa "
            . "sessão; o estado real do projeto está nos arquivos do /workspace):\n\"\"\"\n{$text}\n\"\"\"\n\n";
    }

    /**
     * ANTES de planejar: o arquiteto decide se o pedido está claro ou se há AMBIGUIDADE/LACUNA
     * decisiva que, adivinhada errado, faria entregar a coisa errada (ex.: 'cadastro de clientes'
     * = signup de usuário OU CRUD de gestão?). Se claro, NÃO pergunta. Estilo VSCode: perguntas
     * abertas, de escolha única ou múltipla. Retorna a lista de perguntas, ou [] se não precisa.
     */
    private function maybeClarify(string $ws, string $uuid, string $message): array
    {
        $prompt = $this->convContext()
            . "Avalie este PEDIDO de software ANTES de planejar:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "Sua ÚNICA tarefa agora: detectar AMBIGUIDADES DECISIVAS, pontos em que duas interpretações "
            . "razoáveis levariam a ENTREGAS DIFERENTES. Para cada uma, faça uma pergunta curta e objetiva (no "
            . "máx. 3, ideal 1-2). Se o pedido for inequívoco e proporcional, NÃO pergunte (needed=false).\n\n"
            . "VOCÊ DEVE perguntar quando houver casos como:\n"
            . "- \"cadastro de clientes\" / \"cadastro de X\": é o PRÓPRIO usuário criando a conta dele (signup/login), "
            . "OU um PAINEL para um operador GERENCIAR clientes (listar, criar, editar, excluir)? São sistemas DIFERENTES, pergunte.\n"
            . "- escopo vago (\"completo\", \"igual o X\"), entidade sem campos definidos, quem USA o sistema (público × interno/admin), "
            . "integrações/pagamento/login social não especificados, ou qualquer suposição que mudaria o que será entregue.\n\n"
            . "Tipos de pergunta: \"single\" (escolher 1 opção), \"multi\" (escolher várias), \"open\" (texto livre, sem opções). "
            . "Para single/multi, dê 2-4 opções CONCRETAS, cada uma com 'label' curto + 'description' de 1 linha.\n\n"
            . "Responda EXCLUSIVAMENTE com JSON válido:\n"
            . "{\"needed\":true|false,\"questions\":[{\"header\":\"≤2 palavras\",\"question\":\"a pergunta\",\"type\":\"single|multi|open\",\"options\":[{\"label\":\"...\",\"description\":\"...\"}]}]}\n"
            . "Se needed=false, \"questions\" deve ser []. Para type=open, \"options\" deve ser [].";

        try {
            $res = $this->cc->run($ws, $uuid, $prompt, [
                'quiet'  => true,
                'system' => 'Você é um analista de requisitos de software RIGOROSO em detectar ambiguidade, '
                    . 'pega interpretações divergentes ANTES de um time gastar horas construindo a coisa errada. '
                    . 'Não é tímido em perguntar quando o escopo é dúbio, mas também não pergunta o óbvio. '
                    . 'Você SÓ responde com JSON válido, nada mais.',
            ]);
            $parsed = $this->parseJson((string) ($res['summary'] ?? ''));
            if (!is_array($parsed) || empty($parsed['needed']) || empty($parsed['questions']) || !is_array($parsed['questions'])) {
                return [];
            }
            return $this->sanitizeQuestions($parsed['questions']);
        } catch (\Throwable $e) {
            return []; // falha → segue direto pro plano (comportamento atual, sem travar)
        }
    }

    /** Normaliza/valida as perguntas vindas do modelo (defensivo para a UI). Máx. 3 perguntas. */
    private function sanitizeQuestions(array $raw): array
    {
        $out = [];
        foreach ($raw as $q) {
            if (!is_array($q)) {
                continue;
            }
            $question = trim((string) ($q['question'] ?? ''));
            if ($question === '') {
                continue;
            }
            $type = strtolower(trim((string) ($q['type'] ?? 'single')));
            if (!in_array($type, ['single', 'multi', 'open'], true)) {
                $type = 'single';
            }
            $header  = trim((string) ($q['header'] ?? '')) ?: 'Pergunta';
            $options = [];
            if ($type !== 'open' && !empty($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $opt) {
                    if (is_array($opt)) {
                        $label = trim((string) ($opt['label'] ?? ''));
                        $desc  = trim((string) ($opt['description'] ?? ''));
                    } else {
                        $label = trim((string) $opt);
                        $desc  = '';
                    }
                    if ($label !== '') {
                        $options[] = ['label' => mb_substr($label, 0, 120), 'description' => mb_substr($desc, 0, 200)];
                    }
                    if (count($options) >= 5) {
                        break;
                    }
                }
                if (count($options) < 2) {
                    continue; // single/multi sem opções suficientes → descarta a pergunta
                }
            }
            $out[] = [
                'header'   => mb_substr($header, 0, 24),
                'question' => mb_substr($question, 0, 400),
                'type'     => $type,
                'options'  => $options,
            ];
            if (count($out) >= 3) {
                break;
            }
        }
        return $out;
    }

    /**
     * Aplica uma tarefa pequena SEM cerimônia, mas com o especialista CERTO (qualidade).
     * Se 'arquiteto' (trivial) → um passe só (muda + testa + empacota + marca).
     * Se um especialista (ex.: frontend p/ um formulário) → o especialista faz com qualidade,
     * depois o Arquiteto finaliza (integra + testa + reempacota + marca). Sem plano/aprovação/re-auditoria.
     */
    /**
     * CONVERSA: a mensagem nao e um pedido de construir/alterar software (saudacao, pergunta,
     * recado vago). Aqui o Claude responde NATURALMENTE, com system LEVE (a persona pesada de
     * 'empacotar/sinalizar conclusao' e o que estragava a resposta). Sem empacotar, sem marcar
     * entrega, sem banner. Uma unica fala persistida (sem duplicar, roda quiet e emite 1x).
     */
    /**
     * POS-ENTREGA: o projeto ja foi construido por este Arquiteto. Ele RETOMA a sessao da entrega
     * (contexto completo do que foi feito) e age com autonomia sobre o que o usuario pediu agora:
     * aplica o ajuste direto, responde uma duvida, ou — so se for um pedido NOVO e grande — avisa
     * que vai planejar. Nao recomeca do zero, nao re-planeja um detalhe.
     */
    private function runFollowup(string $ws, string $uuid, string $stateFile, array $state, string $message): array
    {
        $this->setActiveAgent('arquiteto');
        $this->buildStartedAt = time();
        $sid = (string) ($state['arq_sid'] ?? '');
        $this->cc->sessionDir = STORAGE_PATH . '/claude-sessions/' . (int) ($this->conv->id ?? 0);
        @unlink($ws . '/.maestro/cancel');
        $this->saveState($stateFile, array_merge($state, ['phase' => 'running', 'mode' => 'followup', 'started_at' => date('c')]));
        $this->emit(['type' => 'phase', 'phase' => 'Arquiteto', 'status' => 'avaliando o ajuste']);

        $directive = "Este projeto JA FOI ENTREGUE e foi VOCE quem o construiu — voce tem todo o contexto nesta "
            . "sessao e os arquivos estao em /workspace. O usuario continuou a conversa e disse:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "Aja com AUTONOMIA, SEM recomecar do zero e SEM propor plano novo a menos que seja mesmo um projeto NOVO e grande:\n"
            . "- Se for um AJUSTE (pequeno ou medio) no que ja existe: APLIQUE voce mesmo agora, editando so o(s) arquivo(s) certo(s) em /workspace (mantendo o resto intacto). Se mexeu em algo visivel da web, suba a app e confira rapidamente que ainda funciona. Ao terminar, reempacote o .zip limpo em /workspace/delivery/ e rode: mkdir -p /workspace/.maestro && touch /workspace/.maestro/DELIVERY_COMPLETE.\n"
            . "- Se for so uma DUVIDA ou conversa: responda direto, sem mexer em arquivo.\n"
            . "- Se for um pedido NOVO e substancial (outro sistema, muitas telas): responda em uma frase que para isso voce vai montar um plano (NAO construa ainda).\n"
            . "Responda em portugues, curto e natural, dizendo o que fez ou o que precisa. Nao cole codigo no chat.";

        try {
            $res = $this->cc->run($ws, $uuid, $directive, [
                'system' => $this->spec('arquiteto')['system'],
                'agent'  => $this->activeAgent,
                'resume' => $sid,
            ]);
            $summary = trim((string) ($res['summary'] ?? ''));
        } catch (\Throwable $e) {
            $summary = '';
        }
        if ($summary === '') { $summary = 'Pronto.'; }

        $this->saveState($stateFile, array_merge($state, ['phase' => 'done', 'finished_at' => date('c')]));
        $this->emit(['type' => 'phase', 'phase' => 'Entrega', 'status' => 'concluida']);
        $this->emit(['type' => 'agent_text', 'text' => $summary, 'persist' => true]);
        $this->emitPreviewState($ws, $uuid);
        $this->emitGitState($uuid, $summary);
        $this->emitServerDeploys($uuid);
        return ['stage' => 'followup', 'completed' => true, 'summary' => $summary];
    }

    private function runConversational(string $ws, string $uuid, string $stateFile, array $state, string $message, string $reply): array
    {
        $this->setActiveAgent('arquiteto');

        // A resposta JA veio da triagem (mesma chamada que classificou) -> evita 2a/3a invocacao
        // do Claude Code (cada uma sobe container + Opus; era o que deixava o "oi" lento ~30s).
        // E NAO chamamos maybeTitle: saudacao/mensagem sem objetivo NAO renomeia a conversa
        // (o titulo so e gerado quando ha intencao real, no plano/aprovacao).
        $out = trim($reply);
        if ($out === '') {
            // Fallback raro: a triagem nao trouxe texto -> 1 chamada leve, natural, sem marca.
            try {
                $res = $this->cc->run($ws, $uuid, $this->buildConversationalDirective($message), [
                    'quiet'  => true,
                    'system' => 'Você é um engenheiro de software sênior, natural e direto. Responda em português do Brasil, sem se apresentar e sem citar marca.',
                ]);
                $out = trim((string) ($res['summary'] ?? ''));
            } catch (\Throwable $e) {
                $out = '';
            }
        }
        if ($out === '') { $out = 'Oi! Me conta o que você quer construir ou ajustar.'; }

        $this->saveState($stateFile, array_merge($state, ['phase' => 'idle', 'finished_at' => date('c')]));
        $this->emit(['type' => 'agent_text', 'text' => $out, 'persist' => true]);
        // conversational=true -> o worker NAO trata como entrega e o chat NAO mostra o banner.
        return ['stage' => 'conversation', 'conversational' => true, 'completed' => false, 'summary' => $out];
    }

    /** Diretiva minima para a conversa, sem muralha de regras (e isso que estraga o Claude). */
    private function buildConversationalDirective(string $message): string
    {
        return "Mensagem do usuário:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "Responda de forma natural e breve. Se for uma saudação ou algo vago, cumprimente e pergunte, de "
            . "forma simples, o que a pessoa quer construir ou ajustar. NÃO se apresente, NÃO diga quem você é, "
            . "NÃO cite nome de produto/marca, NÃO anuncie entregas nem crie arquivos.";
    }

    private function runQuick(string $ws, string $uuid, string $stateFile, array $state, string $message, string $specialist, string $directive): array
    {
        $this->setActiveAgent('arquiteto');
        @unlink($ws . '/.maestro/cancel');
        @unlink($ws . '/.maestro/DELIVERY_COMPLETE');
        $this->saveState($stateFile, array_merge($state, ['phase' => 'running', 'mode' => 'quick', 'started_at' => date('c')]));
        $this->buildStartedAt = time();
        $this->emit(['type' => 'status', 'message' => 'Tarefa pequena, sem plano. Aciono o especialista certo e finalizo.']);
        // Batiza a conversa ao começar (não há cerimônia de aprovação no caminho rápido).
        $this->maybeTitle($message);

        $marker = $ws . '/.maestro/DELIVERY_COMPLETE';

        if ($specialist === 'arquiteto') {
            // Trivial e não-especializado → o Arquiteto resolve tudo num passe.
            $res = $this->runSpecialist($ws, $uuid, 'arquiteto', $this->buildQuickDirective($message, $directive));
        } else {
            // Pequeno mas especializado → o especialista certo faz (qualidade), o Arquiteto finaliza.
            $this->runSpecialist($ws, $uuid, $specialist, $this->buildQuickSpecialistDirective($specialist, $message, $directive));
            if ($this->cancelled($ws)) {
                return $this->finishCancelled($stateFile, $state);
            }
            // QA VISUAL antes de empacotar (só web com UI): mesmo num ajuste rápido, mudanças de
            // interface (ex.: tema claro/escuro) passam pelo navegador real, o card aparece ao vivo
            // e o frontend corrige o que aparecer. Assim nenhuma mudança visual escapa do teste.
            if (!$this->isMobile() && $this->hasWebUi($ws)) {
                $this->runVisualQa($ws, $uuid, $message); // foco = o que o usuário acabou de pedir
                if ($this->cancelled($ws)) {
                    return $this->finishCancelled($stateFile, $state);
                }
            }
            $this->emit(['type' => 'phase', 'phase' => 'Arquiteto', 'status' => 'finalizando']);
            $res = $this->runSpecialist($ws, $uuid, 'arquiteto', $this->buildFinalizeDirective());
        }
        $summary = (string) ($res['summary'] ?? '');

        if ($this->cancelled($ws)) {
            return $this->finishCancelled($stateFile, $state);
        }

        $this->saveState($stateFile, array_merge($state, ['phase' => 'done', 'finished_at' => date('c')]));
        $this->setActiveAgent('arquiteto');

        $msg = 'Ajuste aplicado. Os arquivos atualizados estão no painel à direita.';
        $this->emit(['type' => 'phase', 'phase' => 'Entrega', 'status' => 'concluída']);
        $this->emit(['type' => 'agent_text', 'text' => $summary !== '' ? $summary : $msg, 'persist' => true]);
        $this->emitPreviewState($ws, $uuid);
        $this->emitGitState($uuid, $summary !== '' ? $summary : $msg);
        $this->emitServerDeploys($uuid);
        return ['stage' => 'execution', 'completed' => true, 'summary' => $summary !== '' ? $summary : $msg];
    }

    private function buildQuickDirective(string $message, string $directive): string
    {
        return "Tarefa PEQUENA, resolva DIRETO e seja RÁPIDO. Pedido do usuário:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "O que fazer:\n{$directive}\n\n"
            . "Vá DIRETO ao(s) arquivo(s) afetado(s). NÃO explore o projeto inteiro, NÃO refatore, NÃO crie documentação nova, "
            . "NÃO rode a suíte de testes inteira nem exercite o sistema ponta a ponta, para uma mudança pequena (texto, rótulo, "
            . "config, estilo pontual) isso é desperdício. Faça assim:\n"
            . "1. Aplique a mudança.\n"
            . "2. Só se você mexeu em CÓDIGO, confira a sintaxe APENAS do arquivo alterado (ex.: php -l / node --check nele). Mudança de texto puro nem isso precisa.\n"
            . "3. Regenere rapidamente o .zip em /workspace/delivery/ (excluindo PLAN.md, PROGRESS.md, .maestro, caches, vendor/, .env).\n"
            . "4. Como ÚLTIMA ação: mkdir -p /workspace/.maestro && touch /workspace/.maestro/DELIVERY_COMPLETE\n"
            . "Uma troca de texto deve levar segundos, não minutos. Resuma em UMA frase o que mudou.";
    }

    /** Diretiva para um especialista fazer uma tarefa pequena com qualidade (sem empacotar, o Arquiteto finaliza). */
    private function buildQuickSpecialistDirective(string $key, string $message, string $directive): string
    {
        $spec = $this->spec($key) ?? $this->spec('arquiteto');
        return "Tarefa pequena e direta (sem plano). Pedido do usuário:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "O que fazer:\n{$directive}\n\n"
            . "Diretrizes da sua especialidade: " . $spec['scope'] . "\n\n"
            . "Faça SOMENTE o que foi pedido, no MENOR escopo, toque apenas no(s) arquivo(s) afetado(s), mas com a "
            . "QUALIDADE da sua área (ex.: se for UI, siga o guia de design). NÃO empacote nem crie marcadores, o Arquiteto "
            . "finaliza. Ao terminar, atualize /workspace/PROGRESS.md com o que mudou.";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FASE 1, PLANO
    // ──────────────────────────────────────────────────────────────────────────

    private function doPlan(string $ws, string $uuid, string $stateFile, string $message, ?array $prev): array
    {
        $this->emit(['type' => 'phase', 'phase' => 'Plano', 'status' => 'analisando o pedido']);
        $this->setupArqSession(); // Arquiteto VIVO: estabelece a sessao rica que os agentes vao forkar
        $this->buildStartedAt = time();

        $prevPlan = '';
        if ($prev && is_file($ws . '/PLAN.md')) {
            $prevPlan = "\n\nJá existe um PLAN.md anterior. Incorpore este feedback do usuário e reescreva-o: \"{$message}\".";
        }

        $system = $this->spec('arquiteto')['system']
            . ' Sua tarefa AGORA é só planejar, NÃO implemente nada ainda.';
        $prompt = $this->convContext()
            . "Pedido do usuário:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "Explore o diretório /workspace (pode já ter código) e, se o pedido citar um site ou material de referência, ABRA E LEIA de verdade (use o navegador ou HTTP), inclusive as IMAGENS, que você vai reaproveitar. "
            . "Produza um plano REALISTA e ENXUTO em /workspace/PLAN.md, DIMENSIONADO ao tamanho REAL do pedido. A regra mais importante é a PROPORCIONALIDADE: entregue o MENOR e MAIS SIMPLES que resolva BEM, com QUALIDADE DE PRODUTO. Mais código não é melhor, é pior.\n\n"
            . "DIMENSIONE primeiro o pedido e escolha o caminho:\n"
            . "- PEQUENO ou ESTÁTICO (uma landing, página de divulgação, formulário simples, um script): UM ou POUCOS arquivos (ex.: uma página e um CSS, no máximo um arquivo de lógica se precisar). SEM banco de dados, SEM backend em camadas, SEM contrato e SEM ondas de vários agentes. É UM especialista (o frontend, no caso de página) fazendo um trabalho BEM FEITO de ponta a ponta. NÃO invente persistência: só use banco se o usuário pediu para GUARDAR ou gerenciar dados.\n"
            . "- SISTEMA (várias telas/endpoints/camadas, login, CRUD, áreas logadas): aí sim vale o time em paralelo. Decomponha no maior número de unidades INDEPENDENTES, defina um CONTRATO DE INTEGRAÇÃO (rotas, schema, nomes exatos de classes/ids, assinaturas) e separe a LÓGICA (src/) da APRESENTAÇÃO (views/ e assets/, do frontend), para vários agentes (até cerca de 20) rodarem JUNTOS sem se atropelar. O paralelismo é a sua força para construir grande: use quando o tamanho pede.\n\n"
            . "O PLAN.md deve conter, proporcional ao tamanho: objetivo e escopo; a stack e arquitetura MAIS SIMPLES que sirva; as etapas só as necessárias; segurança aplicada no código (sem relatório); e a definição de pronto. Para um SISTEMA, inclua também o contrato de integração e o mapa de paralelização (ondas).\n\n"
            . "QUALIDADE VISUAL (inegociável para qualquer coisa com tela): tem que parecer um PRODUTO REAL e moderno (nível Stripe, Apple, Linear), NÃO um rascunho nem uma cópia pelada. Use o guia de design. Para uma página baseada num site real, aproveite o CONTEÚDO e as IMAGENS reais do site, com um layout chamativo (hero forte, seções com ritmo, imagens, tipografia caprichada, responsivo). Uma landing SEM imagens é entrega REPROVADA.\n\n"
            . "Crie /workspace/PROGRESS.md vazio (com cabeçalho). ESCREVA /workspace/.maestro/plan-files.json no formato {\"files\":[\"caminho1\", \"caminho2\"]} com a lista FIEL e ENXUTA dos arquivos que vai criar (pedido pequeno = poucos arquivos) e inclua essa árvore no resumo.\n\n"
            . "NÃO implemente agora. Ao final, escreva um resumo curto e claro do plano para o usuário."
            . $prevPlan;

        // MOBILE (app/both): plano de app RN/Expo. O caminho web acima fica INALTERADO.
        if ($this->isMobile()) {
            $prompt = $this->buildMobilePlanPrompt($message, $prevPlan);
        }

        // O Arquiteto, que ja entendeu o projeto, grava um titulo curto. A APROVACAO aplica
        // esse titulo (rename) sem nova chamada ao modelo.
        $prompt .= "\n\nTITULO DO CHAT: escreva tambem /workspace/.maestro/chat-title.txt com um titulo CURTO "
            . "(3 a 5 palavras, em portugues, sem aspas nem ponto final) que resuma o PROJETO, sera o nome desta conversa.";

        $result = $this->cc->run($ws, $uuid, $prompt, [
            'system'     => $system,
            'agent'      => $this->activeAgent,
            'session_id' => $this->arqSid,
        ]);

        // Árvore de arquivos esperada (blueprint) que o Arquiteto gravou no plano.
        $plannedFiles = $this->readPlannedFiles($ws);
        $planTitle    = $this->readPlanTitle($ws);

        $this->saveState($stateFile, [
            'phase'      => 'awaiting_approval',
            'request'    => $message,
            'planned_at' => date('c'),
            'files'      => $plannedFiles,
            'title'      => $planTitle,
            'arq_sid'    => $this->arqSid,
        ]);

        // Card de aprovação na UI (botão "Aprovar"); se não aprovar, o usuário digita ajustes no chat.
        $this->emit([
            'type'    => 'plan_ready',
            'summary' => $result['summary'] ?? 'Plano pronto.',
            'files'   => $plannedFiles,
            'message' => 'Revise o plano acima. Clique em **Aprovar** para eu desenvolver tudo sozinho até a entrega, ou digite aqui o que quer ajustar.',
        ]);

        return ['stage' => 'plan', 'summary' => $result['summary'] ?? '', 'awaiting_approval' => true];
    }

    /** Prompt de PLANO para projeto MOBILE (RN/Expo, TypeScript). Espelha o web, mas para app nativo. */
    private function buildMobilePlanPrompt(string $message, string $prevPlan): string
    {
        return "Pedido do usuário:\n\"\"\"\n{$message}\n\"\"\"\n\n"
            . "Você vai planejar um APP MOBILE em React Native + Expo (TypeScript), pronto para Android e iOS. "
            . "Explore o diretório /workspace (pode já ter um app Expo). "
            . "Produza um plano realista e ENXUTO no arquivo /workspace/PLAN.md, DIMENSIONADO ao tamanho real do pedido. "
            . "PROPORCIONALIDADE: para um pedido pequeno (ex.: uma tela com uma lista), planeje POUCOS arquivos (uma ou duas telas + um componente ou dois) e o caminho mais direto, NADA de camadas/abstrações por antecipação nem suíte de testes pesada. O PLAN.md deve conter:\n"
            . "- Objetivo e escopo\n- Stack e arquitetura (Expo managed, TypeScript; navegação com expo-router ou React Navigation se houver mais de uma tela; a mais simples que sirva)\n"
            . "- Telas e componentes (lista enxuta) e a navegação entre elas\n"
            . "- Etapas de implementação (ordenadas, só as necessárias)\n"
            . "- Testes proporcionais (checagem de tipos com tsc e export web sem erro já bastam para algo pequeno)\n- Pontos de segurança aplicados NO CÓDIGO (sem segredos no bundle, validação de entrada, HTTPS, armazenamento seguro de tokens)\n"
            . "- DEFINIÇÃO DE PRONTO (objetiva: 'npx tsc --noEmit' sem erros e 'npx expo export --platform web' gerando dist/index.html)\n"
            . "- CONTRATO DE INTEGRAÇÃO (essencial para paralelizar): defina ANTES as interfaces que ligam as partes, para vários especialistas trabalharem AO MESMO TEMPO. Para um app RN, o contrato são: as PROPS (com tipos TypeScript) de cada componente compartilhado, as ROTAS/telas da navegação e seus parâmetros, os TIPOS/modelos de dados (interfaces TS) que telas e serviços trocam, e as ASSINATURAS dos serviços/hooks (ex.: funções de dados/API). Com isso fixado, um agente escreve uma tela enquanto outro escreve um componente/serviço, mirando as MESMAS interfaces, sem se atropelarem.\n\n"
            . "Crie também /workspace/PROGRESS.md vazio (com cabeçalho) para acompanharmos a execução.\n\n"
            . "TIME E PARALELISMO (sua maior força): você é o ARQUITETO e COORDENA um time que trabalha EM PARALELO (o motor suporta até ~20 agentes simultâneos). Decomponha no MAIOR número de unidades INDEPENDENTES (a unidade é a UNIDADE DE TRABALHO, uma tela, um componente, um serviço/hook —, NÃO o papel; pode despachar VÁRIOS do mesmo tipo). É o CONTRATO que torna isso possível. Especialistas disponíveis (keys exatas): frontend (telas/componentes/UI), backend (serviços/hooks/lógica/integração de dados), banco (modelagem dos dados locais/tipos), qa (testes/checagem), seguranca (revisão de segurança). No PLAN.md, descreva o MAPA DE PARALELIZAÇÃO: agrupe os arquivos em unidades que mexem em arquivos SEPARADOS e diga quais correm JUNTAS. Mire ondas LARGAS, mantendo a PROPORCIONALIDADE.\n\n"
            . "SEPARAÇÃO UI × LÓGICA (para a interface não sair amadora): o FRONTEND é o DONO das TELAS e COMPONENTES visuais (JSX/estilos) e cuida do design; o BACKEND escreve a LÓGICA e os DADOS (serviços, hooks, chamadas de API/armazenamento) e expõe funções/hooks que as telas consomem, sem embutir regra de negócio dentro do JSX. Estruture: telas/componentes em (app/ ou screens/ + components/) para o FRONTEND; serviços/hooks/tipos em (services/ ou lib/ + hooks/) para o BACKEND. As telas CHAMAM os hooks/serviços para obter dados.\n\n"
            . "PADRÃO VISUAL (inegociável p/ app com tela): a UI tem que parecer um produto REAL e moderno, NÃO um rascunho. Mínimos: layout coeso e alinhado, espaçamento e tipografia caprichados, áreas de toque adequadas, estados de carregando/vazio/erro, feedback de toque (Pressable), ícones quando ajudarem (@expo/vector-icons) e compatibilidade com react-native-web (para o preview exportar). Defina também a IDENTIDADE do app: um nome real (não 'app de exemplo'), uma cor de tema/acento e um ícone+splash próprios (gerados depois na finalização). Planeje uma onda final de POLIMENTO da UI.\n\n"
            . "ESTRUTURA DE ARQUIVOS ESPERADA (obrigatório): ESCREVA /workspace/.maestro/plan-files.json no formato EXATO "
            . "{\"files\":[\"caminho/arquivo1\",\"pasta/arquivo2\", ...]}, a lista FIEL e ENXUTA dos arquivos que você vai CRIAR (caminhos relativos à raiz do projeto). Reflita a PROPORCIONALIDADE. Inclua também essa árvore no PLAN.md e no seu resumo, como seção 'Estrutura de arquivos esperada'.\n\n"
            . "NÃO implemente o projeto agora. Ao final, escreva um resumo curto do plano para o usuário, mencionando quais especialistas vão atuar."
            . $prevPlan;
    }

    /** Lê o título do chat que o Arquiteto gravou no plano (.maestro/chat-title.txt). */
    private function readPlanTitle(string $ws): string
    {
        $f = rtrim($ws, '/') . '/.maestro/chat-title.txt';
        if (!is_file($f)) {
            return '';
        }
        $t = trim((string) @file_get_contents($f));
        $t = trim(preg_replace('/\s+/', ' ', $t) ?? $t);
        $t = trim($t, " \t\n\r\0\x0B\"'`.…");
        return mb_substr($t, 0, 60);
    }

    /** Renomeia a conversa direto (sem chamar o modelo) e avisa a UI. Respeita rename manual. */
    private function applyTitle(string $title): void
    {
        $conversation = $this->conv;
        if ($conversation === null) {
            return;
        }
        $cur = trim((string) $conversation->title);
        $isPlaceholder = ($cur === '' || $cur === 'Nova conversa' || $cur === 'Nova Conversa');
        if (!$isPlaceholder) {
            return; // ja tem titulo definido (ex.: renomeado pelo usuario) -> nao sobrescreve
        }
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $title = trim($title, " \t\n\r\0\x0B\"'`.…");
        $title = mb_substr($title, 0, 60);
        if ($title === '') {
            return;
        }
        try {
            $conversation->update(['title' => $title]);
            $this->emit(['type' => 'title', 'title' => $title, 'conversation' => $conversation->uuid]);
        } catch (\Throwable $e) {}
    }

    /** Lê a árvore de arquivos esperada (.maestro/plan-files.json) gravada pelo Arquiteto no plano. */
    private function readPlannedFiles(string $ws): array
    {
        $f = rtrim($ws, '/') . '/.maestro/plan-files.json';
        if (!is_file($f)) {
            return [];
        }
        $j = json_decode((string) file_get_contents($f), true);
        if (!is_array($j) || empty($j['files']) || !is_array($j['files'])) {
            return [];
        }
        $files = [];
        foreach ($j['files'] as $p) {
            $p = trim(ltrim((string) $p, '/'));
            if ($p !== '' && !in_array($p, $files, true)) {
                $files[] = $p;
            }
            if (count($files) >= 80) {
                break; // limite de segurança
            }
        }
        return $files;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FASE 2, EXECUÇÃO AUTÔNOMA (o cérebro)
    // ──────────────────────────────────────────────────────────────────────────

    private function runAutonomous(string $ws, string $uuid, string $stateFile, array $state): array
    {
        $this->setActiveAgent('arquiteto');
        $this->emit(['type' => 'status', 'message' => 'Plano aprovado. O Arquiteto vai coordenar o time até a entrega.']);
        $this->setupArqSession($state['arq_sid'] ?? null); // retoma a sessao VIVA do Arquiteto criada no plano
        $this->buildStartedAt = time();
        // APROVACAO = gatilho para renomear o chat com o titulo que o Arquiteto definiu no PLANO
        // (ele ja entendeu o projeto). Aplica direto, sem nova chamada ao modelo. Fallback: gera do pedido.
        $planTitle = trim((string) ($state['title'] ?? ''));
        if ($planTitle !== '') {
            $this->applyTitle($planTitle);
        } else {
            $this->maybeTitle((string) ($state['request'] ?? ''));
        }
        @unlink($ws . '/.maestro/cancel');
        @unlink($ws . '/.maestro/DELIVERY_COMPLETE');
        $this->saveState($stateFile, array_merge($state, ['phase' => 'running', 'mode' => 'full', 'started_at' => date('c')]));

        $marker      = $ws . '/.maestro/DELIVERY_COMPLETE';
        $maxWaves    = max(1, (int) env('MAESTRO_MAX_WAVES', 10));
        $maxParallel = max(1, (int) env('SPECIALISTS_MAX_PARALLEL', 10));
        $feedback    = '';
        $verdict     = ['passed' => false, 'details' => ''];
        $summary     = '';
        $waveNum     = 0;

        // O ARQUITETO é o cérebro: a cada onda ele decide quais especialistas despachar
        // (vários em PARALELO, em áreas disjuntas), revisa o resultado e segue até integrar
        // tudo num sistema coeso. Só conclui quando o marcador existe E a verificação passa.
        for ($i = 1; $i <= $maxWaves; $i++) {
            if ($this->cancelled($ws)) {
                return $this->finishCancelled($stateFile, $state);
            }

            // STEERING ao vivo: recados que o usuário mandou DURANTE o build entram aqui, entre
            // as ondas, SEM parar os agentes. O arquiteto lê e incorpora antes de planejar a próxima.
            $steer = $this->readInbox($ws);
            if ($steer !== '') {
                $this->emit(['type' => 'status', 'message' => '📨 Vi sua mensagem, vou incorporar agora.']);
                $this->setActiveAgent('arquiteto');
                $this->emit(['type' => 'agent_text', 'text' => 'Recebi seu recado no meio do trabalho e vou incorporar a partir desta etapa.', 'persist' => true, 'agent' => $this->activeAgent]);
                $feedback = trim($feedback . "\n\nMENSAGEM NOVA DO USUÁRIO durante o trabalho (leia e INCORPORE ao plano e à próxima onda, pode mudar o rumo do que falta fazer): \"" . $steer . "\"");
            }

            $this->setActiveAgent('arquiteto');
            $this->emit(['type' => 'phase', 'phase' => 'Arquiteto', 'status' => 'planejando a próxima onda']);
            $wave = $this->planWave($ws, $uuid, $feedback);

            if ($this->cancelled($ws)) {
                return $this->finishCancelled($stateFile, $state);
            }

            if (!empty($wave['done'])) {
                // PASSE DE POLIMENTO VISUAL (uma vez, só apps com UI): antes de integrar/empacotar,
                // o FRONTEND abre cada página renderizada e deixa a UI nível produto. Evita a entrega
                // "amadora" (header quebrado, lista com bolinhas, CSS que não cobre a marcação).
                if (empty($state['design_polished']) && $this->isMobile()) {
                    // Mobile: o frontend deixa as telas RN nível produto e reexporta para o preview.
                    $this->emit(['type' => 'phase', 'phase' => 'Frontend', 'status' => 'polimento visual final']);
                    $this->runSpecialist($ws, $uuid, 'frontend', $this->buildMobileDesignPolishDirective());
                    $state['design_polished'] = true;
                    $this->saveState($stateFile, array_merge($state, ['phase' => 'running', 'mode' => 'full']));
                    if ($this->cancelled($ws)) {
                        return $this->finishCancelled($stateFile, $state);
                    }
                } elseif (empty($state['design_polished']) && $this->hasWebUi($ws)) {
                    $this->emit(['type' => 'phase', 'phase' => 'Frontend', 'status' => 'polimento visual final']);
                    $this->runSpecialist($ws, $uuid, 'frontend', $this->buildDesignPolishDirective());
                    $state['design_polished'] = true;
                    $this->saveState($stateFile, array_merge($state, ['phase' => 'running', 'mode' => 'full']));
                    if ($this->cancelled($ws)) {
                        return $this->finishCancelled($stateFile, $state);
                    }
                }

                // QA VISUAL (uma vez, só web com UI): um navegador REAL testa a interface RENDERIZADA
                // ao vivo (login, rotas, cliques), transmitido no chat, e o FRONTEND vê os screenshots
                // (visão) + os erros de console/JS/HTTP e corrige a UI até ficar limpa. Roda depois do
                // polimento estático e ANTES de integrar/empacotar, para a entrega só fechar com a UI OK.
                if (empty($state['qa_visual_done']) && !$this->isMobile() && $this->hasWebUi($ws)) {
                    $qaVerdict = $this->runVisualQa($ws, $uuid, (string) ($state['request'] ?? '')); // foco = o pedido da tarefa
                    if ($qaVerdict === 'infra') {
                        $this->emit(['type' => 'agent_text', 'persist' => true, 'agent' => $this->activeAgent, 'text' => 'Aviso honesto: nao consegui subir o navegador para o QA visual (falha de infraestrutura). A entrega NAO foi verificada visualmente, entao trate o resultado com cautela.']);
                    }
                    $state['qa_visual_done'] = true;
                    $this->saveState($stateFile, array_merge($state, ['phase' => 'running', 'mode' => 'full']));
                    if ($this->cancelled($ws)) {
                        return $this->finishCancelled($stateFile, $state);
                    }
                }

                // O Arquiteto considera tudo pronto → integra, empacota e sinaliza a conclusão.
                $this->emit(['type' => 'phase', 'phase' => 'Arquiteto', 'status' => 'integrando e empacotando']);
                $res = $this->runSpecialist($ws, $uuid, 'arquiteto', $this->buildFinalizeDirective());
                $summary = (string) ($res['summary'] ?? $summary);

                if ($this->cancelled($ws)) {
                    return $this->finishCancelled($stateFile, $state);
                }
                $this->setActiveAgent('arquiteto');

                if (!is_file($marker)) {
                    $feedback = 'Você indicou conclusão, mas não criou o marcador DELIVERY_COMPLETE. Só finalize '
                        . 'quando estiver TUDO pronto: roda sem erro, testes passam, segurança revisada, README e .zip em /workspace/delivery/.';
                    continue;
                }

                $this->emit(['type' => 'phase', 'phase' => 'Verificação', 'status' => 'conferindo a entrega']);
                $verdict = $this->verifyGate($ws, $uuid, 'Entrega', $this->buildDeliveryGateInstruction());

                if (!empty($verdict['passed'])) {
                    break; // entrega confirmada
                }

                @unlink($marker);
                $feedback = "A verificação independente reprovou a entrega. Corrija e finalize de novo:\n" . $verdict['details'];
                continue;
            }

            $tasks = $wave['tasks'] ?? [];
            if (empty($tasks)) {
                $feedback = 'Você não retornou tarefas nem indicou conclusão. Decida a próxima onda de trabalho ou finalize.';
                continue;
            }

            // Despacha a onda EM PARALELO (cada tarefa com a persona/identidade do especialista).
            $waveNum++;
            $names = implode(', ', array_map(
                fn($t) => SpecialistRegistry::get($t['specialist'])['name'] ?? $t['specialist'],
                $tasks
            ));
            $this->setActiveAgent('arquiteto');
            $this->emit(['type' => 'phase', 'phase' => "Onda {$waveNum}", 'status' => count($tasks) . ' em paralelo: ' . $names]);
            // Marco PERMANENTE da onda (mostra o TIME trabalhando em paralelo no histórico, sem o
            // ruído da narração interna de cada agente, que segue efêmera).
            $this->emit(['type' => 'agent_text', 'persist' => true, 'agent' => $this->activeAgent,
                'text' => "🌊 **Onda {$waveNum}**, despachei " . count($tasks) . ' especialista' . (count($tasks) > 1 ? 's' : '') . " em paralelo: {$names}."]);

            $this->runWave($ws, $uuid, $tasks, $maxParallel);
            $feedback = ''; // a próxima planWave lê o PROGRESS.md atualizado para decidir
        }

        if ($this->cancelled($ws)) {
            return $this->finishCancelled($stateFile, $state);
        }

        $this->saveState($stateFile, array_merge($state, ['phase' => 'done', 'finished_at' => date('c')]));
        $this->setActiveAgent('arquiteto');

        if (!empty($verdict['passed'])) {
            $msg = 'Projeto entregue. Os arquivos estão no painel à direita, use **Baixar projeto (.zip)** para levar tudo.';
            $this->emit(['type' => 'phase', 'phase' => 'Entrega', 'status' => 'concluída']);
            $this->emit(['type' => 'agent_text', 'text' => $msg, 'persist' => true]);
            $this->emitPreviewState($ws, $uuid);
            $this->emitGitState($uuid, $summary !== '' ? $summary : $msg);
            $this->emitServerDeploys($uuid);
            return ['stage' => 'execution', 'completed' => true, 'summary' => $summary !== '' ? $summary : $msg];
        }

        $pend = 'Encerrei após ' . $maxWaves . ' ondas, mas a verificação final ainda aponta pendências: ' . ($verdict['details'] ?? '');
        $this->emit(['type' => 'agent_error', 'error' => $pend]);
        return ['stage' => 'execution', 'completed' => false, 'summary' => $pend];
    }

    /**
     * O Arquiteto (cérebro) decide a PRÓXIMA onda: tarefas a despachar em paralelo, ou conclusão.
     * Roda em modo quiet e devolve {done:bool, tasks:[{specialist,title,directive}]}.
     */
    private function planWave(string $ws, string $uuid, string $feedback): array
    {
        $valid = implode(', ', array_keys(array_diff_key(SpecialistRegistry::all(), ['arquiteto' => true])));
        $fb    = $feedback !== '' ? "\n\nATENÇÃO (pendências da rodada anterior):\n{$feedback}\n" : '';
        $prompt = "Você é o ARQUITETO coordenando o time. Leia /workspace/PLAN.md (inclui o CONTRATO DE INTEGRAÇÃO) e /workspace/PROGRESS.md e decida a PRÓXIMA ONDA de trabalho.\n"
            . "PARALELIZE AO MÁXIMO (esta é a prioridade): decomponha o trabalho PENDENTE no MAIOR número possível de unidades INDEPENDENTES e despache-as TODAS na MESMA onda. O motor roda até ~20 agentes ao mesmo tempo. A unidade é a UNIDADE DE TRABALHO (módulo/arquivo/área), NÃO o papel: você PODE e DEVE despachar VÁRIOS agentes do MESMO tipo na mesma onda quando atuam em arquivos diferentes (ex.: 'backend' para auth, OUTRO 'backend' para o CRUD, 'frontend' para o CSS, 'frontend' para o JS, tudo junto). Prefira UMA onda LARGA a várias ondas estreitas.\n"
            . ($this->isMobile()
                ? "O CONTRATO TORNA ISSO SEGURO: como o PLAN.md já fixou as props/tipos TypeScript dos componentes, as rotas/telas e seus parâmetros, os tipos de dados e as assinaturas de hooks/serviços, as partes NÃO precisam esperar umas às outras. Frontend (telas/componentes RN) e Backend (hooks/serviços/dados) RODAM JUNTOS contra o contrato (o Backend expõe os hooks/tipos acordados; o Frontend consome exatamente esses hooks/tipos). NÃO serialize Frontend depois de Backend por precaução, só separe quando houver dependência REAL e inevitável (ex.: integração final, ou verificação que precisa do app inteiro pronto).\n"
                : "O CONTRATO TORNA ISSO SEGURO: como o PLAN.md já fixou rotas, esquema de HTML/classes CSS, schema do banco e assinaturas, as partes NÃO precisam esperar umas às outras. Em servidor-renderizado, Frontend e Backend RODAM JUNTOS contra o contrato (o Backend emite as classes acordadas; o Frontend estiliza essas mesmas classes). NÃO serialize Frontend depois de Backend por precaução, só separe quando houver dependência REAL e inevitável (ex.: integração final que junta tudo, ou verificação que precisa do sistema inteiro pronto).\n")
            . "ÚNICA restrição de paralelismo: duas tarefas NÃO podem editar o MESMO arquivo na mesma onda. Dê a cada tarefa uma ÁREA/arquivos próprios e exclusivos.\n"
            . "Especialistas válidos (key): {$valid}.\n"
            . "Para cada tarefa, escreva uma DIRETIVA específica e autossuficiente: o que fazer, em quais arquivos/área (exclusivos dela), a quais itens do CONTRATO se ater, e o que NÃO tocar.\n\n"
            . "CONVERGÊNCIA (crítico, não fique em loop): NÃO repita trabalho já feito. Antes de despachar, confira no PROGRESS.md o que JÁ foi concluído. "
            . "Se a implementação já está feita e já foi AUDITADA/APROVADA por QA e Segurança, NÃO mande auditar de novo, responda done=true para finalizar. "
            . "Só despache uma tarefa se houver trabalho REAL ainda PENDENTE. Auditoria/teste é feito UMA vez por mudança, não a cada onda. Tipicamente: 1 onda larga de implementação (todos os módulos juntos) → 1 onda de qa+seguranca em paralelo → done.\n"
            . "ESCOPO INCREMENTAL (projetos grandes): quando o pedido for uma mudança num projeto JÁ entregue, identifique os ARQUIVOS ALTERADOS e seus dependentes diretos, e escope cada tarefa SÓ a esse raio. NÃO re-implemente nem re-audite áreas intactas, a suíte de testes existente é a rede de regressão. Mexeu em 1 arquivo de 5 mil? O trabalho é sobre esse arquivo e quem o consome, não sobre os 5 mil.\n"
            . "Se TODO o trabalho (implementação + testes + segurança da mudança) já estiver feito e só faltar integrar/empacotar, responda done=true.{$fb}\n\n"
            . "Responda EXCLUSIVAMENTE com JSON válido, sem texto antes ou depois, no formato (note VÁRIAS tarefas na mesma onda):\n"
            . '{"done": false, "tasks": [{"specialist": "backend", "title": "Auth", "directive": "..."}, {"specialist": "backend", "title": "CRUD de tarefas", "directive": "..."}, {"specialist": "frontend", "title": "CSS/layout", "directive": "..."}, {"specialist": "frontend", "title": "JS/interações", "directive": "..."}]}'
            . "\nou, se já acabou a implementação: {\"done\": true, \"tasks\": []}";

        try {
            $result = $this->cc->run($ws, $uuid, $prompt, [
                'quiet'  => true,
                'resume' => $this->arqSid,
                'system' => $this->spec('arquiteto')['system'] . ' Você só responde com JSON válido.',
            ]);
            $parsed = $this->parseJson((string) ($result['summary'] ?? ''));
            if (!is_array($parsed)) {
                return ['done' => false, 'tasks' => []];
            }

            $tasks = [];
            foreach (($parsed['tasks'] ?? []) as $t) {
                $key = is_array($t) ? strtolower(trim((string) ($t['specialist'] ?? ''))) : '';
                if ($key === '' || $key === 'arquiteto' || SpecialistRegistry::get($key) === null) {
                    continue;
                }
                $dir = trim((string) ($t['directive'] ?? $t['title'] ?? ''));
                if ($dir === '') {
                    continue;
                }
                $tasks[] = ['specialist' => $key, 'title' => (string) ($t['title'] ?? ''), 'directive' => $dir];
            }
            return ['done' => (bool) ($parsed['done'] ?? false), 'tasks' => $tasks];
        } catch (\Throwable $e) {
            $this->emit(['type' => 'status', 'message' => 'Não consegui planejar a onda agora; vou tentar de novo.']);
            return ['done' => false, 'tasks' => []];
        }
    }

    /** Executa as tarefas de uma onda EM PARALELO, cada uma com a persona/identidade do especialista. */
    private function runWave(string $ws, string $uuid, array $tasks, int $maxParallel): void
    {
        $jobs = [];
        foreach ($tasks as $idx => $t) {
            $spec = $this->spec($t['specialist']) ?? $this->spec('arquiteto');
            $jobs[] = [
                'id'     => 'w' . $idx . '-' . $t['specialist'],
                'ws'     => $ws,
                'uuid'   => $uuid,
                'prompt' => $this->buildTaskDirective($spec, (string) $t['directive']),
                'system' => $spec['system'],
                'agent'  => SpecialistRegistry::ui($t['specialist']),
                'model'  => $this->modelForAgents(),
                'fork'   => $this->arqSid,
            ];
        }
        $this->cc->runMany($jobs, $maxParallel);
    }

    /** Diretiva de UMA tarefa (ordem do Arquiteto) para um especialista, ciente do paralelismo. */
    private function buildTaskDirective(array $spec, string $directive): string
    {
        $contract = $this->isMobile()
            ? 'props/tipos TypeScript dos componentes, rotas/telas e seus parâmetros de navegação, tipos/modelos de dados e assinaturas de hooks/serviços'
            : 'rotas, nomes de classes/ids do HTML, schema, assinaturas';
        return "Você é o especialista de {$spec['name']}, executando uma ordem do Arquiteto.\n\n"
            . "TAREFA:\n{$directive}\n\n"
            . "Diretrizes da sua especialidade: " . $spec['scope'] . "\n\n"
            . "TRABALHO EM PARALELO (atenção): VÁRIOS outros agentes estão trabalhando AGORA, ao mesmo tempo que você. "
            . "Por isso: (1) ATENHA-SE ao CONTRATO DE INTEGRAÇÃO do /workspace/PLAN.md ({$contract}), é ele que faz sua parte encaixar na dos outros SEM vocês conversarem; não invente nomes/contratos novos. "
            . "(2) Mexa SOMENTE nos arquivos da SUA área (os que a tarefa indicou); NÃO crie nem edite arquivos de outras áreas, NEM o de outro agente, para não haver conflito. "
            . "(3) Se precisar de algo que é de outra área (ex.: uma função que outro está escrevendo), confie no CONTRATO e programe contra a interface acordada, não a reimplemente. "
            . "Implemente sua parte de forma COMPLETA. Ao terminar, registre seu progresso APENAS por APPEND ATÔMICO via shell, rode exatamente um comando como: printf '%s\\n' '- [SUA ÁREA] concluído: <resumo curto>' >> /workspace/PROGRESS.md (use >> SEMPRE). NUNCA leia-e-reescreva o PROGRESS.md (outros agentes escrevem nele ao mesmo tempo e isso o corromperia).";
    }

    /**
     * Roda UMA passada de um especialista: define a identidade ativa, anuncia a fase e
     * invoca o Claude Code com a persona (system) e a identidade do especialista.
     */
    private function runSpecialist(string $ws, string $uuid, string $key, string $directive): array
    {
        $spec = $this->spec($key) ?? $this->spec('arquiteto');
        $ui   = $this->setActiveAgent($key);
        $this->emit(['type' => 'phase', 'phase' => $spec['name'], 'status' => 'trabalhando']);

        $opt = ['system' => $spec['system'], 'agent' => $ui];
        if ($key !== 'arquiteto') { $opt['model'] = $this->modelForAgents(); }
        if ($this->arqSid !== '') { $opt[$key === 'arquiteto' ? 'resume' : 'fork'] = $this->arqSid; }
        return $this->cc->run($ws, $uuid, $directive, $opt);
    }

    /**
     * Modelo dos AGENTES (especialistas das ondas, verificador do portão, QA visual). O Arquiteto
     * NÃO passa por aqui: segue o escopo 'autonomous' (default do motor). Sem configuração própria,
     * o escopo dos agentes HERDA o modelo do Dev Autônomo do mesmo usuário — comportamento antigo.
     */
    private function modelForAgents(): string
    {
        $uid = $this->actingUserId ?: (int) $this->cc->actingUserId;
        return \Ravi\Services\ModelService::forUser($uid, \Ravi\Services\ModelService::SCOPE_AGENTS);
    }

    /** Diretiva do passe de POLIMENTO VISUAL: o frontend deixa a UI nível produto, olhando o real. */
    private function buildDesignPolishDirective(): string
    {
        return "POLIMENTO VISUAL FINAL, você é o FRONTEND e vai deixar a interface com cara de PRODUTO REAL e moderno "
            . "(nível Stripe/Apple/Linear). A implementação já existe; agora o foco é SÓ a aparência e a coesão visual.\n\n"
            . "Releia o guia de design e o CONTRATO de marcação do /workspace/PLAN.md. Depois:\n"
            . "1. SUBA a app com o EXATO start_command do app.json (com o roteador, se houver, é assim que o PREVIEW roda) em SEGUNDO PLANO "
            . "(NUNCA em primeiro plano: ex.: 'php -S 127.0.0.1:8080 -t public public/index.php >/tmp/srv.log 2>&1 & SRV=\$!', faça os curl, e no fim 'kill \$SRV'). "
            . "ASSETS (decisivo): baixe os CSS/JS referenciados na home via HTTP e confirme o content-type, CSS 'text/css', JS 'application/javascript'. Se vier 'text/html', o estático está sendo engolido pelo roteador → adicione o guard cli-server no index.php, EXCLUINDO .php (senão os links 'index.php?r=...' viram página branca): \"if (PHP_SAPI==='cli-server') { \$p=parse_url(\$_SERVER['REQUEST_URI']??'/',PHP_URL_PATH); if(\$p!=='/' && is_file(__DIR__.\$p) && substr(\$p,-4)!=='.php') return false; }\". Depois confirme também que um link interno (index.php?r=...) retorna CONTEÚDO, não branco. "
            . "Baixe o HTML RENDERIZADO de cada rota (home, listagem, item, carrinho, login, cadastro, conta, painel admin, etc.) "
            . "e INSPECIONE a marcação real que está sendo servida.\n"
            . "2. CORRIJA tudo que estiver amador, item por item (são reprovações garantidas):\n"
            . "   - CABEÇALHO: precisa ser uma barra coesa e ALINHADA (logo/título à esquerda, navegação e ações, busca, carrinho, entrar/cadastrar, no lugar, sem item vazando ou cortado na borda). Use flexbox/grid; nada de elementos empilhados tortos.\n"
            . "   - RESET de CSS: NENHUMA lista (`<ul>/<li>`) usada como menu/filtro/cards pode aparecer com BOLINHAS ou numeração, remova marcadores e estilize como o design pede.\n"
            . "   - GRID de cards uniforme, com espaçamento, raio, sombra/sutileza, tipografia e hierarquia consistentes; imagens/placeholders elegantes; botões com tamanho/peso corretos e estados de hover/foco.\n"
            . "   - Espaçamento, alinhamento e tipografia coerentes em TODAS as páginas; tema/contraste acessível; responsivo (não pode quebrar em telas estreitas).\n"
            . "3. Você é o DONO da apresentação: se alguma página tem HTML solto/feio escrito fora da camada de view, AJUSTE a marcação e o CSS para ficar coeso (sem mudar a lógica/regras de negócio do backend). Garanta que o CSS COBRE toda a marcação realmente servida.\n"
            . "4. NÃO entregue nem crie marcadores de conclusão, só faça o polimento e, ao terminar, registre no PROGRESS.md (append) o que ajustou. O Arquiteto finaliza depois.";
    }

    /** Polimento visual de app MOBILE (RN/Expo): o frontend deixa as telas nível produto. */
    private function buildMobileDesignPolishDirective(): string
    {
        return "POLIMENTO VISUAL FINAL, você é o FRONTEND de um app React Native + Expo e vai deixar a interface "
            . "com cara de PRODUTO REAL e moderno (nível dos melhores apps). A implementação já existe; o foco agora é SÓ a "
            . "aparência e a coesão visual das TELAS.\n\n"
            . "Releia o PLAN.md (contrato/telas) e percorra CADA tela e componente (app/, components/). Depois conserte tudo que "
            . "estiver amador, item por item:\n"
            . "1. IDENTIDADE: um tema coeso (paleta de cores consistente com uma cor de acento, tipografia com hierarquia clara, "
            . "raios de canto e sombras sutis padronizados). Centralize tokens (cores/espaçamentos) em um arquivo de tema e use em todas as telas.\n"
            . "2. LAYOUT: use SafeAreaView (respeitar notch/barra de status), espaçamento e alinhamento consistentes, listas com "
            . "FlatList bem espaçadas; nada de elementos colados na borda ou cortados.\n"
            . "3. TOQUE E ESTADOS: alvos de toque adequados (mín. ~44px), feedback ao tocar (Pressable com estado de pressionado), "
            . "e estados de CARREGANDO, VAZIO e ERRO bem desenhados (não telas em branco).\n"
            . "4. CAPRICHO: ícones (@expo/vector-icons) onde ajudam, botões com peso/contraste corretos, inputs com rótulo e foco visível, "
            . "acessibilidade (accessibilityLabel/role). Compatível com react-native-web (o preview é o export web).\n"
            . "5. PROVE: rode 'npx tsc --noEmit' (sem erros) e 'npx expo export --platform web' (tem que gerar dist/index.html) para "
            . "garantir que o polimento não quebrou nada.\n"
            . "NÃO crie marcadores de conclusão nem empacote, o Arquiteto finaliza. Ao terminar, registre no PROGRESS.md (append) o que ajustou.";
    }

    /**
     * QA VISUAL: abre um navegador REAL contra a app rodando e testa a interface RENDERIZADA
     * (login de verdade, rotas, cliques), transmitindo ao vivo no chat (card "Navegador ao vivo").
     * O FRONTEND então VÊ os screenshots (visão) + os erros (console/JS/HTTP do report.json) e
     * corrige a UI, num pequeno loop, até ficar limpa. Roda UMA vez por build. Tolerante a falha de
     * infra: se o navegador não puder abrir, apenas registra e segue (o portão final ainda roda).
     */
    private function runVisualQa(string $ws, string $uuid, string $focus = ''): string
    {
        if (!filter_var(env('MAESTRO_QA_VISUAL', true), FILTER_VALIDATE_BOOLEAN)) {
            return 'disabled';
        }
        $qa = new QaVisualService();
        if (!$qa->shouldRun($ws)) {
            return 'skipped'; // sem interface web para testar
        }
        // Os eventos screencast_start/stop fluem para o chat (abrem/fecham o card ao vivo).
        $qa->onUpdate(fn(array $e) => $this->emit($e));

        $focus = trim($focus);
        $rounds  = max(1, (int) env('MAESTRO_QA_VISUAL_ROUNDS', 2));
        $verdict = 'issues';       // default se sair do loop sem aprovar
        $focusChecked = false;     // a mudança específica já foi verificada por um agente?
        for ($r = 1; $r <= $rounds; $r++) {
            if ($this->cancelled($ws)) {
                $verdict = 'cancelled';
                break;
            }
            $this->setActiveAgent('qa');
            $this->emit(['type' => 'phase', 'phase' => 'QA Visual', 'status' => 'testando a interface no navegador']);

            $res = $qa->run($ws, $uuid);
            if (!empty($res['skipped'])) {
                $verdict = 'skipped';
                break;
            }
            if (empty($res['report'])) {
                // Infra falhou (sem report): não bloqueia a entrega, o verificador final ainda roda.
                $this->emit(['type' => 'phase', 'phase' => 'QA Visual', 'status' => 'não foi possível abrir o navegador desta vez']);
                $verdict = 'infra';
                break;
            }

            $errs  = (int) ($res['error_count'] ?? 0);
            $clean = !empty($res['ok']) && $errs === 0;
            // O harness é GENÉRICO (varre rotas); ele NÃO sabe testar a mudança nova. Então, mesmo
            // sem erro, se há uma MUDANÇA específica ($focus) ainda não verificada, despachamos um
            // agente que VÊ os screenshots e EXERCITA exatamente o que foi pedido.
            $needFocus = ($focus !== '' && !$focusChecked);
            if ($clean && !$needFocus) {
                $this->setActiveAgent('qa');
                $this->emit(['type' => 'phase', 'phase' => 'QA Visual', 'status' => 'interface aprovada no navegador']);
                $this->emit(['type' => 'agent_text', 'persist' => true, 'agent' => $this->activeAgent,
                    'text' => 'QA visual: testei a interface num navegador real' . ($focus !== '' ? ', incluindo a mudança pedida,' : '') . ' e está funcionando sem erros.']);
                $verdict = 'clean';
                break;
            }

            // No ÚLTIMO round não despacha outro agente (evita loop infinito).
            if ($r >= $rounds) {
                $this->emit(['type' => 'phase', 'phase' => 'QA Visual', 'status' => $clean ? 'interface aprovada no navegador' : 'apliquei correções; pode haver pontos residuais']);
                $verdict = $clean ? 'clean' : 'issues';
                break;
            }

            // O FRONTEND verifica a MUDANÇA específica (focus) + conserta erros/visual, lendo os
            // screenshots (visão) e EXERCITANDO o novo comportamento no navegador; depois reconfirmamos.
            $this->runSpecialist($ws, $uuid, 'frontend', $this->buildVisualQaFixDirective($res, $focus));
            $focusChecked = true;
        }

        $qa->cleanup($ws); // remove o screencast (frame/stills/report), nada acumula
        return $verdict;
    }

    /**
     * O pedido é EXPLICITAMENTE para revalidar/rodar o QA visual (navegador real)? Usado para, num
     * projeto já entregue com UI web, abrir o navegador na hora, sem rebuild nem plano. Específico
     * para não capturar pedidos genéricos de "visual" (ex.: "melhore o visual" segue o fluxo normal).
     */
    private function isVisualQaRequest(string $message): bool
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') {
            return false;
        }
        // "qa visual" em qualquer ordem; ou (revalidar/rodar/testar) + (navegador|"teste visual").
        if (preg_match('/\bqa\s*visual\b/u', $m)) {
            return true;
        }
        if (preg_match('/\bteste?\s+(no\s+)?navegador\b/u', $m)) {
            return true;
        }
        if (preg_match('/\b(revalid\w*|reteste\w*|retestar|rod[ae]r?|roda|valid\w*|test\w*|abr\w*)\b/u', $m)
            && preg_match('/\bnavegador\b/u', $m)) {
            return true;
        }
        return false;
    }

    /**
     * Revalidação SOB DEMANDA do QA visual: roda o navegador real AGORA contra a app já entregue,
     * sem rebuild nem aprovação de plano. Mostra o card ao vivo e conserta a UI se achar erro.
     */
    private function runVisualQaOnDemand(string $ws, string $uuid): array
    {
        $this->setActiveAgent('qa');
        $this->emit(['type' => 'status', 'message' => 'Revalidando a interface num navegador real…']);

        $verdict = $this->runVisualQa($ws, $uuid);

        $this->setActiveAgent('qa');
        if ($verdict === 'skipped' || $verdict === 'disabled') {
            $this->emit(['type' => 'agent_text', 'persist' => true, 'agent' => $this->activeAgent,
                'text' => 'Este projeto não tem interface web para testar no navegador.']);
        } elseif ($verdict === 'infra') {
            $this->emit(['type' => 'agent_text', 'persist' => true, 'agent' => $this->activeAgent,
                'text' => 'Tentei abrir o navegador para revalidar a interface, mas o ambiente não respondeu desta vez. Pode pedir de novo.']);
        } elseif ($verdict === 'issues') {
            $this->emit(['type' => 'agent_text', 'persist' => true, 'agent' => $this->activeAgent,
                'text' => 'Revalidei a interface no navegador e ajustei o que apareceu; restaram pontos que vou revisar num próximo passe.']);
        }
        // 'clean' já emite a confirmação dentro do runVisualQa.

        return ['stage' => 'qa_visual', 'completed' => true, 'summary' => 'QA visual revalidado (' . $verdict . ').'];
    }

    /** Diretiva de correção do QA visual: o frontend olha os screenshots + erros e conserta a UI. */
    private function buildVisualQaFixDirective(array $res, string $focus = ''): string
    {
        $report = is_array($res['report'] ?? null) ? $res['report'] : [];
        $shots  = is_array($res['shots'] ?? null) ? $res['shots'] : [];

        $shotList = '';
        foreach ($shots as $s) {
            $shotList .= "   - /workspace/.maestro/screencast/" . ltrim((string) $s, '/') . "\n";
        }

        // Bloco PRIORITÁRIO: o harness é genérico e NÃO sabe testar a mudança nova. Se houver uma
        // mudança específica pedida, o agente tem que VERIFICAR e EXERCITAR exatamente ela.
        $focusBlock = '';
        if (trim($focus) !== '') {
            $focusBlock = "0. A MUDANÇA PEDIDA AGORA (prioridade MÁXIMA, o teste automático NÃO cobre isto):\n"
                . "\"\"\"\n" . trim($focus) . "\n\"\"\"\n"
                . "   - CONFIRME que essa mudança está IMPLEMENTADA e FUNCIONA DE VERDADE. Suba a app (start_command do app.json, "
                . "em SEGUNDO PLANO), vá até a TELA/elemento afetado e EXERCITE o novo comportamento de fato, ex.: se foi um "
                . "botão/toggle novo, ACIONE-o e confirme o efeito (no caso de 'ver senha', que o input alterna entre password e "
                . "texto e mostra/oculta a senha). Inspecione o HTML/JS servido daquela tela e o screenshot dela.\n"
                . "   - Se a mudança NÃO está lá, está incompleta, feia ou quebrada: IMPLEMENTE/CORRIJA agora e prove rodando. "
                . "NÃO se limite a revalidar o que já existia, o foco é ESTA mudança.\n\n";
        }

        return "QA VISUAL, um navegador REAL (Chromium) acabou de navegar a app rodando: abriu a home, "
            . "fez LOGIN com as credenciais de demonstração e percorreu as telas principais, capturando "
            . "SCREENSHOTS e registrando erros de console/JavaScript/HTTP. Você é o FRONTEND e vai CORRIGIR o "
            . "que apareceu, deixando a interface RENDERIZADA limpa, bonita e SEM erros.\n\n"
            . $focusBlock
            . "1. OLHE OS SCREENSHOTS (são imagens de PÁGINA INTEIRA, abra cada uma com sua visão e avalie como "
            . "a UI REALMENTE renderizou):\n" . ($shotList !== '' ? $shotList : "   (sem screenshots nesta rodada)\n")
            . "   E leia o relatório em /workspace/.maestro/screencast/report.json.\n\n"
            . "2. ERROS ENCONTRADOS PELO NAVEGADOR (corrija TODOS, são reprovações):\n"
            . $this->compileQaFindings($report) . "\n\n"
            . "3. Conserte também o que estiver AMADOR nos screenshots, item por item: cabeçalho coeso e ALINHADO; "
            . "nenhuma lista (<ul>/<li>) de menu/cards com BOLINHAS; grid uniforme com espaçamento/raio/sombra/tipografia "
            . "consistentes; botões e estados de hover/foco corretos; espaçamento e alinhamento coerentes em TODAS as telas; "
            . "responsivo. A UI tem que parecer um PRODUTO REAL (nível Stripe/Apple/Linear).\n"
            . "4. PROVE que corrigiu: suba a app com o EXATO start_command do app.json em SEGUNDO PLANO (nunca em primeiro "
            . "plano, use & e mate ao fim) e confirme via HTTP que as rotas que tinham erro agora respondem sem erro e que os "
            . "estáticos (CSS/JS) vêm com o content-type certo. NÃO altere a lógica/regras do backend além do necessário para a UI renderizar certo.\n"
            . "5. NÃO crie marcadores de conclusão nem empacote, só corrija. Ao terminar, registre no PROGRESS.md (append) o que ajustou.";
    }

    /** Resume os achados do report.json do QA visual em linhas curtas para a diretiva de correção. */
    private function compileQaFindings(array $report): string
    {
        $lines = [];
        $login = is_array($report['login'] ?? null) ? $report['login'] : [];
        if (!empty($login['required']) && array_key_exists('success', $login) && $login['success'] === false) {
            $lines[] = '   - LOGIN falhou no navegador: ' . (string) ($login['detail'] ?? 'não autenticou com as credenciais de demonstração') . '. O login PRECISA funcionar.';
        }
        foreach (($report['http_errors'] ?? []) as $e) {
            $lines[] = '   - HTTP ' . (int) ($e['status'] ?? 0) . ' em ' . (string) ($e['url'] ?? '');
        }
        foreach (($report['page_errors'] ?? []) as $e) {
            $lines[] = '   - Exceção de página (JS): ' . mb_substr((string) ($e['text'] ?? ''), 0, 180) . ' (em ' . (string) ($e['url'] ?? '') . ')';
        }
        foreach (($report['console_errors'] ?? []) as $e) {
            $lines[] = '   - Erro de console: ' . mb_substr((string) ($e['text'] ?? ''), 0, 180) . ' (em ' . (string) ($e['url'] ?? '') . ')';
        }
        foreach (($report['failed_requests'] ?? []) as $e) {
            $lines[] = '   - Requisição falhou: ' . (string) ($e['url'] ?? '') . ' (' . (string) ($e['error'] ?? '') . ')';
        }
        if (empty($lines)) {
            $lines[] = '   - (Sem erros de runtime no relatório, foque no capricho visual a partir dos screenshots.)';
        }
        return implode("\n", array_slice($lines, 0, 25));
    }

    /** Diretiva de finalização (Arquiteto): integra, documenta, empacota LIMPO e cria o marcador. */
    private function buildFinalizeDirective(): string
    {
        // MOBILE (app/both): finalização de app RN/Expo. O caminho web abaixo fica INALTERADO.
        if ($this->isMobile()) {
            return $this->buildFinalizeDirectiveMobile();
        }

        return "Os especialistas já implementaram suas partes (veja /workspace/PROGRESS.md). Agora, como ARQUITETO, finalize com padrão profissional:\n"
            . "1. RODE O SISTEMA DE VERDADE e PROVE que funciona, não basta os testes. Para app WEB: prepare o banco (rode a migração/seed), suba o servidor (php -S) e acesse VIA HTTP. COMECE pela RAIZ 'curl -i http://127.0.0.1:PORTA/': ela TEM que devolver 200 ou 302 (NUNCA 404, o preview SEMPRE abre a raiz, então garanta um index.php na docroot que seja a home/login ou redirecione para ela). Depois acesse a tela de login, faça um LOGIN REAL com as credenciais de demonstração, acesse a área logada e o logout, confirmando que NENHUMA retorna 404/erro/500 e que o login autentica de fato. Conserte QUALQUER erro até o FLUXO REAL rodar limpo. (A entrega vira um PREVIEW clicável, se a raiz ou a tela de login quebra, é inaceitável.)\n"
            . "2. SEGURANÇA (obrigatória, independe do tamanho): revise o que foi criado e CORRIJA as falhas, SQL só por prepared statements (nunca concatenar), entrada/saída validada e escapada (anti-XSS), CSRF nos formulários, senha com hash, sessão/cookies seguros, rotas protegidas exigindo login de fato, sem segredos versionados. Uma brecha até num formulário simples pode expor o banco inteiro.\n"
            . "3. Execute os testes que existirem; se algo falhar, corrija até passar.\n"
            . "4. DOCUMENTAÇÃO para quem vai BAIXAR e implantar em OUTRO ambiente (obrigatório):\n"
            . "   - README.md: o que é, funcionalidades, stack e como rodar localmente.\n"
            . "   - INSTALL.md (ou um script instalador): passo a passo de instalação/implantação do zero por um programador que NUNCA viu o projeto, requisitos, instalação de dependências, configuração do .env, criação do banco, como subir o servidor e como trocar SQLite por MySQL/MariaDB.\n"
            . "5. ENTREGA LIMPA (importante): monte o .zip final em /workspace/delivery/ contendo APENAS o projeto pronto para produção. "
            . "INCLUA o código (src, public, config…), composer.json, .env.example, schema, testes e a documentação. "
            . "EXCLUA OBRIGATORIAMENTE: PLAN.md, PROGRESS.md, a pasta .maestro, bancos de teste COM DADOS (ex.: *.sqlite gerado nos testes), "
            . "caches (.phpunit.cache etc.), vendor/, node_modules/, .env real, logs e qualquer arquivo temporário/scratch criado durante o desenvolvimento. "
            . "O que o programador baixar tem que estar LIMPO, PRONTO e PERFEITO, sem sujeira de processo nem de teste.\n"
            . "   ATENÇÃO: NÃO APAGUE esses arquivos do /workspace, PLAN.md, PROGRESS.md e .maestro são necessários caso o usuário "
            . "peça AJUSTES depois da entrega (você relê o progresso para continuar). Apenas NÃO os inclua no .zip "
            . "(monte o pacote a partir de uma lista de inclusão, ou copie o projeto para uma pasta temporária e remova os itens excluídos só de lá antes de compactar).\n"
            . "6. METADADOS DE PREVIEW: escreva /workspace/.maestro/app.json descrevendo como rodar a app, no formato exato: "
            . '{"type":"web|api|cli","has_web_ui":true|false,"docroot":"public","start_command":"php -S 0.0.0.0:$PORT -t public","setup_command":"","requires_login":true|false,"demo_credentials":{"user":"","password":""},"db":"none|sqlite|mysql","db_env":{"driver":"DB_DRIVER","host":"DB_HOST","port":"DB_PORT","database":"DB_NAME","user":"DB_USER","password":"DB_PASS"}}. '
            . "Regras do app.json: use SEMPRE o literal \$PORT no start_command (o preview substitui pela porta). "
            . "has_web_ui=true SÓ se houver interface visual para abrir no navegador. "
            . "setup_command: se a app usa BANCO, é OBRIGATÓRIO preencher com o comando que prepara o banco do ZERO antes de subir (ex.: 'php migrate.php && php seed.php' ou 'cp -n .env.example .env; php migrate.php'), SEM isso o preview sobe sem banco e quebra na primeira página. Só deixe vazio se a app realmente não tem banco/preparo. "
            . "Se db=mysql, em db_env informe os NOMES das variáveis de ambiente que a SUA app usa para conectar ao banco "
            . "(o preview vai defini-las apontando para um MariaDB real); garanta que a app lê essas variáveis de ambiente. "
            . "Se a app TIVER login (mesmo que a home seja pública), SEMPRE crie usuário(s) de demonstração via seed no setup_command e preencha demo_credentials no app.json, e no RESUMO final para o usuário INFORME explicitamente as credenciais (usuário e senha) de cada papel (ex.: admin e cliente). O usuário não deve precisar pedir isso.\n"
            . "7. Confira a DEFINIÇÃO DE PRONTO do /workspace/PLAN.md item a item.\n\n"
            . "SINAL DE CONCLUSÃO (obrigatório): SOMENTE quando estiver 100% pronto, roda, testes passam, segurança revisada, "
            . "documentação completa, .zip LIMPO e app.json prontos, execute como ÚLTIMA ação o comando:\n"
            . "    mkdir -p /workspace/.maestro && touch /workspace/.maestro/DELIVERY_COMPLETE\n"
            . "NÃO crie esse marcador antes de tudo estar realmente pronto. Se faltar qualquer coisa, continue até terminar.";
    }

    /** Finalização MOBILE (Arquiteto): integra, PROVA (tipos + export web), empacota LIMPO e marca. */
    private function buildFinalizeDirectiveMobile(): string
    {
        return "Os especialistas já implementaram suas partes (veja /workspace/PROGRESS.md). Agora, como ARQUITETO, finalize o app MOBILE (React Native + Expo) com padrão profissional:\n"
            . "0. IDENTIDADE DO APP (obrigatório, para não parecer um exemplo): no app.json do Expo defina o NOME real do app "
            . "(a partir do pedido do usuário, ex.: 'Minhas Notas') em expo.name, um slug coerente, e uma cor de fundo de splash "
            . "(expo.splash.backgroundColor e expo.backgroundColor) combinando com o tema. GERE um ÍCONE e um SPLASH próprios "
            . "(não use os padrões do Expo): com a ferramenta ImageMagick (comando 'convert', já instalada), crie um assets/icon.png "
            . "QUADRADO 1024x1024 com um fundo de cor sólida/gradiente do tema e a INICIAL (ou um glifo simples) do app centralizada, "
            . "e um assets/splash-icon.png parecido (ex.: 1284x2778 ou o ícone centralizado em fundo da cor do tema). Aponte-os no app.json "
            . "(expo.icon, expo.splash.image, e expo.android.adaptiveIcon.foregroundImage se existir). Mantenha sóbrio e elegante. "
            . "Confira que o app.json continua válido e o export web abaixo segue funcionando.\n"
            . "1. PROVE QUE COMPILA E EXPORTA (obrigatório, é a definição de pronto):\n"
            . "   - Garanta as dependências instaladas ('npm install' se preciso) e as de web ('npx expo install react-dom react-native-web @expo/metro-runtime').\n"
            . "   - Rode a checagem de tipos: 'npx tsc --noEmit', NÃO pode haver erro de tipo. Conserte até passar.\n"
            . "   - Rode 'npx expo export --platform web', TEM que concluir SEM erro e gerar /workspace/dist/index.html. Se falhar (ex.: uso de API incompatível com react-native-web), conserte o código até exportar limpo. Essa export é o que alimenta o preview.\n"
            . "2. APK ANDROID: NÃO gere o APK aqui (é demorado). O usuário gera o APK sob demanda, depois, por um botão na interface. Apenas garanta que o app está pronto para empacotar (compila e exporta).\n"
            . "3. SEGURANÇA (obrigatória): revise o que foi criado e CORRIJA, NENHUM segredo/API key no código do app (o bundle vai para o usuário), tokens em armazenamento seguro (expo-secure-store), toda entrada do usuário e resposta de API validada/tratada, chamadas externas por HTTPS, sem logar dados sensíveis.\n"
            . "4. DOCUMENTAÇÃO para quem vai BAIXAR e continuar (obrigatório):\n"
            . "   - README.md: o que é o app, telas/funcionalidades, stack (Expo/TypeScript) e como rodar ('npm install' e 'npx expo start').\n"
            . "   - INSTALL.md: passo a passo do zero, instalar Node/Expo, instalar dependências, rodar no Expo Go/emulador, e como gerar o APK (Android) e o build iOS.\n"
            . "5. ENTREGA LIMPA: monte o .zip final em /workspace/delivery/ contendo APENAS o projeto pronto (código-fonte, app.json/app.config, package.json, assets, README/INSTALL). INCLUA o .apk se foi gerado. EXCLUA OBRIGATORIAMENTE: node_modules/, .expo/, a pasta dist/ (export web, é regenerável), PLAN.md, PROGRESS.md, a pasta .maestro, caches e qualquer arquivo temporário. O que o programador baixar tem que estar LIMPO e PRONTO.\n"
            . "   ATENÇÃO: NÃO APAGUE PLAN.md, PROGRESS.md, .maestro nem a pasta dist/ do /workspace, são necessários para ajustes posteriores e para o preview. Apenas NÃO os inclua no .zip.\n"
            . "6. METADADOS (obrigatório): escreva /workspace/.maestro/app.json no formato exato: "
            . '{"type":"mobile","framework":"expo","language":"typescript","platforms":["android","ios"],"web_export":"dist","apk":"","has_web_ui":false}. '
            . "Preencha 'apk' com o caminho relativo do .apk em delivery/ se você o gerou (senão deixe vazio). Mantenha has_web_ui=false (o preview mobile é tratado pela plataforma à parte, não como app web).\n"
            . "7. Confira a DEFINIÇÃO DE PRONTO do /workspace/PLAN.md item a item.\n\n"
            . "SINAL DE CONCLUSÃO (obrigatório): SOMENTE quando estiver 100% pronto, tipos OK, export web gerando dist/index.html, segurança revisada, documentação completa, .zip LIMPO e app.json prontos, execute como ÚLTIMA ação:\n"
            . "    mkdir -p /workspace/.maestro && touch /workspace/.maestro/DELIVERY_COMPLETE\n"
            . "NÃO crie esse marcador antes de tudo estar realmente pronto. Se faltar qualquer coisa, continue até terminar.";
    }

    /** Sinal de cancelamento gravado pelo servidor WebSocket (botão Parar). */
    private function cancelled(string $ws): bool
    {
        return is_file($ws . '/.maestro/cancel');
    }

    /**
     * Garante que /workspace/.maestro existe e é gravável pelo usuário do container (uid sandbox).
     * O dir é criado pelo worker (www-data); sem 0777, o Claude Code (outro uid) não conseguia
     * criar o marcador DELIVERY_COMPLETE e gastava tempo contornando permissões.
     */
    private function ensureMaestroDir(string $ws): void
    {
        $dir = $ws . '/.maestro';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777); // mkdir respeita umask; força a permissão de fato
    }

    /**
     * Emite o estado do preview ao concluir: se já há um rodando para o projeto, manda o LINK
     * atualizado (mesmo link, código novo); senão, oferece o botão de abrir. Só quando há UI.
     */
    /**
     * Após a entrega: se o projeto está ligado ao Git, ou sincroniza sozinho (auto-sync)
     * ou marca mudanças pendentes, e avisa o chat. O push roda no worker (job git_sync).
     */
    private function emitGitState(string $uuid, string $summary = ''): void
    {
        try {
            $project = Project::findByUuid($uuid);
            if (!$project || !$project->gitEnabled()) {
                return;
            }
            $owner = $project->user();
            $ownerConnected = $owner && $owner->hasGithub();

            if ($project->git_auto_sync && $ownerConnected) {
                // Auto-sync: enfileira o push (não bloqueia a finalização do build).
                // Mensagem rica = resumo da entrega; autor = quem rodou o build (acting user).
                $summary = trim($summary);
                $message = $summary !== '' ? $summary : ('Atualização via Ravi Developer, ' . date('Y-m-d H:i'));
                $actor   = $this->actingUserId ?: (int) ($project->user_id ?? 0);
                \Ravi\Core\Database::getInstance()->query(
                    "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'git_sync', ?, 2)",
                    [uuid(), json_encode([
                        'project_id'   => $project->id,
                        'project_uuid' => $project->uuid,
                        'triggered_by' => $actor,
                        'message'      => mb_substr($message, 0, 2000),
                        'auto'         => true,
                    ], JSON_UNESCAPED_UNICODE)]
                );
                $this->emit(['type' => 'git_synced', 'auto' => true, 'repo' => $project->git_repo_full_name, 'syncing' => true]);
            } else {
                // Marca pendente para o usuário sincronizar sob demanda.
                $project->markGitPending();
                $this->emit([
                    'type'    => 'git_pending',
                    'repo'    => $project->git_repo_full_name,
                    'project' => $project->uuid,
                ]);
            }
        } catch (\Throwable $e) {
            // Git nunca quebra a entrega.
        }
    }

    /**
     * Deploy automático nas entregas: para cada fonte externa marcada como auto_deploy, enfileira
     * um rsync do workspace pro servidor remoto (job server_deploy). Best-effort, não trava a entrega.
     */
    private function emitServerDeploys(string $uuid): void
    {
        try {
            $project = Project::findByUuid($uuid);
            if (!$project) {
                return;
            }
            $servers = (new \Ravi\Services\ProjectContextService())->autoDeployServers($project);
            foreach ($servers as $s) {
                \Ravi\Core\Database::getInstance()->query(
                    "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'server_deploy', ?, 2)",
                    [uuid(), json_encode([
                        'project_id' => (int) $project->id,
                        'server_id'  => (int) $s->id,
                        // Conversa que disparou a entrega → o worker emite server_deployed/_failed
                        // de volta no chat (UI "Sincronizado no servidor X") quando o rsync termina.
                        'conv_id'    => $this->conv !== null ? (int) $this->conv->id : 0,
                    ], JSON_UNESCAPED_UNICODE)]
                );
                $this->emit(['type' => 'server_deploying', 'server' => (string) $s->name]);
            }
        } catch (\Throwable $e) {
            // Deploy nunca quebra a entrega.
        }
    }

    private function emitPreviewState(string $ws, string $uuid): void
    {
        // Web: só oferece preview se há UI. Mobile: sempre (o app exporta para web e abre em molduras).
        if (!$this->isMobile() && !$this->hasWebUi($ws)) {
            return;
        }
        $cur = null;
        try {
            $cur = (new PreviewService())->getByProject($uuid);
        } catch (\Throwable $e) {
            $cur = null;
        }
        if ($cur) {
            $this->emit([
                'type'    => 'preview_available',
                'running' => true,
                'mobile'  => $this->isMobile(),
                'url'     => $cur['url'],
                'demo'    => !empty($cur['demo_user']) ? ['user' => $cur['demo_user'], 'password' => $cur['demo_password']] : null,
            ]);
        } else {
            $this->emit(['type' => 'preview_available', 'mobile' => $this->isMobile()]);
        }
    }

    /** Há interface visual para abrir num link? (lê app.json; senão detecta public/index.*). */
    private function hasWebUi(string $ws): bool
    {
        $f = $ws . '/.maestro/app.json';
        if (is_file($f)) {
            $m = json_decode((string) file_get_contents($f), true);
            if (is_array($m)) {
                return !empty($m['has_web_ui']);
            }
        }
        $docroot = is_dir($ws . '/public') ? 'public' : '.';
        return is_file($ws . '/' . $docroot . '/index.php') || is_file($ws . '/' . $docroot . '/index.html');
    }

    private function finishCancelled(string $stateFile, array $state): array
    {
        $this->saveState($stateFile, array_merge($state, ['phase' => 'cancelled']));
        $this->emit(['type' => 'status', 'message' => 'Execução cancelada.']);
        return ['stage' => 'execution', 'completed' => false, 'cancelled' => true, 'summary' => 'Execução cancelada pelo usuário.'];
    }

    /** Instrução do portão de ENTREGA. Mobile (app/both) verifica tipos+export web; web fica INALTERADO. */
    private function buildDeliveryGateInstruction(): string
    {
        if ($this->isMobile()) {
            return 'Confira de forma objetiva, RODANDO os comandos de verdade (não basta ler o código). É um app React Native + Expo (TypeScript): '
                . '(a) PROVE QUE COMPILA E EXPORTA, rode "npx tsc --noEmit" (NÃO pode haver erro de tipo) e "npx expo export --platform web" (TEM que concluir sem erro e gerar /workspace/dist/index.html). Se a export não gerar o dist/index.html ou houver erro de tipo, passed=false e descreva o erro; '
                . '(b) SEGURANÇA revisada e SEM falhas óbvias no que foi criado: NENHUM segredo/API key versionado no código do app, tokens em armazenamento seguro, entrada do usuário e respostas de API validadas/tratadas, chamadas externas por HTTPS. Se achar uma brecha, passed=false; '
                . '(c) existe um .zip em /workspace/delivery/; '
                . '(d) o .zip contém README.md e INSTALL.md (ou equivalente); '
                . '(e) o .zip está LIMPO: NÃO contém node_modules/, .expo/, dist/, PLAN.md, PROGRESS.md, .maestro nem caches; '
                . '(f) existe /workspace/.maestro/app.json válido com type="mobile" e web_export="dist"; '
                . '(g) ENXUTO: a estrutura é proporcional ao pedido (sem dezenas de telas/arquivos para algo pequeno). '
                . 'passed=true SÓ se TODOS conferem, em especial (a) e (b); senão liste exatamente o que falhou.';
        }

        return 'Confira de forma objetiva, RODANDO o sistema de verdade (não basta ler o código): '
            . '(a) PROVE QUE RODA E O FLUXO FUNCIONA, se for app WEB: execute o setup_command do app.json (migração/seed) e suba o servidor com o EXATO start_command do app.json (substituindo $PORT), INCLUSIVE o roteador, se houver (ex.: "php -S 127.0.0.1:PORTA -t public public/index.php"); é assim que o PREVIEW roda, então teste IGUAL. Acesse VIA HTTP (curl): COMECE pela RAIZ "curl -i http://127.0.0.1:PORTA/", TEM que devolver 200 ou 302 (NUNCA 404). ASSETS (passo decisivo p/ o preview não sair sem estilo): pegue os CSS/JS referenciados no HTML da home e baixe cada um via HTTP conferindo o content-type, CSS tem que vir "text/css" e JS "application/javascript"; se vier "text/html" (o roteador engoliu o estático), o CSS NÃO aplica e a UI quebra no preview → passed=false (e o conserto é o guard cli-server no index.php). Depois exercite a tela de login, faça um LOGIN REAL com as demo_credentials, acesse a área protegida e o logout. NENHUMA pode retornar 404/500/erro fatal, e o login PRECISA autenticar. Se for CLI, execute o comando real. Se QUALQUER passo falhar, passed=false e descreva o erro; '
            . '(b) SEGURANÇA revisada e SEM falhas óbvias no que foi criado: SQL sempre por prepared statements (sem concatenação), entrada/saída validada e escapada (anti-XSS), CSRF nos formulários, senhas com hash, rotas protegidas de fato exigindo sessão. Se achar uma brecha, passed=false; '
            . '(c) existe um .zip em /workspace/delivery/; '
            . '(d) o .zip contém README.md e INSTALL.md (ou um instalador equivalente); '
            . '(e) o .zip está LIMPO: NÃO contém PLAN.md, PROGRESS.md, .maestro, bancos de teste com dados, sessões de runtime, caches, vendor/, node_modules nem .env real; '
            . '(f) existe /workspace/.maestro/app.json válido com setup_command e start_command corretos; '
            . '(g) ENXUTO: para tarefa pequena NÃO pode ter sido instalado um framework de teste pesado (ex.: PHPUnit/phpunit em vendor/, composer.lock só por causa de teste), se houver esse inchaço para algo simples, remova-o (basta um script de verificação curto) e passed=false até limpar. passed=true SÓ se TODOS conferem, em especial (a) e (b); senão liste exatamente o que falhou.';
    }

    /**
     * Portão: roda um verificador cético (quiet) que devolve APENAS um JSON {passed, details}.
     */
    private function verifyGate(string $ws, string $uuid, string $label, string $gateInstruction): array
    {
        $prompt = "Você é um VERIFICADOR cético e independente. NÃO implemente nada novo, apenas verifique.\n\n"
            . $gateInstruction . "\n\n"
            . "Responda EXCLUSIVAMENTE com um objeto JSON válido, sem texto antes ou depois, no formato:\n"
            . '{"passed": true|false, "details": "explicação curta do que verificou e do resultado"}';

        try {
            $result  = $this->cc->run($ws, $uuid, $prompt, [
                'quiet'  => true,
                'model'  => $this->modelForAgents(),
                'system' => 'Você é um auditor rigoroso. Só responde JSON. Na dúvida, passed=false.',
            ]);
            $verdict = $this->parseJsonVerdict((string) ($result['summary'] ?? ''));
            if ($verdict !== null) {
                return $verdict;
            }
            return ['passed' => false, 'details' => 'Verificador não retornou JSON válido: ' . mb_substr((string) ($result['summary'] ?? ''), 0, 200)];
        } catch (\Throwable $e) {
            return ['passed' => false, 'details' => 'Falha ao rodar o verificador: ' . $e->getMessage()];
        }
    }

    /** Extrai o primeiro objeto JSON de um texto (tolera cercas ```json e texto ao redor). */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    private function parseJsonVerdict(string $text): ?array
    {
        $data = $this->parseJson($text);
        if (!is_array($data) || !array_key_exists('passed', $data)) {
            return null;
        }
        return [
            'passed'  => (bool) $data['passed'],
            'details' => (string) ($data['details'] ?? ''),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Estado + helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function isApproval(string $message): bool
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') {
            return false;
        }
        $exact = ['ok', 'sim', 'pode', 'vai', 'isso', 'perfeito', 'confirmo', 'confirmar', 'go', '👍', 'manda', 'bora', 'segue'];
        if (in_array($m, $exact, true)) {
            return true;
        }
        return (bool) preg_match('/\b(aprov\w*|approve\w*|pode\s+(ir|seguir|executar|mandar)|manda\s+ver|executa\w*|segue|vai\s+l[aá]|come[çc]a|bora)\b/u', $m);
    }

    private function loadState(string $stateFile): ?array
    {
        if (!is_file($stateFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($stateFile), true);
        return is_array($data) ? $data : null;
    }

    private function saveState(string $stateFile, array $state): void
    {
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
