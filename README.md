# Relatório de ligações — Bradial

Relatório de CDR para Issabel 5que mostra **uma linha por ligação**, com o nome do
departamento, quem atendeu e, ao expandir, todos os ramais que tocaram, não
atenderam ou estavam ocupados. Os registros crus do CDR continuam acessíveis na
"Visão técnica".

Também compatível com Issabel 4 (CentOS 7, PHP 5.4, MariaDB 5.5). Sem dependências,
sem framework, sem Composer.

## Instalação

```bash
# 1. Copiar a pasta para o servidor
scp -r relatorio-ligacoes root@PABX:/var/www/html/relatorio

# 2. Permissões
chown -R asterisk:asterisk /var/www/html/relatorio   # ou apache:apache, conforme o httpd
find /var/www/html/relatorio -type f -exec chmod 644 {} \;

# 3. Configuração
cd /var/www/html/relatorio
cp config.sample.php config.php
vi config.php
```

Abrir `https://PABX/relatorio/`.

Para testar a interface sem banco, coloque `'demo' => true` no `config.php`.

### Banco de dados

Se `db.user`/`db.pass` ficarem vazios, o relatório lê as credenciais de
`/etc/amportal.conf` (AMPDBUSER/AMPDBPASS) e, se não achar, de
`/etc/issabel.conf`. O ideal é criar um usuário **só leitura**:

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

(Verifique antes com `SHOW INDEX FROM cdr;` — alguns já existem.)

### Acesso

- `auth_users` no `config.php` liga login por usuário/senha.
- Os `.htaccess` bloqueiam `lib/` e o `config.php`. Se o Apache estiver com
  `AllowOverride None` para `/var/www/html`, eles são ignorados; nesse caso o
  `config.php` continua seguro (é PHP e não imprime nada), mas vale liberar
  `AllowOverride All` para a pasta.

## Como as ligações são agrupadas

1. Todos os registros com o mesmo **linkedid** (ou uniqueid, se não houver
   linkedid) viram uma única ligação.
2. Cada registro vira um "passo": URA, fila, grupo de toque, ramal, caixa postal
   ou saída por tronco. Os nomes vêm do próprio Issabel (filas, grupos, ramais,
   URAs, DIDs); `labels` no config sobrescreve qualquer um.
3. Os canais `Local/232@from-queue-…;1` e `;2` são a mesma tentativa, então são
   unidos. Cada ramal da fila mostra quantas vezes tocou e o resultado de cada
   vez (atendeu, não atendeu, ocupado, falhou).
4. **Atendida** = algum ramal ou pessoa conversou (billsec > 0 num passo de
   ramal/fila/grupo). Um `ANSWERED` da URA (BackGround/Playback) não conta como
   atendimento.
5. **Espera** conta do primeiro toque na fila/grupo até o atendimento; o tempo
   ouvindo o menu da URA aparece separado.
6. **Perdida sem retorno**: perdida em que ninguém ligou de volta e o cliente
   não voltou a ser atendido dentro de `callback_window_hours`.

Os números de atendentes são justos com ring-all: "Tocou e ninguém atendeu"
só conta ligações que ficaram perdidas. Se um colega atendeu, não pesa contra
quem também tocou.

## Gravações

O arquivo é buscado pelo `recordingfile` do CDR, sempre dentro de
`recordings_dir`. Gravações `.wav` e `.mp3` tocam no navegador; `.gsm` não —
nesse caso use o botão de baixar ou mude o formato de gravação do Issabel para
wav.

## Limitações conhecidas

- O CDR não registra o motivo exato de abandono em fila; o `queue_log` teria
  isso (ABANDON, EXITWITHTIMEOUT) e pode ser incorporado depois.
- Transferências cegas feitas por telefone às vezes geram registros com
  linkedid diferente; nesses casos a ligação aparece em duas linhas.
- Use a "Visão técnica" para conferir, ligação a ligação, como os registros
  crus foram interpretados.
