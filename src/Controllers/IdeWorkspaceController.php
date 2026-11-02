<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\JsonResponse;
use Ravi\Services\IdeService;

/**
 * Página /ide — área de trabalho com ABAS de IDE (uma por projeto aberto do usuário).
 * Em vez de abrir o VS Code numa aba do navegador, embute cada IDE num iframe e mostra um topo
 * com o nome do projeto, bolinha verde quando está processando, alternar tema e voltar ao dashboard.
 */
class IdeWorkspaceController extends Controller
{
    /** Renderiza a área de IDEs com as sessões abertas do usuário. */
    public function index(Request $request): Response
    {
        $this->requireAuth($request);
        $ide = new IdeService();
        // Religa as IDEs PAUSADAS do usuário (deixou abertas, fechou o navegador e voltou) ANTES de
        // listar — assim elas reaparecem como abas ativas, exatamente como foram deixadas.
        $ide->resumeUserPaused((int) $this->user->id);
        $sessions = $ide->userSessions((int) $this->user->id);
        $active = (string) $request->query('active', '');
        // Conta do Claude na barra da IDE (engrenagem): só para quem o admin liberou a escolha.
        // Mesmos dados dos cards do perfil; a troca é aplicada nas IDEs abertas pelo reconcile.
        $uid = (int) $this->user->id;
        $licenseChoice  = \Ravi\Services\LicenseService::selfChoiceEnabled($uid);
        $licenseOptions = [];
        $globalLicense  = null;
        if ($licenseChoice) {
            foreach (\Ravi\Services\LicenseService::all() as $l) {
                if (!empty($l['is_active'])) {
                    $licenseOptions[] = ['id' => (int) $l['id'], 'name' => (string) $l['name']];
                }
            }
            $gid = \Ravi\Services\LicenseService::globalId('ide');
            if ($gid !== null) {
                $g = \Ravi\Services\LicenseService::find($gid);
                if ($g && $g['is_active']) {
                    $globalLicense = ['id' => (int) $g['id'], 'name' => (string) $g['name']];
                }
            }
        }
        // Sem escolha liberada, a engrenagem ainda aparece (somente leitura) quando a conta
        // efetiva da IDE tem TETO de uso configurado pelo admin — para o usuário acompanhar.
        $limitCard = null;
        if (!$licenseChoice) {
            $gid = \Ravi\Services\LicenseService::licenseIdForUser($uid, 'ide');
            if ($gid !== null) {
                $lim = \Ravi\Services\LicenseService::usageLimitPct($gid);
                if ($lim !== null) {
                    $limitCard = ['id' => $gid, 'limit' => $lim];
                }
            }
        }
        return $this->view('ide/workspace', [
            'sessions' => $sessions,
            'active'   => $active,
            'title'    => 'IDE',
            'licenseChoice'  => $licenseChoice,
            'licenseOptions' => $licenseOptions,
            'globalLicense'  => $globalLicense,
            'userLicenseId'  => $licenseChoice ? \Ravi\Services\LicenseService::userIdFor($uid, 'ide') : null,
            'limitCard'      => $limitCard,
        ]);
    }

    /**
     * Lista as IDEs abertas do usuário (JSON) — para atualizar as abas e as bolinhas verdes.
     * Com `visible=1` (a página /ide está na frente do usuário) isso também CONTA COMO USO de todas
     * as IDEs dele (adia o standby) e religa as que porventura já estejam pausadas, para a página
     * recompor a aba em vez de a IDE sumir.
     */
    public function sessions(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $ide = new IdeService();
        $uid = (int) $this->user->id;
        $resumed = [];
        if ((string) $request->query('visible', '') === '1') {
            $resumed = $ide->resumeUserPaused($uid);
            $ide->touchUser($uid);
        }
        return $this->json(['ok' => true, 'sessions' => $ide->userSessions($uid), 'resumed' => $resumed]);
    }

    /**
     * Projetos que o usuário pode abrir na IDE (dono + compartilhados, ativos) — alimenta o modal
     * do botão "+" na barra de abas, para abrir outra IDE sem sair da página (sem perder sessões).
     */
    public function projects(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $projects = \Ravi\Models\Project::findByUser((int) $this->user->id, 'active');
        $out = array_map(static fn($p) => [
            'uuid' => (string) $p->uuid,
            'name' => (string) $p->name,
        ], $projects);
        return $this->json(['ok' => true, 'projects' => $out]);
    }

    /**
     * Prontidão de cada IDE do usuário (container no ar + code-server servindo HTTP) — para o
     * preloader da página /ide só revelar o iframe quando o ambiente está pronto.
     */
    public function ready(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        return $this->json(['ok' => true, 'ready' => (new IdeService())->readiness((int) $this->user->id)]);
    }

    /**
     * Tira do standby as IDEs pausadas do usuário — chamado pela página /ide quando ela volta ao
     * foco (você trocou de aba/voltou). Devolve os uuids retomados para a UI recarregar só esses.
     */
    public function resume(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        return $this->json(['ok' => true, 'resumed' => (new IdeService())->resumeUserPaused((int) $this->user->id)]);
    }

    /**
     * Recebe os incrementos de TEMPO TRABALHADO da página da IDE (enquanto a bolinha verde aparece).
     * Body: deltas[projectUuid] = segundos. Soma no acumulado do usuário (ide_work_seconds).
     */
    public function worktime(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $deltas = (array) $request->input('deltas', []);
        $totals = (new IdeService())->addWorkSeconds((int) $this->user->id, $deltas);
        return $this->json(['ok' => true, 'totals' => $totals]);
    }
}
