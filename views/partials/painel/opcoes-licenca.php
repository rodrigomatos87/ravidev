<?php
/**
 * <option> de um seletor de licença (Claude ou ChatGPT), único para as telas de licenças.
 *
 * Variáveis esperadas (defina antes do include):
 *   $opcoes      array id => rótulo pronto (vem do controller: opcoesLicencas / opcoesChatgpt)
 *   $selecionada ?int  id escolhido (0 ou null = opção zero)
 *   $rotuloZero  string rótulo da opção de valor 0
 *   $excluir     ?int  (opcional) id que não entra na lista
 */
$selecionadaAtual = (int) ($selecionada ?? 0);
$excluirAtual = (int) ($excluir ?? 0);
?>
<option value="0"<?= $selecionadaAtual > 0 ? '' : ' selected' ?>><?= e((string) $rotuloZero) ?></option>
<?php foreach ((array) $opcoes as $idOpcao => $rotuloOpcao): ?>
<?php if ($excluirAtual > 0 && (int) $idOpcao === $excluirAtual) { continue; } ?>
<option value="<?= (int) $idOpcao ?>"<?= $selecionadaAtual === (int) $idOpcao ? ' selected' : '' ?>><?= e((string) $rotuloOpcao) ?></option>
<?php endforeach; ?>
<?php unset($excluir, $selecionadaAtual, $excluirAtual, $idOpcao, $rotuloOpcao); ?>
