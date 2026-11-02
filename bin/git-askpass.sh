#!/bin/sh
# Askpass para o git push/clone: devolve o token do GitHub a partir do ambiente.
# Mantém o token FORA do argv (não aparece em `ps`) e FORA do disco (.git/config limpo).
printf '%s' "$RAVI_GIT_TOKEN"
