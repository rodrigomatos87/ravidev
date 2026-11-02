<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Setting;
use Ravi\Models\UserPref;

/**
 * Resolve qual modelo (Opus/Sonnet) usar, SEPARADO por escopo: a IDE e o Dev Autônomo (chat/Maestro)
 * podem ter modelos diferentes. Precedência por escopo: preferência do USUÁRIO → padrão GLOBAL (admin)
 * → fallback do .env (CLAUDE_CODE_MODEL). Opus 5.5 / Opus 5 / Opus 4.8 / Fable 5.1 / Fable 5 / Sonnet 5 / Sonnet 4.6 (todos até 1M de contexto).
 *
 * IMPORTANTE: usar SEMPRE ids de modelo que o `claude` das imagens (ravi-sandbox/ravi-ide) realmente
 * serve. O id "claude-sonnet-4-6" só é reconhecido pelo CLI >= 2.1.170; em versões antigas (ex.: 2.1.161)
 * a flag --model com esse id TRAVA (timeout, sem erro) em vez de recusar — foi o que derrubou o Claude
 * na IDE até atualizarmos o CLI das imagens para 2.1.185 (jun/2026). Se um dia trocar o id do Sonnet,
 * confirme antes que o CLI embutido nas imagens conhece o novo id (senão volta a travar).
 */
class ModelService
{
    /** Modelos oferecidos no seletor (id => rótulo). */
    public const MODELS = [
        'claude-fable-5-1'  => 'Fable 5.1',
        'claude-fable-5'    => 'Fable 5',
        'claude-opus-5-5'   => 'Opus 5.5',
        'claude-opus-5'     => 'Opus 5',
        'claude-opus-4-8'   => 'Opus 4.8',
        'claude-sonnet-5'   => 'Sonnet 5',
        'claude-sonnet-4-6' => 'Sonnet 4.6',
    ];

    /** Escopos independentes (chave => rótulo). */
    public const SCOPES = [
        'ide'        => 'IDE',
        'autonomous' => 'Dev Autônomo',
        'autonomous_agents' => 'Agentes do Dev Autônomo',
    ];

    /**
     * Escopo dos AGENTES do Dev Autônomo (especialistas das ondas, verificador do portão, QA).
     * O Arquiteto (cérebro que conversa/planeja/integra) segue o escopo 'autonomous'. Este escopo
     * é o único com HERANÇA: sem escolha do usuário e sem padrão global, os agentes usam o MESMO
     * modelo do Dev Autônomo do usuário (comportamento de sempre).
     */
    public const SCOPE_AGENTS = 'autonomous_agents';

    /**
     * Modelos cuja janela de 1M de contexto está SEMPRE incluída na conta (sem custo extra) → pedimos
     * [1m] sempre. Opus 4.8 no Max é 1M incluído; Fable 5 tem 1M como janela padrão do modelo;
     * Opus 5.5 tem janela de 1M como padrão (lançamento 22/09/2026, CLI >= 2.1.280).
     */
    public const ONE_M_ALWAYS = ['claude-opus-4-8', 'claude-opus-5', 'claude-opus-5-5', 'claude-fable-5', 'claude-fable-5-1'];

    /** Setting global (admin) que liga o 1M OPCIONAL do Sonnet (exige usage credits na conta). */
    public const SONNET_1M_KEY = 'sonnet_1m';

    /** O 1M do Sonnet (5 e 4.6) está ligado no painel? (padrão desligado = 200k). */
    public static function sonnet1mEnabled(): bool
    {
        return (string) (Setting::get(self::SONNET_1M_KEY, '0') ?? '0') === '1';
    }

    /**
     * Esse modelo deve PEDIR a janela de 1M (sufixo [1m] do Claude Code; sem ele fica em 200k — NÃO é
     * automático)? Opus: sempre (incluído). Sonnet 5/4.6: só quando o admin liga o toggle `sonnet_1m`
     * (e a conta tem usage credits — senão o claude FALHA com "Usage credits required for 1M context").
     */
    public static function wants1m(string $id): bool
    {
        if (in_array($id, self::ONE_M_ALWAYS, true)) {
            return true;
        }
        if ($id === 'claude-sonnet-5' || $id === 'claude-sonnet-4-6') {
            return self::sonnet1mEnabled();
        }
        return false;
    }

    /**
     * Id do modelo no formato que o CLI do Claude Code espera, com o sufixo [1m] quando a janela de 1M
     * está disponível/ligada para aquele modelo (ver wants1m). É o que vai para ANTHROPIC_MODEL /
     * CLAUDE_CODE_MODEL (IDE) e para --model (Dev Autônomo). O id "cru" (sem [1m]) continua sendo o que
     * é salvo/escolhido/exibido e o que vai para o ledger de custo — o [1m] é só a flag de contexto.
     */
    public static function cliModel(string $id): string
    {
        return self::wants1m($id) ? $id . '[1m]' : $id;
    }

    /**
     * Linhas do /model picker do Claude Code (chave `modelPicker` do settings.json, CLI >= 2.1.258):
     * a lineup COMPLETA do Ravi com nossos rótulos, para que todo modelo do seletor apareça como
     * opção dentro da IDE (extensão E terminal), independente de qual é o padrão da sessão.
     * Substitui o mecanismo antigo do ANTHROPIC_CUSTOM_MODEL_OPTION (uma entrada só).
     */
    public static function pickerOptions(): array
    {
        $rows = [];
        foreach (self::MODELS as $id => $label) {
            $rows[] = ['model' => self::cliModel($id), 'label' => $label];
        }
        return $rows;
    }

    public static function isScope(string $scope): bool
    {
        return isset(self::SCOPES[$scope]);
    }

    /** Chave do padrão global (app_settings) por escopo. */
    public static function globalKey(string $scope): string
    {
        return 'default_model_' . $scope;
    }

    /** Chave da preferência por usuário (user_prefs) por escopo. */
    public static function userKey(string $scope): string
    {
        return 'model_' . $scope;
    }

    /** Fallback final: o que está no .env (hoje claude-opus-5). */
    public static function fallback(): string
    {
        $m = (string) env('CLAUDE_CODE_MODEL', 'claude-opus-5');
        return isset(self::MODELS[$m]) ? $m : 'claude-opus-5';
    }

    /** Padrão global do sistema (admin) para um escopo. Sem config → fallback do .env. */
    public static function globalDefault(string $scope): string
    {
        $m = self::isScope($scope) ? (string) (Setting::get(self::globalKey($scope), '') ?? '') : '';
        return isset(self::MODELS[$m]) ? $m : self::fallback();
    }

    /**
     * Modelo efetivo de um usuário para um escopo: pref própria → global → fallback.
     * Exceção (SCOPE_AGENTS): pref própria → global do escopo → HERDA o Dev Autônomo do usuário.
     */
    public static function forUser(int $userId, string $scope): string
    {
        if (!self::isScope($scope)) {
            return self::fallback();
        }
        if ($userId > 0) {
            $u = UserPref::getRaw($userId, self::userKey($scope), '');
            if (isset(self::MODELS[$u])) {
                return $u;
            }
        }
        if ($scope === self::SCOPE_AGENTS) {
            $g = self::globalPref($scope);
            return $g !== '' ? $g : self::forUser($userId, 'autonomous');
        }
        return self::globalDefault($scope);
    }

    /** Padrão global CRU de um escopo ('' = não configurado; no SCOPE_AGENTS, '' = herdar). */
    public static function globalPref(string $scope): string
    {
        $m = self::isScope($scope) ? (string) (Setting::get(self::globalKey($scope), '') ?? '') : '';
        return isset(self::MODELS[$m]) ? $m : '';
    }

    /** Preferência crua do usuário para um escopo ('' = herdar o global). */
    public static function userPref(int $userId, string $scope): string
    {
        $u = ($userId > 0 && self::isScope($scope)) ? UserPref::getRaw($userId, self::userKey($scope), '') : '';
        return isset(self::MODELS[$u]) ? $u : '';
    }

    public static function isValid(string $id): bool
    {
        return isset(self::MODELS[$id]);
    }

    public static function label(string $id): string
    {
        return self::MODELS[$id] ?? $id;
    }
}
