#!/bin/bash
# Atualiza o relatório de ligações (modules/report) em todas as VMs do inventário,
# em blocos. Uso (no zsh do Mac):
#
#   read -s "GH_TOKEN?Token: "; echo; export GH_TOKEN
#   ./deploy/deploy-report.sh ~/audit_vm/inventory.ini
#
# Variáveis opcionais:
#   EXCLUIR="1.2.3.4 1.2.3.5"   IPs que devem ficar de fora
#   BLOCO=50                    VMs por bloco
#   FORKS=10                    VMs ao mesmo tempo dentro do bloco
#   GRUPO=pbx_vms               grupo do inventário
#   VAULT=1                     passa --ask-vault-pass para o ansible
#
# Nunca coloque o inventário, IPs ou tokens neste repositório: tudo que está
# aqui vai para as VMs.

INVENTARIO="${1:-inventory.ini}"
BLOCO="${BLOCO:-50}"
FORKS="${FORKS:-10}"
GRUPO="${GRUPO:-pbx_vms}"
EXCLUIR="${EXCLUIR:-}"
LOG="deploy-report.log"
export ANSIBLE_DEPRECATION_WARNINGS=False

[ -f "$INVENTARIO" ] || { echo "Inventário não encontrado: $INVENTARIO"; exit 1; }
[ -n "$GH_TOKEN" ] || { echo "Falta o token. No zsh: read -s \"GH_TOKEN?Token: \"; echo; export GH_TOKEN"; exit 1; }

VAULT_OPT=""
[ "${VAULT:-0}" = "1" ] && VAULT_OPT="--ask-vault-pass"

# Na VM: clona a branch, tira o que não é do relatório, guarda o antigo em
# report.old (só na primeira vez) e coloca o novo no lugar.
CMD="set -e; cd /tmp; rm -rf issabel; GIT_TERMINAL_PROMPT=0 timeout 90 git clone -q --depth 1 -b relatorio https://${GH_TOKEN}@github.com/guilhermebradial/issabel.git; rm -rf issabel/.git issabel/.gitignore issabel/deploy issabel/README.md; cd /var/www/html/modules; if [ -d report.old ]; then rm -rf report; else mv report report.old; fi; mv /tmp/issabel report; chown -R asterisk:asterisk report; echo OK"

HOSTS=$(grep -E '^[0-9]' "$INVENTARIO")
for ip in $EXCLUIR; do
  HOSTS=$(echo "$HOSTS" | grep -vxF "$ip")
done
TOTAL=$(echo "$HOSTS" | grep -c .)
echo "$TOTAL VMs"

for ((i=1; i<=TOTAL; i+=BLOCO)); do
  f=$(( i+BLOCO-1 < TOTAL ? i+BLOCO-1 : TOTAL ))
  IPS=$(echo "$HOSTS" | sed -n "${i},${f}p" | paste -sd, -)
  echo "===== VMs $i a $f =====" | tee -a "$LOG"
  ansible "$GRUPO" -i "$INVENTARIO" --limit "$IPS" \
    -m ansible.builtin.raw -a "$CMD" \
    -f "$FORKS" -o $VAULT_OPT 2>&1 | sed "s/${GH_TOKEN}/***TOKEN***/g" | tee -a "$LOG"
  if [ "$f" -lt "$TOTAL" ]; then
    read -p "Bloco concluído. Enter para o próximo, Ctrl+C para parar... "
  fi
done

echo
echo "Falhas (se houver):"
grep -E "FAILED|UNREACHABLE" "$LOG" || echo "nenhuma"
