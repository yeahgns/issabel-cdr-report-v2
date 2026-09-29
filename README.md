<div align="center">

# 📊 Relatório de ligações

**Relatório de CDR para Issabel que finalmente faz sentido para o cliente.**

Uma linha por ligação. Nome do departamento em vez de número de fila. Clique para expandir e ver cada ramal que tocou, não atendeu ou ficou ocupado.

[![Live demo](https://img.shields.io/badge/demo-live-1F93FF?style=flat&logo=render&logoColor=white)](https://issabel-cdr-report-v2-demo.onrender.com)
![PHP](https://img.shields.io/badge/PHP-5.4%2B-777BB4?style=flat&logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-lightgrey?style=flat)

</div>

<img width="1917" height="924" alt="image" src="https://github.com/user-attachments/assets/ff78234a-403b-420e-a673-73cbb6b37e85" />

## 🔗 Demo

**[Clique aqui para acessar](https://issabel-cdr-report-v2-demo.onrender.com)**

É o próprio relatório rodando com dados fictícios para simulação. Dá para clicar em qualquer linha, expandir o histórico de uma ligação, trocar o período e olhar as abas de Departamentos e Atendentes.

## Por que existe

O CDR do Issabel mostra cada tentativa de uma ligação como uma linha separada: o ramal 232 atendeu, o 293 ficou em "NO ANSWER", e por aí vai, tudo com o mesmo `uniqueid`. Faz sentido para quem mexe no PABX. Para o cliente que só quer saber se a ligação foi atendida, é confuso.

Este relatório agrupa tudo isso numa única ligação, mostra quem atendeu e esconde o resto atrás de um clique, sem fazer a informação sumir.

## O que ele mostra

- **Uma linha por ligação**, com o nome do departamento no lugar do número da fila
- **Expandir e ver tudo**: cada ramal que tocou, quanto tempo, e o resultado (atendeu, não atendeu, ocupado)
- **Perdidas sem retorno**: quem ligou, não foi atendido, ninguém retornou e o cliente não voltou a ligar
- **Espera sem contar a URA**: o tempo no menu fica separado do tempo de espera real
- **Linha do tempo por ligação**, mostrando quem recebeu a chamada e quando, inclusive em transferências
- **Departamentos e Atendentes**, com nível de serviço, mapa de calor por dia e hora, e métricas justas para quem trabalha com ring-all
- **Gravação, exportação em CSV e modo ao vivo**
- **Visão técnica**, com os registros crus do CDR ao lado da interpretação, para conferir ligação a ligação

Compatível com Issabel 5 e também com Issabel 4 (CentOS 7, PHP 5.4, MariaDB 5.5). Sem dependências, sem framework, sem Composer.

## Instalação

O relatório fica em `/var/www/html/modules/report`.

```bash
# 1. Baixar a versão mais recente do projeto
cd /tmp
git clone --depth 1 https://github.com/yeahgns/issabel-cdr-report-v2.git

# 2. Mover para a pasta de módulos do Issabel (guardando o antigo se existir)
cd /var/www/html/modules
[ -d report ] && mv report report.old
mv /tmp/issabel-cdr-report-v2 report
chown -R asterisk:asterisk report

# 3. Configuração (opcional: sem config.php ele já roda com os padrões)
cd report
cp config.sample.php config.php
vi config.php
```

Abrir `https://PABX/modules/report/`.

Para testar a interface sem banco, coloque `'demo' => true` no `config.php`, ou simplesmente abra a [demo ao vivo](https://issabel-cdr-report-v2-demo.onrender.com).

A pasta `.git` nunca deve ficar dentro de `/var/www/html`: ela fica acessível pelo navegador.

### Banco de dados

Se `db.user`/`db.pass` ficarem vazios, o relatório lê as credenciais de `/etc/amportal.conf` (AMPDBUSER/AMPDBPASS) e, se não achar, de `/etc/issabel.conf`. O ideal é criar um usuário **só leitura**:

```sql
GRANT SELECT ON asteriskcdrdb.* TO 'relatorio'@'localhost' IDENTIFIED BY 'senha-forte';
GRANT SELECT ON asterisk.*      TO 'relatorio'@'localhost';
FLUSH PRIVILEGES;
```

Índices recomendados (deixam o relatório bem mais rápido em CDR grande):

```sql
ALTER TABLE asteriskcdrdb.cdr ADD INDEX idx_calldate (calldate);
ALTER TABLE asteriskcdrdb.cdr ADD INDEX idx_linkedid (linkedid);
```

Verifique antes com `SHOW INDEX FROM cdr;`, alguns já existem.

### Acesso

- `auth_users` no `config.php` liga login por usuário e senha.
- Os `.htaccess` bloqueiam `lib/` e o `config.php`. Se o Apache estiver com `AllowOverride None` para `/var/www/html`, eles são ignorados; nesse caso o `config.php` continua seguro (é PHP e não imprime nada), mas vale liberar `AllowOverride All` para a pasta.

## Como as ligações são agrupadas

1. Todos os registros com o mesmo **linkedid** (ou uniqueid, se não houver linkedid) viram uma única ligação.
2. Cada registro vira um passo: URA, fila, grupo de toque, ramal, caixa postal ou saída por tronco. Os nomes vêm do próprio Issabel (filas, grupos, ramais, URAs, DIDs); `labels` no config sobrescreve qualquer um deles.
3. Os canais `Local/232@from-queue-...;1` e `;2` são a mesma tentativa, então são unidos. Cada ramal da fila mostra quantas vezes tocou e o resultado de cada vez (atendeu, não atendeu, ocupado, falhou).
4. **Atendida** significa que algum ramal ou pessoa conversou (billsec maior que zero num passo de ramal, fila ou grupo). Um `ANSWERED` da URA (BackGround/Playback) não conta como atendimento.
5. **Espera** conta do primeiro toque na fila ou grupo até o atendimento; o tempo ouvindo o menu da URA aparece separado.
6. **Perdida sem retorno**: perdida em que ninguém ligou de volta e o cliente não voltou a ser atendido dentro de `callback_window_hours`.
7. **Ligações do próprio sistema** (Originate/AMI, call files, sem tronco, sem ramal e sem número na origem) são descartadas. Isso evita que testes internos ou scripts apareçam como "Ramal s" no relatório do cliente.

Os números de atendentes são justos com ring-all: "Tocou e ninguém atendeu" só conta ligações que ficaram perdidas. Se um colega atendeu, isso não pesa contra quem também tocou.

## Gravações

O arquivo é buscado pelo `recordingfile` do CDR, sempre dentro de `recordings_dir`. Gravações `.wav` e `.mp3` tocam no navegador; `.gsm` não, nesse caso use o botão de baixar ou mude o formato de gravação do Issabel para wav.

## Atualizar a frota

O script `deploy/deploy-report.sh` atualiza `modules/report` em várias VMs de uma vez, via Ansible, em blocos.

```bash
read -s "GH_TOKEN?Token: "; echo; export GH_TOKEN
./deploy/deploy-report.sh ~/caminho/inventory.ini
```

Veja as opções (VMs a excluir, tamanho do bloco, senha do vault) no cabeçalho do próprio script. Nunca suba inventário, IPs ou tokens para este repositório, tudo que está aqui é copiado para as VMs.

## Limitações conhecidas

- O CDR não registra o motivo exato de abandono em fila; o `queue_log` teria isso (ABANDON, EXITWITHTIMEOUT) e pode ser incorporado depois.
- Transferências cegas feitas por telefone às vezes geram registros com linkedid diferente; nesses casos a ligação aparece em duas linhas.
- Use a "Visão técnica" para conferir, ligação a ligação, como os registros crus foram interpretados.
- Ainda não há uma separação na apresentação da métrica para diferenciar ligações de telefonia padrão com campanhas dentro do módulo CallCenter (remodelado posteriormente por mim, nesse repositório [aqui](https://github.com/yeahgns/issabel-callcenter-v2). Elas são exibidas de forma conjunta, podendo atrapalhar futuras análises.

---

<div align="center">
Feito para o dia a dia, não para uma tela bonita e vazia. Para sugestões e problemas, abra uma issue.
</div>
