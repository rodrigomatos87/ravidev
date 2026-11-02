<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\JsonResponse;
use Ravi\Core\Request;
use Ravi\Models\UserPref;

/**
 * Preferências da casca por usuário (idioma, tema, favoritos do menu, grupos abertos, menu
 * recolhido, disposição das janelas). Guardadas em user_prefs; a casca lê tudo de uma vez
 * pelo layout (RAVI_CASCA.preferencias) e grava uma chave por vez.
 */
class PreferenciaController extends Controller
{
    /** Chaves aceitas e o tamanho máximo do valor (JSON ou texto curto). */
    public const CHAVES = [
        'idioma'         => 8,
        'tema'           => 8,
        'favoritos'      => 4000,
        'grupos_abertos' => 4000,
        'menu_recolhido' => 1,
        'disposicao'     => 60000,
        'disposicoes'    => 120000,
    ];

    /** Preferências da casca de um usuário, já decodificadas. */
    public static function daCasca(int $userId): array
    {
        $todas = UserPref::allFor($userId);
        $saida = [];
        foreach (self::CHAVES as $chave => $max) {
            if (!array_key_exists('casca_' . $chave, $todas)) {
                continue;
            }
            $v = (string) $todas['casca_' . $chave];
            $dec = json_decode($v, true);
            $saida[$chave] = json_last_error() === JSON_ERROR_NONE ? $dec : $v;
        }
        return $saida;
    }

    public function salvar(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $chave = (string) $request->input('chave', '');
        if (!isset(self::CHAVES[$chave])) {
            return $this->jsonError('Preferência desconhecida.', 422);
        }
        $valor = $request->input('valor');
        if ($valor === null) {
            $valor = '';
        }
        $texto = is_string($valor) ? $valor : (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($texto) > self::CHAVES[$chave]) {
            return $this->jsonError('Valor grande demais.', 422);
        }
        if ($chave === 'idioma' && !in_array($texto, \Ravi\Core\Lang::available(), true)) {
            return $this->jsonError('Idioma indisponível.', 422);
        }
        if ($chave === 'tema' && !in_array($texto, ['auto', 'claro', 'escuro'], true)) {
            return $this->jsonError('Tema inválido.', 422);
        }
        UserPref::putRaw((int) $this->user->id, 'casca_' . $chave, $texto);
        return $this->json(['chave' => $chave, 'valor' => $valor], 'Preferência salva.');
    }
}
