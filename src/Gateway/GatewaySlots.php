<?php

declare(strict_types=1);

namespace Ravi\Gateway;

/**
 * Semáforo de concorrência do backend CLI do gateway.
 *
 * Cada POST /v1/messages servido pelo CLI dispara um `docker run` com teto de 1GB —
 * o cap do container protege UM container, não o agregado: ~20 simultâneos levariam
 * o host (24GB) à OOM. Este semáforo limita quantos containers existem AO MESMO
 * TEMPO no host inteiro, entre todos os workers do PHP-FPM.
 *
 * Implementação: N arquivos de slot com flock(LOCK_EX|LOCK_NB). flock é liberado
 * pelo kernel se o processo morrer — não há como "vazar" slot. Quem não consegue
 * slot espera até GW_SLOT_WAIT segundos (fila curta) e então recebe 529.
 *
 * Faixa LONGA (20/10/2026): a chamada que pede tempo acima do GW_MAX_TIMEOUT (cabeçalho
 * x-ravi-tempo-limite, até GW_TEMPO_TETO) segura o container por até 10 minutos. Para não
 * roubar vaga do tráfego de conversa (que já bate no teto de 6 em horário de pico), ela
 * usa um semáforo PRÓPRIO, com GW_LONGAS_MAX vagas a mais (pasta slots-longas): a faixa
 * longa nunca consome as vagas da faixa normal e o teto de memória do host fica em
 * (GW_MAX_CONCURRENCY + GW_LONGAS_MAX) GB.
 */
class GatewaySlots
{
    private string $dir;
    private int $max;
    private float $wait;

    /** @var resource|null */
    private $handle = null;

    public function __construct(bool $longa = false)
    {
        $base = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage') . '/gateway/';
        $this->dir = $base . ($longa ? 'slots-longas' : 'slots');
        // 6 slots × 1GB = teto de 6GB em containers do gateway (host tem 24GB).
        $this->max  = $longa
            ? max(1, (int) env('GW_LONGAS_MAX', 2))
            : max(1, (int) env('GW_MAX_CONCURRENCY', 6));
        $this->wait = max(0, (float) env('GW_SLOT_WAIT', 15));
    }

    /** Tenta ocupar um slot (esperando até GW_SLOT_WAIT). true = pode rodar o container. */
    public function acquire(): bool
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $deadline = microtime(true) + $this->wait;
        do {
            for ($i = 0; $i < $this->max; $i++) {
                $h = @fopen($this->dir . '/slot-' . $i . '.lock', 'c');
                if ($h === false) {
                    continue;
                }
                if (flock($h, LOCK_EX | LOCK_NB)) {
                    $this->handle = $h;
                    return true;
                }
                fclose($h);
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);
        return false;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
