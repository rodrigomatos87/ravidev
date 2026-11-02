<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\{User, UserPref, Project, Conversation};

/**
 * Envia notificações por e-mail respeitando a preferência (toggle) de cada usuário
 * e a configuração de SMTP. Tudo é "best-effort": qualquer falha é logada e NUNCA
 * interrompe o fluxo que chamou (worker, share, criação de usuário).
 */
class NotificationService
{
    private function appUrl(): string
    {
        return rtrim((string) env('APP_URL', ''), '/');
    }

    /** Envia se o usuário existe, tem a pref ligada e o SMTP está configurado. */
    private function deliver(int $userId, string $prefKey, string $subject, string $heading, string $bodyHtml, string $bodyText, ?string $ctaUrl = null, string $ctaLabel = 'Abrir'): void
    {
        try {
            if ($userId <= 0 || !UserPref::enabled($userId, $prefKey)) {
                return;
            }
            $user = User::find($userId);
            if (!$user || empty($user->email) || !$user->is_active) {
                return;
            }
            $mail = new EmailService();
            if (!$mail->isConfigured()) {
                return;
            }

            $cta = $ctaUrl
                ? '<p style="margin:24px 0"><a href="' . htmlspecialchars($ctaUrl) . '" style="background:#7c6cff;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">' . htmlspecialchars($ctaLabel) . '</a></p>'
                : '';
            $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
                  . '<h2 style="color:#7c6cff">' . htmlspecialchars($heading) . '</h2>'
                  . $bodyHtml . $cta
                  . '<p style="color:#888;font-size:12px;margin-top:28px">Você recebe este aviso porque a notificação "' . htmlspecialchars(UserPref::NOTIFY[$prefKey] ?? $prefKey) . '" está ligada. Desligue em Configurações → Notificações.</p>'
                  . '</div>';
            $text = $heading . "\n\n" . strip_tags($bodyText) . ($ctaUrl ? "\n\n" . $ctaUrl : '');

            $mail->send((string) $user->email, $subject, $html, $text);
        } catch (\Throwable $e) {
            logger()->error('NotificationService falhou (' . $prefKey . '): ' . $e->getMessage());
        }
    }

    /** Envio best-effort por WhatsApp (não interrompe nada se falhar / não configurado). */
    private function sendWhatsApp(int $userId, string $alertType, string $text): void
    {
        try {
            (new WhatsAppService())->sendNotification($userId, $alertType, $text);
        } catch (\Throwable $e) {
            logger()->error('NotificationService WhatsApp falhou (' . $alertType . '): ' . $e->getMessage());
        }
    }

    public function taskCompleted(int $userId, Conversation $conv): void
    {
        $project = Project::find((int) $conv->project_id);
        $pname   = $project->name ?? 'seu projeto';
        $url     = $project ? $this->appUrl() . '/chat/' . $project->uuid . '/' . $conv->uuid : null;
        $this->sendWhatsApp(
            $userId,
            'task_completed',
            "✅ Ravi Developer: tarefa concluída em \"{$pname}\""
                . (!empty($conv->title) ? " ({$conv->title})" : '') . '. A entrega está pronta.'
                . ($url ? "\n" . $url : '')
        );
        $this->deliver(
            $userId,
            'notify_task_done',
            'Tarefa concluída — ' . $pname,
            'Sua tarefa foi concluída',
            '<p>O agente terminou de trabalhar em <strong>' . htmlspecialchars($pname) . '</strong>'
                . (!empty($conv->title) ? ' (' . htmlspecialchars((string) $conv->title) . ')' : '') . '.</p>'
                . '<p>A entrega está pronta para você revisar e baixar.</p>',
            'O agente terminou de trabalhar em ' . $pname . '. A entrega está pronta.',
            $url,
            'Ver resultado'
        );
    }

    /**
     * IDE: um trabalho que durou mais que o limite do usuário terminou. Avisa por WhatsApp
     * diferenciando "pausou aguardando você" (waiting, bolinha azul) de "finalizou" (idle).
     * Respeita o toggle notify_ide_long (default ligado). WhatsApp apenas (é um ping de "voltei?").
     *
     * @param string $finalState 'waiting' (precisa de você) | 'idle' (finalizou)
     */
    public function ideLongJobEnded(int $userId, string $projectName, string $projectUuid, string $finalState, int $minutes): void
    {
        if ($userId <= 0 || !UserPref::enabled($userId, 'notify_ide_long')) {
            return;
        }
        $pname = $projectName !== '' ? $projectName : 'seu projeto';
        $url   = $this->appUrl() . '/ide?active=' . $projectUuid;
        if ($finalState === 'waiting') {
            $msg = "🔵 Ravi Developer (IDE): o Claude pausou e está aguardando você responder em \"{$pname}\""
                 . " (trabalhou {$minutes} min).\n" . $url;
        } else {
            $msg = "🟢 Ravi Developer (IDE): o Claude finalizou o trabalho em \"{$pname}\""
                 . " (durou {$minutes} min).\n" . $url;
        }
        $this->sendWhatsApp($userId, 'ide_long_running', $msg);
    }

    /**
     * Alerta de INFRAESTRUTURA: o uso de disco ou RAM do servidor cruzou o limite configurado
     * (ou normalizou). Vai para TODOS os admins ativos por WhatsApp (tipo 'system_resource', respeita
     * o toggle do admin) e também por e-mail direto (sem toggle: é um aviso de operação). Best-effort.
     *
     * @param string $metric    'disk' (disco) | 'ram' (memória)
     * @param int    $pct       uso atual em %
     * @param int    $threshold limite configurado em %
     * @param string $detail    linha extra (ex.: "Livre: 8 GB de 97 GB")
     * @param bool   $recovered true = voltou ao normal; false = passou do limite
     */
    public function systemResourceAlert(string $metric, int $pct, int $threshold, string $detail = '', bool $recovered = false): void
    {
        try {
            $name = $metric === 'ram' ? 'RAM' : 'disco';
            $url  = $this->appUrl() . '/admin/monitoring';

            if ($recovered) {
                $waText   = "✅ Ravi Developer (servidor): uso de {$name} normalizou, agora em {$pct}% (limite {$threshold}%).";
                $subject  = "Servidor: {$name} normalizou ({$pct}%)";
                $heading  = "Uso de {$name} normalizou";
                $bodyHtml = '<p>O uso de <strong>' . $name . '</strong> do servidor voltou ao normal: <strong>'
                          . $pct . '%</strong> (limite ' . $threshold . '%).</p>';
            } else {
                $waText   = "🔴 Ravi Developer (servidor): uso de {$name} em {$pct}%, acima do limite de {$threshold}%."
                          . ($detail !== '' ? "\n" . $detail : '');
                $subject  = "Servidor: {$name} em {$pct}% (limite {$threshold}%)";
                $heading  = "Alerta de uso de {$name}";
                $bodyHtml = '<p>O uso de <strong>' . $name . '</strong> do servidor está em <strong>'
                          . $pct . '%</strong>, acima do limite de ' . $threshold . '%.</p>'
                          . ($detail !== '' ? '<p style="color:#555;font-size:13px">' . htmlspecialchars($detail) . '</p>' : '');
            }

            $mail   = new EmailService();
            $mailOk = $mail->isConfigured();
            $admins = User::where('role', '=', 'admin')->where('is_active', '=', 1)->get();
            foreach ($admins as $admin) {
                $aid = (int) $admin->id;
                $this->sendWhatsApp($aid, 'system_resource', $waText . "\n" . $url);
                if ($mailOk && !empty($admin->email)) {
                    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
                          . '<h2 style="color:#7c6cff">' . htmlspecialchars($heading) . '</h2>'
                          . $bodyHtml
                          . '<p style="margin:24px 0"><a href="' . htmlspecialchars($url) . '" style="background:#7c6cff;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Abrir monitoramento</a></p>'
                          . '</div>';
                    $mail->send((string) $admin->email, 'Ravi Developer: ' . $subject, $html, strip_tags($bodyHtml) . "\n" . $url);
                }
            }
        } catch (\Throwable $e) {
            logger()->error('NotificationService::systemResourceAlert falhou: ' . $e->getMessage());
        }
    }

    public function taskFailed(int $userId, Conversation $conv, string $error = ''): void
    {
        $project = Project::find((int) $conv->project_id);
        $pname   = $project->name ?? 'seu projeto';
        $url     = $project ? $this->appUrl() . '/chat/' . $project->uuid . '/' . $conv->uuid : null;
        $this->sendWhatsApp(
            $userId,
            'task_failed',
            "⚠️ Ravi Developer: a tarefa em \"{$pname}\" falhou."
                . ($error !== '' ? "\n" . mb_substr($error, 0, 180) : '')
                . ($url ? "\n" . $url : '')
        );
        $this->deliver(
            $userId,
            'notify_task_failed',
            'Falha na tarefa — ' . $pname,
            'Uma tarefa falhou',
            '<p>A tarefa em <strong>' . htmlspecialchars($pname) . '</strong> não pôde ser concluída.</p>'
                . ($error !== '' ? '<p style="color:#b91c1c;font-size:13px">' . htmlspecialchars(mb_substr($error, 0, 240)) . '</p>' : '')
                . '<p>Abra o chat para ver os detalhes e tentar novamente.</p>',
            'A tarefa em ' . $pname . ' falhou. Abra o chat para ver os detalhes.',
            $url,
            'Abrir o chat'
        );
    }

    public function projectShared(int $memberUserId, Project $project, string $sharedByName): void
    {
        $url = $this->appUrl() . '/projects/' . $project->uuid;
        $this->deliver(
            $memberUserId,
            'notify_shared',
            'Projeto compartilhado com você — ' . ($project->name ?? ''),
            'Um projeto foi compartilhado com você',
            '<p><strong>' . htmlspecialchars($sharedByName) . '</strong> compartilhou o projeto <strong>'
                . htmlspecialchars((string) $project->name) . '</strong> com você.</p>'
                . '<p>Ele já aparece na sua conta com acesso total.</p>',
            $sharedByName . ' compartilhou o projeto ' . $project->name . ' com você.',
            $url,
            'Abrir projeto'
        );
    }

    /** Avisa o novo dono de que a titularidade de um projeto foi transferida para ele. */
    public function projectTransferred(int $newOwnerUserId, Project $project, string $fromName): void
    {
        $url = $this->appUrl() . '/projects/' . $project->uuid;
        $this->deliver(
            $newOwnerUserId,
            'notify_shared',
            'Você agora é o dono do projeto — ' . ($project->name ?? ''),
            'Titularidade de projeto transferida para você',
            '<p><strong>' . htmlspecialchars($fromName) . '</strong> transferiu a titularidade do projeto <strong>'
                . htmlspecialchars((string) $project->name) . '</strong> para você.</p>'
                . '<p>Você passa a ter controle total: configurações, membros e exclusão.</p>',
            $fromName . ' transferiu a titularidade do projeto ' . $project->name . ' para você.',
            $url,
            'Abrir projeto'
        );
    }

    /**
     * Novo auto-cadastro pendente: avisa TODOS os admins ativos para analisarem.
     * Não passa pelo toggle (é controle de acesso, sempre enviado).
     */
    public function registrationPending(User $newUser): void
    {
        try {
            $mail = new EmailService();
            if (!$mail->isConfigured()) {
                return;
            }
            $url  = $this->appUrl() . '/admin/users';
            $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
                  . '<h2 style="color:#7c6cff">Novo cadastro para aprovar</h2>'
                  . '<p><strong>' . htmlspecialchars((string) $newUser->name) . '</strong> ('
                  . htmlspecialchars((string) $newUser->email) . ') solicitou acesso e está <strong>aguardando aprovação</strong>.</p>'
                  . '<p style="margin:24px 0"><a href="' . htmlspecialchars($url) . '" style="background:#7c6cff;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Analisar cadastro</a></p>'
                  . '</div>';
            $text = (string) $newUser->name . ' (' . (string) $newUser->email . ') solicitou acesso e aguarda aprovação. Analise em: ' . $url;

            $admins = User::where('role', '=', 'admin')->where('is_active', '=', 1)->get();
            foreach ($admins as $admin) {
                if (empty($admin->email)) {
                    continue;
                }
                $mail->send((string) $admin->email, 'Novo cadastro para aprovar — ' . (string) $newUser->name, $html, $text);
            }
        } catch (\Throwable $e) {
            logger()->error('NotificationService::registrationPending falhou: ' . $e->getMessage());
        }
    }

    /** Avisa o usuário que o acesso foi aprovado (sempre enviado). */
    public function approvalGranted(User $user): void
    {
        try {
            if (empty($user->email)) {
                return;
            }
            $mail = new EmailService();
            if (!$mail->isConfigured()) {
                return;
            }
            $mail->sendAccountApproved((string) $user->email, (string) ($user->name ?: $user->email), $this->appUrl() . '/login');
        } catch (\Throwable $e) {
            logger()->error('NotificationService::approvalGranted falhou: ' . $e->getMessage());
        }
    }

    /** Avisa todos os admins ativos (com a pref ligada), exceto quem criou. */
    public function userCreated(User $newUser, int $byUserId = 0): void
    {
        try {
            $admins = User::where('role', '=', 'admin')->where('is_active', '=', 1)->get();
            foreach ($admins as $admin) {
                if ((int) $admin->id === $byUserId) {
                    continue;
                }
                $this->deliver(
                    (int) $admin->id,
                    'notify_new_user',
                    'Novo usuário criado — ' . ($newUser->name ?? ''),
                    'Novo usuário na plataforma',
                    '<p>A conta <strong>' . htmlspecialchars((string) $newUser->name) . '</strong> ('
                        . htmlspecialchars((string) $newUser->email) . ') foi criada.</p>',
                    'A conta ' . $newUser->name . ' (' . $newUser->email . ') foi criada.',
                    $this->appUrl() . '/admin/users',
                    'Ver usuários'
                );
            }
        } catch (\Throwable $e) {
            logger()->error('NotificationService::userCreated falhou: ' . $e->getMessage());
        }
    }
}
