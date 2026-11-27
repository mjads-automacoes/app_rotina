# Rotina: auditoria completa e instruções para continuar em outra sessão

Atualizado em 13/11/2026. Este arquivo é o ponto de partida de qualquer sessão nova (na nuvem ou em outro computador). Leia inteiro antes de mexer no código. Tudo aqui foi conferido contra o código nesta data; se algo divergir, o código vale.

---

## 1. Resumo em 1 minuto

- **O que é:** sistema web próprio de gestão de tráfego pago, um "mini ClickUp": clientes com ficha, tarefas, rotina diária do gestor e automações internas. O objetivo de longo prazo é parar de usar o ClickUp (hoje ele só serve de fonte para importar os clientes).
- **Tecnologia:** PHP 8.2 + SQLite (um arquivo), JavaScript puro, sem Composer, sem npm, sem framework, sem dependência externa.
- **Estado:** funciona localmente e na nuvem, com 102 testes de PHP e 1 teste de navegador passando. **Está no ar desde 13/11/2026 em `https://rotina.whatsredirect.pro`** (seção 11). A imagem Docker foi construída e testada em 13/11/2026 (seção 4). O código está no GitHub em `mjads-automacoes/app_rotina`, branch `claude/new-session-10ewul` (primeiro commit feito em 13/11/2026 a partir de um .zip da pasta do dono; a `main` do GitHub ainda está vazia).
- **Nome:** "Rotina" é provisório. Fica em um único lugar: variável `ROTINA_APP_NAME` ou `src/config.php`.
- **Login de teste:** `admin` / senha `1` (administrador) e `ana` / senha `1` (gestora). Vêm de `scripts/seed-demo.php`.
- **Projeto independente:** não compartilha código, banco, login nem domínio com o WhatsRedirect (outro projeto do mesmo dono, na pasta `Gerador de Redirect_atual`). Não misture os dois.

## 2. Regras de trabalho (valem para toda sessão)

1. **Responder sempre em português do Brasil**, em linguagem simples. O dono não é programador: ele pede mudanças por print de tela com riscos e setas. Explique o que mudou na tela, não o código.
2. **Toda mudança vai para o ar.** Desde 13/11/2026 o sistema está online em `https://rotina.whatsredirect.pro` e o dono decidiu: "toda alteração feita, você terá de publicar pra que possa ser vista na versão online". Mexer no Easypanel, na VPS, no domínio ou na infraestrutura continua exigindo pedido explícito.
3. **Commit e push a cada mudança**, no ramo `claude/new-session-10ewul`, sem esperar pedido, mas só com os testes passando. Se um teste falhar, não envie e avise.
4. **Toda mudança termina com os testes rodados** (seção 5) e, quando for visual, com o preview no ar para ele olhar. Se um teste falhar, diga que falhou.
5. **Fazer exatamente o que foi pedido.** Mudanças de tela são pontuais ("remova isso", "mude o nome para X"). Não redesenhe o resto. Quando uma remoção deixar algo sem acesso ou sem uso, avise e pergunte, não decida sozinho.
6. **Manter o padrão do código:** sem dependências novas, sem script ou estilo inline (a CSP bloqueia), todo texto do servidor entra na tela por `textContent`, toda regra de negócio nova ganha teste em `tests/`.
7. **Atualizar este arquivo** ao fim de cada sessão que mudar comportamento, decisão ou pendência.

## 3. Passo zero: levar o código para a nuvem

A sessão na nuvem só enxerga o que estiver num repositório remoto. Hoje o projeto está apenas na pasta `C:\Users\Maquina 01\Documents\Rotina`, sem commit e sem remoto. Antes da primeira sessão na nuvem, no computador do dono:

```bash
cd "C:/Users/Maquina 01/Documents/Rotina"
git add -A
git commit -m "Rotina: estado inicial (mini ClickUp, rotina do dia, automacoes, importacao)"
# criar um repositorio PRIVADO e vazio no GitHub (ex.: rotina) e entao:
git remote add origin https://github.com/<usuario>/rotina.git
git push -u origin main
```

O `.gitignore` já deixa de fora o banco (`data/`, `*.sqlite`) e `.env`. Nenhum segredo está no código. O banco **não** vai para o repositório: na nuvem os dados de exemplo são recriados pelo `seed-demo.php`.

## 4. Como rodar

### Na nuvem (Linux)

Requisitos: PHP 8.2 ou mais novo com as extensões `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl` e `curl`; Node 22 ou mais novo (o teste de navegador usa `WebSocket` e `fetch` nativos). Se faltar PHP: `apt-get install -y php-cli php-sqlite3 php-mbstring php-curl`.

```bash
export ROTINA_DATA_DIR=/tmp/rotina-dados          # banco fora da pasta do projeto
php scripts/seed-demo.php                         # cria admin/1, ana/1, 10 clientes, tarefas da semana
php -S 0.0.0.0:8200 -t public public/router.php   # abre em http://localhost:8200
```

O seed só age em banco vazio. Para recomeçar do zero, apague a pasta de `ROTINA_DATA_DIR` e rode de novo. Sem seed, o primeiro acesso abre a tela **Primeiro acesso**, que cria o administrador.

### No Windows do dono

O PHP dele (8.4) vem com SQLite desligado, por isso os comandos levam `-d extension=pdo_sqlite -d extension=sqlite3`.

```powershell
powershell -File scripts/serve.ps1     # http://127.0.0.1:8200, dados em .\data
```

O preview que ele costuma usar roda com `ROTINA_DATA_DIR=C:/Users/MAQUIN~1/AppData/Local/Temp/rotina-preview` na porta 8200. O servidor morre quando é iniciado como tarefa de fundo do Claude; inicie destacado:

```powershell
$env:ROTINA_DATA_DIR = 'C:/Users/MAQUIN~1/AppData/Local/Temp/rotina-preview'
Start-Process -WindowStyle Hidden -FilePath php -ArgumentList '-d','extension=pdo_sqlite','-d','extension=sqlite3','-S','127.0.0.1:8200','-t','public','public/router.php' -WorkingDirectory "C:\Users\Maquina 01\Documents\Rotina"
```

### Docker (testado em 13/11/2026)

`docker compose up --build` abre em `http://localhost:8200` (imagem `php:8.2-apache`, volume `/data`, porta interna 80). Na nuvem o Docker existe, mas o serviço não vem ligado: rode `dockerd &` antes.

A partida do container é `scripts/docker-entrypoint.sh`: acerta o dono do volume `/data` (sem isso, um volume vindo de fora dava erro 500 "unable to open database file") e roda `scripts/tick.php` a cada minuto, então as automações não dependem de cron no servidor. Conferido: banco vazio abre o Primeiro acesso, senha curta é recusada com `ROTINA_SENHA_MIN=8`, os dados sobrevivem a reiniciar o container e o tick roda no fuso de Fortaleza.

## 5. Testes

```bash
php tests/run.php            # 102 testes; "php tests/run.php http" roda só os arquivos com "http" no nome
node tests/browser_test.js   # ponta a ponta num navegador sem janela; SHOT=pasta salva os prints
```

- `tests/run.php` é um executor próprio (funções `t`, `eq`, `ok`, `lanca` em `tests/helpers.php`). Cada teste usa banco em memória. Os testes rodam com `ROTINA_SENHA_MIN=8` (regra de produção), definido em `helpers.php`.
- `tests/browser_test.js` sobe o próprio servidor PHP (porta 8231) com banco temporário e seed, abre o navegador por CDP (porta 9362) e percorre login, rotina, tarefas, quadro, automações, clientes, histórico, importar, os 2 temas e o celular. Falha se houver qualquer erro de JavaScript no console.
- **No Linux (nuvem):** fora do Windows o teste já abre o navegador com `--no-sandbox`. Aponte para o Chromium: `EDGE_PATH=/opt/pw-browsers/chromium-1243/chrome-linux64/chrome node tests/browser_test.js` (caminho do ambiente da nuvem em 13/11/2026; se mudar, procure em `/opt/pw-browsers`). O teste também passa `-d extension=pdo_sqlite` ao PHP; onde a extensão já vem ligada isso só gera um aviso.
- A pasta de `SHOT` precisa existir ou ser criável. Os prints são a forma de conferir o visual sem o dono.

## 6. Mapa do código

```
public/
  index.php        entrada web: sessão (cookie rotina_sid), monta Request, chama App
  router.php       roteador do servidor embutido do PHP (uso local)
  .htaccess        reescrita para o index.php no Apache
  assets/
    theme.js       aplica o tema antes de pintar (localStorage "rotina_tema")
    app.js         comum: tema, menu do celular, confirmações, cores via data-cor,
                   menu de seleção dos dias, troca Rotina/Tarefas, window.Rotina
    rotina.js      tela Rotina (modo foco)
    tarefas.js     painel Tarefas: lista, quadro, filtros, gaveta
    automacoes.js  tela Automações: lista, liga/desliga, construtor
    app.css        todo o visual (tokens dos 2 temas no topo)
src/
  bootstrap.php    carrega as classes, rotina_config(), fuso horário
  config.php       configuração única (variáveis de ambiente)
  Db.php           abre o SQLite e migra (PRAGMA user_version, hoje versão 9)
  Http.php         AcessoNegado, e(), icone(), logo_plataforma(), seletor_dias(),
                   Sessao, Request, Response, View
  App.php          rotas (páginas e API), CSRF, cabeçalhos de segurança, flash
  Nucleo.php       monta os serviços; Nucleo::sistema() é o usuário "Sistema"
  Demo.php         dados FICTÍCIOS (19 clientes, 6+ por dia útil, semana da rotina, tarefas gerais); carregar/apagar
  Auth.php         usuários, login, bloqueio por tentativas, senha
  Clientes.php     clientes, ficha, notas
  Listas.php       listas e status
  Tarefas.php      tarefas, etiquetas, checklist, comentários, atividade
  Automacoes.php   gatilhos, condições, ações, tick
  Rotina.php       rotina do dia (visão sobre as tarefas) e histórico
  Importacao.php   ClickUp: importa clientes, sincroniza comentários <-> notas; token criptografado
views/             layout.php + uma view por tela (PHP puro)
scripts/
  seed-demo.php    dados de exemplo (só em banco vazio): cria admin/1 e ana/1 e chama Demo::carregar
  demo.php         na versão online: carregar|apagar os dados fictícios (ROTINA_DEMO no Easypanel)
  docker-entrypoint.sh  partida do container: dono do volume, ROTINA_DEMO, tick a cada minuto
  tick.php         roda as automações (para o cron do servidor)
  serve.ps1        servidor local no Windows
tests/             *_test.php, helpers.php, run.php, browser_test.js
Dockerfile, docker-compose.yml, .dockerignore, .gitignore, README.md
```

Total: cerca de 6.600 linhas.

### Convenções que não podem ser quebradas

- **Erros:** `AcessoNegado` vira 403, `InvalidArgumentException` vira 422 (ou aviso na tela e volta para a página), `RuntimeException` vira 404. Qualquer outra exceção vira 500 com mensagem genérica.
- **Permissão:** todo método de serviço recebe `$actor` (o usuário). Gestor vê só os clientes dele e as tarefas em que é responsável, criador ou dono do cliente. Admin vê tudo.
- **CSRF:** todo POST exige o token, no campo `csrf` do formulário ou no cabeçalho `X-CSRF-Token` (o `chamar()` do `app.js` já envia). O token está em `body[data-csrf]`.
- **CSP:** `default-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'`. Não existe script nem estilo inline. Cor que vem de dado entra por `data-cor` (o `app.js` aplica) ou pelo atributo `cor` do `el()`.
- **`el(tag, attrs, filhos)`** em `window.Rotina`: `text` vira `textContent`; atributo desconhecido vira `setAttribute`. **Não aceita `onclick`**: crie o elemento e use `addEventListener`. Os outros utilitários são `toast`, `chamar`, `dataBR`, `iniciais`.
- **Banco:** mudança de estrutura é uma nova função `vN` em `Db.php`, com `VERSAO` incrementada. Nunca edite `v1` a `v9`. (`v9`, 27/11/2026: campos da ficha para os agentes + tabelas `pedidos` e `pedido_eventos`.) (`v8`, 24/11/2026: `tarefas.iniciada_em`, preenchida para as antigas a partir do histórico de status.) (`v7`, 24/11/2026: `cliente_notas.excluida` para exclusão marcada.) (`v6`, 24/11/2026: coluna `clientes.entregaveis`, lista separada por vírgula, idempotente.) (`v5`, 24/11/2026: colunas `cnpj`, `cidade`, `estado` em `clientes`, idempotente.) (`v4`, 24/11/2026: situação do cliente vira `novo|ativo|alerta|churn`; "pausado" virou "alerta". Refaz a tabela `clientes` com `PRAGMA foreign_keys = OFF` ligado no `migrate()` para o DROP não apagar em cascata; testado em `db_auth_test.php`.) (`v3`, 19/11/2026: apaga o site fictício `https://exemplo.com/...` dos clientes de demonstração, pedido do dono; a carga de demonstração também não preenche mais o site.)
- **Notificações (pedido do dono em 16/11/2026):** toda notificação aparece no canto superior direito e some em 5 segundos (ou com um clique). Use `toast(msg, erro)` do `window.Rotina`; avisos do servidor (flash, erro de login/setup/importar) levam `data-notificacao="ok|erro"` e o `app.js` os converte. Na ficha em janela (iframe), a notificação sai na tela principal. Não crie faixas de aviso paradas na página. (O quadro informativo fixo da tela Automações não é notificação e continua.)
- **Ficha em janela (16/11/2026):** no modo embutido a ficha usa 2 colunas (dados | tarefas e notas), sem o nome repetido nem o link "Clientes / ficha".
- **Ficha sem botão Salvar (16/11/2026, pedido do dono):** o formulário `form[data-autosalvar]` salva sozinho via `POST /api/clientes/{id}` (JSON; 700 ms depois de parar de digitar, na hora em menus/caixas e ao sair do campo; `fetch keepalive` ao fechar a página). O status "Salvando…/✓ Salvo/Não salvo" fica no topo do cartão (não é notificação; erro vira notificação). Mudou o nome: atualiza o título da janela. **Excluir** virou ícone de lixeira ao lado do nome: na janela da Rotina fica no topo da janela (`POST /api/clientes/{id}/excluir`, confirma, fecha e recarrega a Rotina); na ficha em página cheia, ao lado do h1 (formulário para a rota de página). Excluir mantém as tarefas do cliente, sem cliente: as abertas do dia passam a aparecer no Check List (perguntado ao dono). Desde 23/11/2026 não há mais o aviso "Salva sozinha / ✓ Salvo" no topo do cartão (pedido do dono); erro ao salvar continua aparecendo como notificação.
- **Arquivos da tela:** no `layout.php` use sempre `asset('arquivo.js')` (em `Http.php`), que acrescenta `?v=` com a marca do conteúdo. A Cloudflare na frente do domínio guarda `/assets/*` por até 4 horas; sem a marca, cada mudança apareceria com CSS/JS velho.
- **API:** responde sempre `{ok: true, ...}` ou `{ok: false, message}`.
- **Modo embutido:** quando a página é pedida de dentro de um iframe (`Sec-Fetch-Dest: iframe`), o layout recebe `embutido = true`, o `body` ganha só a classe `embutido` e some o menu. É assim que a ficha abre em pop-up. Não use a classe `sem-menu` nesse caso (ela aplica a largura da tela de login).

## 7. Banco de dados (versão 2)

Arquivo `rotina.sqlite` na pasta de `ROTINA_DATA_DIR`. Chaves estrangeiras ligadas, modo WAL.

| Tabela | Para quê | Campos que importam |
|---|---|---|
| `usuarios` | equipe | `email` (guarda o **login**: e-mail ou login simples), `senha_hash`, `papel` (`admin`/`gestor`), `ativo` |
| `tentativas_login` | bloqueio | `email`, `ip`, `criado_em` |
| `clientes` | ficha | `gestor_id`, `nome`, `empresa`, `descricao`, `link`, `dias` (texto `segunda,quinta`), `turno` (`manha`/`tarde`/`fechamento`), `status` (`ativo`/`novo`/`pausado`), `contato_nome`, `contato_telefone`, `contato_email`, `plataformas`, `orcamento`, `observacoes`, `clickup_id`, `clickup_dados` (JSON) |
| `cliente_notas` | notas da ficha | `autor`, `texto`, `origem` (`manual`/`clickup`), `origem_id` |
| `listas` | listas de tarefas | 1 = "Rotina diária", 2 = "Geral" |
| `status` | colunas de cada lista | `tipo` (`aberto`/`andamento`/`fechado`), `ordem`, `cor` |
| `tarefas` | tarefas | `lista_id`, `cliente_id`, `status_id`, `titulo`, `descricao`, `prioridade` (1 Urgente a 4 Baixa), `responsavel_id`, `prazo` (AAAA-MM-DD), `turno`, `origem` (`manual`/`automacao`), `origem_chave` (única, evita duplicar), `concluida_em` |
| `etiquetas`, `tarefa_etiquetas` | etiquetas | |
| `tarefa_itens` | checklist da tarefa | `texto`, `feito`, `ordem` |
| `tarefa_atividades` | comentários e linha do tempo | `tipo` (`comentario` e eventos), `texto`, `usuario_id` (nulo = Sistema) |
| `automacoes` | regras | `gatilho`, `gatilho_config`, `condicoes`, `acoes` (JSON), `ativa`, `ultima_execucao` |
| `automacao_execucoes` | histórico e trava | `chave` única por automação |
| `meta` | interno | `ultimo_tick` |
| `configuracoes` | ajustes | `clickup_token` (criptografado) |

A versão 1 tinha `andamento`, `checklist_modelo`, `checklist_marcas` e `registros`. A versão 2 apagou essas tabelas: a rotina passou a ser feita de tarefas comuns.

**Dados que já nascem com o banco:** as 2 listas com seus status ("Geral" tem também "Em revisão") e 5 automações: *Rotina diária dos clientes*, *Checklist da manhã*, *Checklist da tarde*, *Checklist do fechamento* (todas seg a sex às 05:00) e *Tarefa atrasada ganha prioridade alta*.

## 8. Rotas

### Páginas

| Rota | O que faz |
|---|---|
| `GET/POST /setup` | cria o primeiro administrador (só com banco sem usuários) |
| `GET/POST /login`, `POST /logout` | entrar e sair |
| `GET /` | tela Rotina (com o painel Tarefas escondido dentro dela) |
| `GET /tarefas` | redireciona para `/#tarefas` |
| `GET/POST /clientes` | lista em cartões e criação |
| `GET/POST /clientes/{id}` | ficha e salvar |
| `POST /clientes/{id}/notas`, `POST /clientes/{id}/excluir` | nota e exclusão |
| `POST /clientes/{id}/tarefas`, `POST /clientes/{id}/tarefas/{tid}/excluir` | adicionar e excluir tarefa pela ficha |
| `GET /automacoes` | tela Automações |
| `GET /historico` | concluídas, com filtros `de`, `ate`, `cliente_id`, `responsavel_id` |
| `GET /integracoes`, `POST /integracoes/token`, `/integracoes/remover`, `/integracoes/executar` | ClickUp (tela "Integrações"; os antigos `/importar...` continuam funcionando, `GET /importar` redireciona) |
| `GET /conta`, `POST /conta/senha` | trocar a própria senha |
| `GET/POST /equipe`, `POST /equipe/{id}/ativo`, `POST /equipe/{id}/senha` | só admin |

### API (JSON, exige login)

| Rota | O que faz |
|---|---|
| `GET /api/meta` | listas, etiquetas, usuários, clientes, prioridades, gatilhos, hoje, usuário |
| `GET /api/rotina?data=&gestor=` | a semana da data, por dia e turno, separada em `checklist` e `clientes` |
| `POST /api/rotina/iniciar`, `/concluir`, `/reabrir` | recebem `tarefa_id` (concluir aceita `nota`) |
| `POST /api/rotina/gerar` | roda agora as automações agendadas de hoje |
| `GET/POST /api/tarefas` | listar (filtros `lista_id`, `status_id`, `cliente_id`, `responsavel_id`, `prioridade`, `etiqueta`, `busca`, `de`, `ate`, `abertas`, `concluidas`) e criar |
| `GET/POST /api/tarefas/{id}` | detalhe e edição |
| `POST /api/tarefas/{id}/status`, `/excluir`, `/comentarios`, `/itens` | mover, apagar, comentar, item de checklist |
| `POST /api/itens/{id}`, `/api/itens/{id}/excluir` | marcar e apagar item |
| `GET/POST /api/automacoes`, `POST /api/automacoes/{id}`, `/alternar`, `/executar`, `/excluir`, `GET /api/automacoes/{id}/execucoes` | automações |

## 9. Como cada parte funciona hoje

### Login e equipe

- O campo de login aceita **e-mail ou login simples** (3 a 60 caracteres: letras minúsculas, números, ponto, traço, sublinhado). É guardado em minúsculas na coluna `email`.
- **Senha mínima configurável** por `ROTINA_SENHA_MIN`. O padrão é **1**, a pedido do dono, para a fase de testes. Antes de publicar: `ROTINA_SENHA_MIN=8` e trocar as senhas.
- 5 erros seguidos do mesmo login e IP bloqueiam por 15 minutos. Mensagem genérica "Login ou senha incorretos."
- Admin cria gestores, ativa, desativa e redefine senha em Equipe. Ninguém desativa a própria conta.

### Tela Rotina (a principal)

Mostra **só o dia de hoje**. Não há abas de dias, nem filtro de turno, nem barra de progresso.

- **Topo:** título "Rotina"; ao lado, para o admin, o seletor de gestor (vê a rotina de outro); à direita, o botão de atualizar. Abaixo, a barra do dia: "Terça-feira 17/11 | 0 de 14 concluídos".
- **Formato (17/11/2026, pelo modelo enviado pelo dono):** o **cliente em foco** fica em cima, na largura toda; embaixo, lado a lado, com a mesma largura e a mesma altura, **"Checklist do dia"** (esquerda) e **"Clientes do dia"** (direita). No celular vira uma coluna só.
- **Tarefas do cliente no foco (17/11/2026):** o bloco do cliente em foco tem duas colunas separadas por uma linha vertical: à esquerda o de sempre (nome, subtítulo, nota, botões); à direita **"Tarefas do cliente"**, todas as tarefas vinculadas a ele (de `GET /api/tarefas?cliente_id=`) com data e status em pílula; a tarefa em foco fica destacada e muda de status na hora. No celular a lista desce para baixo. Mostra no máximo 3 tarefas; com mais, a lista rola por dentro (altura medida em JS) e já abre rolada até a tarefa em foco. Feito em `rotina.js` (`tarefasDoCliente`) e no fim de `app.css`.
- **Aba Agentes — PARTE 1 (27/11/2026, pedido do dono, especificação em `AGENTES-ESPECIFICACAO.md` e `src/agentes-formularios.json`):** menu **Agentes**. Pedidos da equipe aos 4 agentes do escritório (Gestor de Tráfego, Criativos, Dev Sites, Social Media). `/agentes` lista (filtros agente/status/cliente, ordem: falta informação, para aprovação, ajuste, fazendo, a fazer); `/agentes/novo` monta o formulário **a partir do JSON** (`agentes.js`: cabeçalho comum + campos do tipo; obrigatórios, `required_if`, opções condicionais, "não se aplica/sem oferta", pré-preenchimento "da ficha"); `/agentes/{id}` mostra o que foi pedido, a **linha do tempo** (pedido, status, pergunta, resposta, entrega v1/v2 com arquivos/links/textos/checklist, ajuste, aprovado, cancelado, comentários) e as ações. **Decisões do dono:** cada membro **aprova as próprias solicitações** (aprovador = solicitante; admin também pode); anexos **só por link do Drive** por enquanto; WhatsApp e ClickUp **continuam em paralelo** por enquanto. Ficha ganhou a seção **"Para os agentes"**: ID da conta Meta, ID do Google Ads, WhatsApp comercial, páginas FB/IG, domínio, cores, logo (link), endereço e horário (`Clientes::CAMPOS_AGENTES`). Código: `src/Pedidos.php` (criar/listar/obter/responder/decidir/comentar e, para os agentes, `agenteStatus`/`agentePerguntar`/`agenteEntregar`), rotas em `App.php` (`/agentes...`, `GET /api/agentes/esquema`, `POST /api/pedidos`). **Próximas partes:** (2) **MCP** na Rotina para os agentes da **OpenClaw** lerem pedidos e devolverem status/pergunta/entrega (os métodos `agente*` já existem e são testados); (3) cartão de **OTM** e alertas (24 h em Para aprovação, parado em Falta informação). Aviso de prazo mínimo por tipo ainda não feito. **Agentes como personagens (27/11, pedido do dono):** no topo da aba, 4 cartões de personagem (`Pedidos::PERSONAGENS`, nomes sugeridos por mim: **Max** Tráfego 📈 azul, **Luna** Criativos 🎨 rosa, **Byte** Dev Sites 💻 verde-água, **Sofia** Social 📱 âmbar) com avatar flutuando (o selo de nível "Nv" foi tirado a pedido), frase em balão, "Disponível/Trabalhando em N", números (na fila, para aprovar, entregues) e botão "Pedir a …" (`/agentes/novo?agente=`, já escolhe o agente). Sem o subtítulo, sem o botão "Novo pedido" e sem o quadro "Nenhum pedido ainda" (tirados a pedido em 27/11): o pedido nasce pelo "Pedir a …" de cada personagem.
- **Tema Neon (26/11/2026, pedido do dono, "baseado nas cores e tema do GTA VI"):** terceiro tema `data-tema="neon"`: noite roxa profunda, rosa-neon `#ff3ea5`, laranja de pôr do sol `#ff8a3d` e toque de ciano; botões e menu ativo em gradiente rosa→laranja com brilho, faixa do foco rosa→laranja→ciano, logo com contorno neon, XP em ciano. Só as cores e o clima (sem logo nem nome do jogo na tela). O botão de tema **roda Escuro → Neon → Claro → Escuro** e mostra o próximo (🌴 Neon / ☀ Claro / 🌙 Escuro). Bloco "TEMA NEON" no fim de `app.css`; `theme.js` aceita `neon`.
- **Visual Premium = TEMA ESCURO (24–25/11/2026, pedido do dono, com a logo preta e verde-lima dele):** o tema escuro agora é o visual Premium: preto profundo + verde-lima `#b4c62e`, botões e item ativo do menu em gradiente lima com brilho, logo redonda com contorno lima, cartões com leve reflexo, e **gamificação** na barra do dia da Rotina: anel de progresso (% do dia), **XP do dia** (cliente concluído = 50, item do checklist = 10), frase de incentivo (Bora começar 🚀 → Aquecendo ⚡ → Em ritmo 🔥 → Quase lá 💪 → Dia completo 🏆) e "+XP" subindo ao concluir. **Desde 25/11/2026 o tema claro segue o mesmo conceito** (pedido do dono): fundo e cartões em **cinza claro** (não branco; ajustado em 25/11 a pedido) com brilho lima, mesmo verde-lima, logo preta com contorno lima, menu ativo em gradiente lima e a mesma gamificação; os tokens antigos turquesa do topo do `app.css` ficam por baixo, sobrescritos pelo bloco Premium. O seletor "Premium/Clássico" foi removido em 25/11 a pedido. CSS no bloco "VISUAL PREMIUM" no fim de `app.css` (`:root[data-tema=escuro] ...`) + `topo()` em `rotina.js` (`.gamer`). Para desfazer no código: o commit anterior ao teste é `2c42530`.
- **"OK" e tempo de execução (24/11/2026, pedido do dono):** em "Clientes do dia", cliente concluído mostra **OK** (não mais "Concluído") e, entre o nome e o status, o **tempo de execução** (⏱): do primeiro "Iniciar" (`tarefas.iniciada_em`, gravada por `Tarefas::mover`; voltar para "a fazer" zera) até a última tarefa do cliente concluída naquele dia; em andamento mostra o tempo até agora. Calculado em `Rotina::dia` (`tempo_seg`).
- **Tudo em tempo real (24/11/2026, pedido do dono):** na ficha, os blocos **Tarefas** e **Notas** (`[data-ao-vivo]`) se atualizam sem recarregar a página: o `app.js` envia o formulário por `fetch`, troca só o bloco pela versão nova e mostra o aviso como notificação (`prepararTrecho` refaz cores e calendário). Quando a ficha está na janela sobre a Rotina, cada salvamento (autosave, tarefa, nota) chama `Rotina.recarregarRotina()` na tela de trás. A Rotina busca mudanças a cada **15 s** (antes 60 s) e ao voltar para a aba. Linha de tarefa com título longo quebra o texto e o ✕ fica sempre na mesma linha.
- **Detalhes da tarefa na ficha (24/11/2026, pedido do dono):** em "Tarefas deste cliente", clicar na tarefa abre/fecha embaixo dela os detalhes: o texto escrito no + da Rotina (`descricao`, com formatação) ou "Sem detalhes", e Status, Prazo, Responsável e Lista (`[data-tarefa-abrir]` em `app.js`).
- **Editar e excluir notas (24/11/2026, pedido do dono):** cada nota da ficha escrita pelo próprio usuário (mesmo nome de autor) — ou qualquer nota, para o administrador — tem **lápis** (edita no lugar: Salvar/Cancelar) e **lixeira** (pede confirmação). Rotas `POST /clientes/{id}/notas/{nota}` e `.../excluir` (`Clientes::notaEditar/notaExcluir`). A exclusão só marca `excluida = 1`, para a sincronização com o ClickUp não trazer de volta. Editar/excluir aqui **não** muda o comentário que já está no ClickUp. A lista de notas (`[data-notas-lista]`) tem a altura limitada para a coluna da direita não passar do fim de "Dados do cliente" (mínimo 160px; calculado no `app.js`); as demais rolam por dentro, **sem barra de rolagem visível**. Na ficha em janela, a página também rola sem barra.
- **Entregáveis (24/11/2026, pedido do dono, "como etiquetas", igual ao ClickUp):** na ficha, à direita de "Plataformas" e separado por uma **linha vertical**, um menu de **uma etiqueta colorida** por cliente ("—" = nenhuma). A lista começa igual à do ClickUp do dono (Completo, Tráfego, Tráfego LP e CRM, … Bot-Disparos, com as cores) e o **administrador edita**: ⋯ em cada etiqueta (nome, cor entre 14, excluir), arrastar pelos pontinhos para reordenar, "+ Novo entregável". A lista fica na tabela `meta` (chave `entregaveis`, `Clientes::entregaveis/salvarEntregaveis`, `POST /api/entregaveis {opcoes}`); o cliente guarda o id em `clientes.entregaveis`; excluir uma etiqueta deixa sem entregável quem a tinha. Front em `public/assets/entregaveis.js` (só na ficha).
- **Ficha, Empresa e contato (24/11/2026, pedido do dono):** os campos de texto desse bloco (nome, contato, telefone, orçamento, CNPJ, cidade) **não mostram o 😊 de emojis** (atributo `data-sem-emoji`, respeitado pelo `app.js`). Os botões Google Ads / Meta Ads ficaram mais estreitos.
- **CNPJ | Cidade | Estado (24/11/2026, pedido do dono):** na ficha, o campo "Site do Cliente" foi trocado por **CNPJ** (formata sozinho enquanto digita; o servidor exige 14 números e guarda como `00.000.000/0000-00`), **Cidade** (até 80) e **Estado** (menu com as 27 UFs, `Clientes::UFS`). O site antigo (`clientes.link`) continua guardado no banco, mas sem campo na tela.
- **Clientes em Quadro (kanban) e Lista (24/11/2026, pedido do dono):** a tela Clientes não tem mais os círculos de iniciais. Por padrão mostra um **quadro com 4 colunas**: **Novo** (neutro), **Ativo** (verde), **Alerta** (amarelo), **Churn** (vermelho), com contagem; **arrastar e soltar** um cartão muda a situação na hora (`POST /api/clientes/{id}` com `status`; volta e avisa se der erro). Botões **Quadro | Lista** no topo (a escolha fica no navegador); a Lista é uma tabela com cliente, contato, situação, dias, turno, responsável e plataformas, **separada em grupos Novo (cinza), Ativo (verde), Alerta (amarelo) e Churn (vermelho)**, no estilo do ClickUp (24/11): o nome do status numa **pílula colorida** com a contagem ao lado, **espaço** entre os grupos e os clientes **recuados** embaixo, cada um com uma bolinha da cor do status (um `tbody.lista-grupo` por situação; mover no quadro também muda a linha de grupo). A busca vale para os dois. Código em `public/assets/clientes.js` (carregado só na tela Clientes) e `views/clientes.php`. **Ativo, Alerta e Churn entram na rotina automática; só Novo fica de fora** (decisão do dono em 24/11/2026; `Automacoes.php`, `c.status IN (...)`). Importação do ClickUp: ativo, novo, churn (cancelado/perdido/inativo/encerrado) e o resto vira alerta.
- **Cabeçalhos editáveis (24/11/2026, pedido do dono):** "Checklist do dia" e "Clientes do dia" perderam a barrinha colorida antes do título e ganharam **três pontinhos (⋮)** à direita com **Editar nome** (campo + ✓, Enter salva, Esc cancela) e **Mudar cor** (8 cores: turquesa, azul, verde, amarelo, laranja, vermelho, rosa, roxo; a cor tinge a faixa do cabeçalho e a contagem). Fica salvo **por usuário** na tabela `meta` (chave `cabecalhos:{id}`), sem mudar o banco: `GET /api/rotina` devolve `cabecalhos`; `POST /api/rotina/cabecalho {bloco, nome?, cor?}` (`Rotina::cabecalhos/salvarCabecalho`). O checklist rola sem mostrar a barra lateral. O bloco **"Em foco"** também tem ⋮ (canto superior direito da coluna da esquerda), **só com as cores**: a cor pinta a faixa do topo, o brilho, o selo "EM FOCO" e o cabeçalho "Tarefas do cliente" (bloco `foco` no mesmo `meta`).
- **Tema principal azul-petróleo/turquesa (24/11/2026, pelo modelo enviado pelo dono):** os tokens do topo do `app.css` passaram do roxo para turquesa (`--acento: #14d2c0` no escuro, `#0d9488` no claro) com fundos azul-petróleo; os roxos fixos (setas dos menus, bordas) também viraram turquesa. Os cabeçalhos de turno (Manhã, Tarde, Fechamento) **acompanham a cor do cabeçalho principal de cada bloco** (classe `cor-*` no cartão define `--cab-cor`; trocado em 24/11 a pedido, antes cada turno tinha cor própria). **Fundo chumbo escuro** no tema escuro (cinza grafite escuro: `--bg #141619`, `--panel #1d2024`, escurecido a pedido), mantendo o turquesa como destaque. Cliente selecionado sem a barrinha à esquerda.
- **Emojis (24/11/2026, pedido do dono, opção A = emojis do próprio aparelho):** em **todo campo de texto** (input de texto e textarea, em todas as telas, inclusive os criados por script) aparece um **😊** no canto direito quando o campo está selecionado; ele abre uma lista própria com **todos os emojis numa lista só**, rolando só na vertical e sem barra de rolagem visível; as abas (Carinhas, Gestos, Corações, Trabalho, Símbolos, Outros) pulam para cada grupo. **Não aparece no login nem na primeira configuração** (telas `body.sem-menu`), pedido do dono. e o emoji entra onde estava o cursor (dispara `input`, então o salvamento automático funciona). Clicar fora ou Esc fecha. Código no fim de `app.js` (`EMOJIS`, `emoji*`), CSS no fim de `app.css`. Os desenhos são os do aparelho (no Windows diferem um pouco do WhatsApp).
- **Cuidado ao conferir deploy (23/11/2026):** confira **só o HTML de `/login`** (que não fica em cache) até aparecer o `?v=` novo. **Nunca** busque `/assets/arquivo?v=<novo>` antes disso: a Cloudflare guarda por 4h a versão velha nesse endereço novo, e todo mundo recebe o arquivo antigo. Se acontecer, mude qualquer coisa no arquivo (ex.: um comentário) para gerar outro `?v=` e publique de novo. Para checar se um arquivo da página `/` mudou sem fazer login, compare o md5 local com o `?v=` que aparece no HTML só depois que ele estiver lá.
- **Tarefas do cliente no foco, concluir uma a uma (23/11/2026, pedido do dono):** a lista "Tarefas do cliente" mostra **só as tarefas com prazo de hoje**. Cada uma tem, à direita do status, um **botão de certo (✓)**: cinza e bloqueado enquanto o cliente em foco está "A fazer"; depois de clicar em **Iniciar**, todos ficam verdes e clicáveis; clicar conclui aquela tarefa (`POST /api/rotina/concluir`). O ✓ da própria tarefa da rotina faz o mesmo que "Concluir" (o foco passa para o próximo cliente). Tarefa concluída: ✓ verde cheio e nome riscado. Assim dá para concluir várias tarefas do cliente no mesmo dia. **Atualizado no mesmo dia:** o botão "Concluir" do foco saiu (depois de Iniciar só há os ✓). Concluir uma tarefa põe a **próxima da lista em "Em andamento"** (`/api/rotina/iniciar`), e o **cliente só fica Concluído quando todas as tarefas de hoje dele terminam**: `Rotina::dia` devolve `analise_feita=true` e mostra o cliente como "Em andamento" enquanto houver tarefa aberta do cliente no dia (e não conta no "N de M concluídos"). Cada tarefa da lista também tem um **botão de mais (+)** (ícone desenhado e centralizado num quadrado suave na cor do bloco, desde 24/11) antes do status: abre um **pop-up no meio da tela**, sem botões, para escrever os **detalhes da tarefa** (guardados na `descricao` via `POST /api/tarefas/{id}`); **clicar fora ou Esc fecha e salva**, escrito ou apagado. O detalhe aparece em letra menor embaixo do título. Em "Clientes do dia", cliente **Concluído** fica levemente grifado de verde (`.is-concluido`).
- **Menus de seleção próprios (23/11/2026, pedido do dono):** todo `<select>` simples que existe quando a página abre vira um menu próprio (`selecao()` em `app.js`, exposto em `Rotina.selecao`): botão no visual do sistema e lista própria (opção escolhida com ✓ e fundo roxo, setas/Enter/Esc no teclado, abre para cima perto do fim da tela). O `<select>` original fica escondido de vez (`display: none`, para a lista antiga do navegador nunca abrir ao clicar fora ou no rótulo) no formulário e recebe o valor e os eventos `input`/`change` (o salvamento automático continua funcionando). Selects criados depois por script (automações, "Add +" do checklist, filtros e gaveta de tarefas) também viram o menu próprio, por um `MutationObserver` no `app.js`.
- **Confirmações (23/11/2026, pedido do dono):** nenhuma tela usa mais o `window.confirm` do navegador. Todas usam o pop-up próprio `Rotina.confirmar(mensagem)` (em `app.js`, devolve uma Promise): caixa simples no meio da tela, com "Cancelar" e o botão da ação ("Excluir" em vermelho); Enter confirma, Esc/clique fora cancela. `data-confirmar` nos botões das telas usa o mesmo pop-up. Na ficha em janela (iframe), abre na tela principal.
- **Acabamento visual da Rotina (23/11/2026, pedido do dono, só aparência):** cartões com sombra e cantos maiores; o foco tem faixa roxa/azul no topo e brilho suave; títulos dos blocos com marcador colorido; contagem "N de M" em pílula; turnos do checklist com linha ao lado; itens com hover arredondado; "Clientes do dia" com cabeçalho de turno contornado, cliente em foco com barra roxa à esquerda e status em pílula. Bloco "Rotina: acabamento visual" no fim de `app.css`. Depois (mesmo dia) os cabeçalhos ganharam destaque: topo de "Checklist do dia" e "Clientes do dia" em faixa colorida que fica presa no alto ao rolar; turnos (Manhã, Tarde, Fechamento) e "Tarefas do cliente" com fundo roxo claro e barra à esquerda. O item selecionado (cliente em foco, na lista de clientes; desde 24/11 em chumbo bem escuro `#111316` no tema escuro; na lista de tarefas a seleção escura foi tirada em 23/11 a pedido) usa um tom mais escuro (`--selecionado`) para não se confundir com os cabeçalhos.
- **Calendário do prazo (23/11/2026, pedido do dono):** na ficha, o prazo da nova tarefa usa um **calendário próprio** (`[data-calendario]` em `app.js`, CSS no fim de `app.css`), em português, com atalhos Hoje / Amanhã / Sem prazo; os dias antes de hoje aparecem riscados e bloqueados (`data-min` = hoje, vindo do servidor). O servidor também recusa prazo no passado em `POST /clientes/{id}/tarefas`. O campo de Observações não tem mais texto de exemplo (pedido do dono).
- **Barra de formatação das Observações (19/11/2026, pedido do dono):** ao lado do título "OBSERVAÇÕES" na ficha, botões **#** (liga/desliga "# " na linha = título), **B** (`**negrito**`), **I** (`*itálico*`), **U** (`++sublinhado++`, novo no `texto_rico()` → `<u>`). Aparece junto com o campo de texto (some quando as observações estão no modo de leitura, volta ao clicar "Editar observações"). Código em `app.js` (`[data-formato]`).
- **Menus de seleção (19/11/2026, pedido do dono):** todo `select` e o menu "Dias da rotina" ganharam fundo levemente destacado, seta dentro de um círculo, borda roxa no hover e seta roxa virada para cima quando aberto; itens marcados do menu de dias ficam destacados. Bloco no fim de `app.css`.
- **Botões "Add +" (19/11/2026, pedido do dono):** todo botão que dizia "Adicionar" agora diz **"Add +"** (ficha: tarefas e notas; tela de tarefas: nova tarefa e item do checklist). Na ficha, a data do prazo da nova tarefa ficou estreita, em pílula (sem o rótulo "PRAZO", tirado a pedido), e o "Add +" fica à direita.
- **Barra do dia (19/11/2026, pedido do dono):** "Quinta-feira 19/11 | N de M concluídos" fica **no topo, na mesma linha do título "Rotina"** (entre o seletor de gestor e o botão de atualizar), mais estreita. No celular desce para a linha de baixo.
- **Checklist do dia (19/11/2026, pedido do dono):** sem caixa de marcar. Cada item tem **três pontinhos (⋮)** logo antes do Concluir, que abrem um menu com **Editar** e **Excluir** (menu em lista, embaixo do botão, ou para cima se não couber; Excluir pede confirmação e usa `POST /api/tarefas/{id}/excluir`; clique fora ou Esc fecha o menu). Editar troca o nome na hora ( botão com ícone de certo ou Enter salva, Esc cancela; trocado do lápis em 19/11; `POST /api/tarefas/{id}` com `titulo`) e um botão **"Concluir"** à direita (vira "✓ Concluído"; clicar de novo desfaz). No fim do bloco, **"Add +"** abre um campo com o turno (Manhã, Tarde ou Fechamento) e cria o item para hoje (lista 1, responsável = gestor da tela). O checklist agora mostra também o turno **Fechamento** (`TURNOS_CHECK` em `rotina.js`); os clientes do dia continuam só Manhã e Tarde.
- **Rotina sem rolagem da página (17/11/2026, pedido do dono):** no computador (largura > 860px e altura ≥ 600px) a tela da Rotina ocupa exatamente a altura da janela; Checklist do dia e Clientes do dia rolam por dentro. Em tela menor ou no celular, a página rola normalmente. CSS com `:has(#painelRotina:not([hidden]))` no fim de `app.css`.
- **"Checklist do dia" (embaixo, à esquerda):** as tarefas sem cliente do dia, tudo à mostra, separadas em **Manhã** e **Tarde**, com a contagem "N de M". Clicar marca ou desmarca; a caixa de marcar fica à **esquerda** do texto.
- **Cliente em foco (em cima, largura toda), formato fixo pedido pelo dono em 17/11/2026:** selo "EM FOCO", nome, status (pílula), campo **"Nova tarefa"** com botão só de ícone (avião de papel; Enter também envia) e os botões "**Ver informações**" (à esquerda; ficha em pop-up) e "**Iniciar**" → "**Concluir**" → "Reabrir". O subtítulo **não** aparece mais aqui. "Nova tarefa" cria uma tarefa do cliente com prazo hoje (lista 2, sem turno) e ela entra na lista "Tarefas do cliente" à direita. A antiga "Nota da tarefa" (comentário na tarefa / nota ao concluir) saiu da Rotina; as notas continuam na ficha do cliente. `rotina.js` tem `state.versao` para descartar recarga que chegue depois de um clique.
- **"Clientes do dia" (embaixo, à direita):** somente **Manhã** e **Tarde**, sem prefixo de hora. Clicar num cliente coloca ele em foco.
- **Foco automático:** o primeiro cliente não concluído do dia, manhã antes da tarde. Ao concluir, o próximo entra em foco.
- **Dia vazio:** mensagem e, se for hoje, o botão "Gerar rotina de hoje".
- A tela muda na hora e confirma no servidor; se o servidor recusar, desfaz e avisa. Recarrega sozinha a cada 60 segundos.
- A nota enviada vira um **comentário da tarefa** (aparece no Histórico e na gaveta da tarefa).

### Painel Tarefas

Vive dentro da tela Rotina, escondido (`<div id="tarefas" hidden>`). **Hoje não há botão visível para chegar nele**: só pelo endereço `/#tarefas`. Dentro dele há um alternador para voltar à Rotina. Tem vista **Lista** e **Quadro** (arrastar e soltar entre colunas), filtros (busca, lista, responsável, prioridade, cliente, só abertas), tarefa rápida e uma gaveta com status, responsável, prioridade, prazo, cliente, etiquetas, descrição, checklist, comentários e linha do tempo.

### Clientes e ficha

- Lista em cartões com busca. Formulário "Novo cliente": Nome da Empresa, Nome do Contato, Dias da rotina, Turno e, para o admin, Responsável.
- Ficha: Nome da Empresa, Nome do Contato, Responsável, Telefone, E-mail, Orçamento para Anúncios, Site do Cliente, Plataformas (cartões **Google Ads** e **Meta Ads** com logo), Dias da rotina (menu de seleção múltipla), Turno, Situação (Ativo, Novo, Pausado) e Observações. Ao lado: tarefas do cliente (com campo para **adicionar** tarefa, com prazo opcional, que entra na lista Geral com o usuário como responsável, e botão **✕ para excluir** cada uma, com confirmação; rotas `POST /clientes/{id}/tarefas` e `POST /clientes/{id}/tarefas/{tid}/excluir`), notas e, se veio do ClickUp, os dados guardados da importação.
- Só cliente **ativo com dia marcado** entra na rotina automática.
- Excluir um cliente apaga junto as tarefas **em aberto** dele (A fazer, Em andamento, Em revisão…), pedido do dono em 17/11/2026; as **concluídas** ficam, sem cliente, para o histórico.
- Os botões "Abrir link" e "Ver tarefas" do topo da ficha foram removidos a pedido.

### Automações

- **Gatilhos:** horário agendado (dias e hora), status mudou, tarefa criada, prazo venceu.
- **Condições:** lista, prioridade.
- **Ações:** criar tarefa (uma, uma por cliente agendado no dia, ou uma por gestor ativo), mudar status, atribuir, definir prioridade, adicionar etiqueta, comentar, adicionar item ao checklist. Textos aceitam `{cliente}`, `{tarefa}`, `{data}`, `{gestor}`.
- **Onde rodam:** dentro do sistema. A cada requisição de usuário logado, no máximo 1 vez por minuto, ele verifica o que está na hora. Para rodar sem ninguém acessando, agendar `php scripts/tick.php` a cada minuto.
- **Proteções:** não duplicam (chave única por automação, ação, dia e alvo), profundidade máxima 3 quando uma dispara outra, só o admin cria e edita, gestor desativado não recebe tarefa.
- A rotina do dia nasce da automação *Rotina diária dos clientes*: cria "Analisar {cliente}" para cada cliente ativo agendado naquele dia, no turno do cliente, com o gestor do cliente como responsável.

### Histórico (apagado em 23/11/2026)

A tela de Histórico foi removida a pedido do dono.

### Integrações (antiga "Importar do ClickUp", renomeada em 19/11/2026)

Só fala com `api.clickup.com`. Lê (`GET`) clientes e comentários; a **única escrita** é publicar como comentário da tarefa a nota enviada na ficha (pedido do dono em 16/11/2026: "tudo que eu preencher e mandar em Notas vai para os comentários do ClickUp, e puxa o que já estiver lá"). O admin cola o API Token, que fica criptografado com AES-256-GCM (chave em `ROTINA_SECRET` ou no arquivo `chave.key` da pasta de dados) e nunca volta para a tela. Cada tarefa da lista escolhida vira um cliente; os comentários viram notas; importar de novo atualiza sem duplicar. Regras do mapeamento:

- Situação: status do ClickUp com a palavra "ativo(s)" vira `ativo`; com "novo/nova" vira `novo`; o resto vira `pausado`. Na estrutura real do dono o status é "CLIENTES ATIVOS".
- Dia: etiqueta que começa com o nome do dia; se não houver, o dia da semana do vencimento.
- Turno: etiqueta `13h` ou `tarde` vira tarde; `17h` ou `fechamento` vira fechamento; senão manhã.
- **Observações = descrição da tarefa** (pedido do dono em 16/11/2026), pedida com `include_markdown_description=true` para vir com a formatação, inteira (até 20.000 caracteres, `Clientes::OBSERVACOES_MAX`). Na ficha ela aparece formatada por `texto_rico()` (em `Http.php`: escapa tudo antes e só cria as próprias tags; links só http/https), recolhida com Expandir, e o botão "Editar observações" mostra o campo de texto. Os campos personalizados **não** vão mais para as Observações: ficam no bloco "Dados do ClickUp" (`clickup_dados`).
- **Notas <-> comentários:** nota enviada na ficha de um cliente com `clickup_id` (e token salvo) vira comentário da tarefa (`POST /task/{id}/comment`) e fica salva aqui com `origem = clickup` e o id do comentário, para não duplicar ao puxar. Se o ClickUp falhar, a nota fica só aqui e a notificação avisa. Ao abrir a ficha, os comentários novos são puxados (no máximo 1 vez por minuto por cliente, `meta.clickup_sinc_{id}`; erro nunca quebra a página), convertidos das partes ricas do ClickUp para markdown (título, negrito, itálico, listas, links). Notas aceitam até 20.000 caracteres (`Clientes::NOTA_MAX`). O comentário sai no ClickUp com o nome do dono do token, sem assinatura de quem escreveu.
- **Subtítulo na Rotina** (`clientes.descricao`): **removido da ficha em 23/11/2026** a pedido do dono (já não aparecia na Rotina desde 17/11). A coluna continua no banco, sem uso na tela; salvar a ficha não mexe nela. A importação não preenche esse campo.

**Só foi validado com um transporte simulado nos testes. Nunca rodou contra o ClickUp de verdade.**

### Visual

Tema escuro (padrão) e claro, com botão no menu; segue o sistema na primeira visita e lembra a escolha. Funciona no celular (menu vira gaveta, sem rolagem horizontal). Os tokens de cor ficam no topo do `app.css`. Menu lateral: **Trabalho** (Rotina, Clientes) e **Sistema** (Automações, **Integrações** com ícone de elo de corrente e, para o admin, Equipe). O **Histórico** saiu do menu em 19/11/2026 e foi **apagado de vez em 23/11/2026** a pedido do dono (rota, tela `views/historico.php` e `Rotina::historico` removidos; `/historico` dá 404). As tarefas concluídas continuam no banco.

## 10. Histórico do que foi feito (em ordem)

1. A "Rotina de gestores" nasceu como módulo dentro do WhatsRedirect. Virou projeto independente nesta pasta e foi removida de lá.
2. Primeira versão: rotina com tabelas próprias (andamento, checklist, registros).
3. Segunda versão (migração v2): virou mini ClickUp. Tarefas com listas, status, prioridade, etiquetas, checklist, comentários; automações internas; ficha completa do cliente; importação do ClickUp; temas claro e escuro; Docker.
4. Ficha do cliente refeita: nomes dos campos, cartões de plataforma só com Google e Meta, menu de seleção dos dias.
5. Removidos os botões "Abrir link" e "Ver tarefas" da ficha. Removida a aba **Tarefas** do menu: as tarefas foram para dentro da tela Rotina.
6. O alternador "Rotina do dia | Tarefas" foi para o lado do título e depois **removido**, junto com o texto de progresso ("0 de 2 clientes concluídos"). O seletor de gestor foi para o lado do título.
7. Organização visual da Rotina melhorada (blocos de acabamento no fim do `app.css`).
8. "Concluir" só aparece depois de "Iniciar".
9. "Abrir cliente" passou a abrir a ficha em pop-up (iframe em modo embutido). Botões renomeados: "Abrir cliente" virou "Ver Informações"; "Concluir análise" virou "Concluir".
10. "Clientes do dia": só Manhã e Tarde, sem "08h/13h/17h", sem Fechamento.
11. Nota: passou a poder ser enviada sem concluir; botão só com ícone; rótulo "Nota da Tarefa".
12. Removidas as abas dos dias da semana: a Rotina mostra só hoje.
13. Checklist: título "Check List do Dia", tudo à mostra, separado em Manhã e Tarde. Removidos os botões de filtro de turno.
14. Login trocado para `admin` / `1`: o campo passou a aceitar login simples e a senha mínima virou configurável (padrão 1).

## 11. Pendências e pontos de atenção

### Perguntas feitas ao dono, ainda sem resposta

1. **Onde ficam as Tarefas?** Sem o alternador, o painel só abre por `/#tarefas`. Opções oferecidas: colocar as tarefas em outro ponto da tela Rotina (por exemplo um bloco abaixo do checklist) ou tirar de vez.
2. **Turno "Fechamento":** a Rotina não mostra mais, mas ele continua existindo na ficha, no formulário de novo cliente, no banco, no construtor de automações e na automação *Checklist do fechamento*. Oferecido: tirar dos formulários e passar o que existe para Tarde.
3. **Rótulos com hora** ("08h · Manhã", "13h · Tarde") continuam na ficha, no novo cliente, na gaveta de tarefas e no construtor de automações. Oferecido: trocar para "Manhã" e "Tarde".
4. **Ficha em pop-up:** o link "Clientes / ficha" e o botão Excluir navegam dentro do pop-up para a lista de clientes. Oferecido: esconder os dois no modo embutido.

### Inconsistências reais encontradas na auditoria

- **Tarefas de fechamento invisíveis que acumulam.** A automação *Checklist do fechamento* cria todo dia 2 tarefas por gestor, e clientes com turno fechamento geram "Analisar {cliente}". Nada disso aparece na Rotina. Ficam abertas, vencem e a automação de atraso marca como "atrasada" com prioridade alta. Depende da resposta à pergunta 2; enquanto isso, dá para desligar essa automação na tela Automações.
- **Tarefas de outros dias somem da Rotina.** O que não foi concluído ontem não aparece hoje (continua no painel Tarefas e fica "atrasada").
- **Notas enviadas listadas abaixo do campo só existem na memória da página.** Somem ao recarregar. Continuam salvas como comentário da tarefa.
- **Sobras no código, sem efeito:** em `rotina.js`, os tratadores de `.ir-turno` e `.turno`, as referências a `rotinaProgressoTexto`, `rotinaBarra` e `rotinaBarraWrap`, e a variável `datas` em `escolherInicio`; no `app.css`, estilos de `.abas`, `.aba`, `.turnos`, `.turno`; em `Rotina.php` e na API, a semana inteira ainda é calculada e enviada, embora a tela use só hoje.
- **Colunas guardadas mas sem campo na tela:** `clientes.empresa`, `clientes.link` (o antigo "Site do Cliente", trocado por CNPJ/Cidade/Estado em 24/11/2026) e `clientes.descricao` (o antigo "Subtítulo na Rotina", removido da ficha em 23/11/2026).
- **Plataformas:** o serviço aceita google, meta, tiktok, linkedin e outros; a ficha só mostra Google e Meta. Salvar a ficha de um cliente que tinha outra plataforma apaga essa marcação.
- **Logo do Meta** é um desenho simples, não a marca oficial.
- **`data/.gitkeep`** é citado no `.gitignore` mas não existe (sem impacto: a pasta é criada ao abrir o banco).

### Antes de qualquer publicação (quando o dono pedir)

- `ROTINA_SENHA_MIN=8`, trocar as senhas `1`, `ROTINA_COOKIE_SECURE=1`, definir `ROTINA_SECRET`.
- Conferir o bloqueio de login atrás de proxy: ele usa o IP da conexão; o Dockerfile liga o `mod_remoteip` para faixas privadas.
- Backup do `rotina.sqlite` (o tick já roda sozinho dentro do container).
- Testar a importação com um token real do ClickUp.
- Projeto **separado** no Easypanel, com volume em `/data` e domínio próprio. O roteiro está no `README.md`.

### Ideias anotadas, não pedidas

Tarefas recorrentes por cliente, subtarefas, anexos, visão de calendário, carga por gestor, checklist editável pelo gestor, notificações, recuperação de senha por e-mail.

### Preview na VPS (pedido em 13/11/2026)

**No ar desde 13/11/2026 em `https://rotina.whatsredirect.pro`** (subdomínio provisório do WhatsRedirect, a pedido do dono, até ele ter domínio próprio; registro A `rotina` → `217.196.60.39`, passando pela Cloudflare). Também responde no endereço provisório do Easypanel `rotina-app.wgt1ud.easypanel.host`. Easypanel: projeto `rotina`, serviço App `app`, origem GitHub `mjads-automacoes/app_rotina` ramo `claude/new-session-10ewul`, build Dockerfile, volume `dados` em `/data`, variáveis `ROTINA_SENHA_MIN=8`, `ROTINA_COOKIE_SECURE=1`, `ROTINA_TIMEZONE=America/Fortaleza`, porta 80. Banco começou vazio (sem seed): o dono cria o administrador no Primeiro acesso. O dono fez tudo no painel guiado por prints: a sessão na nuvem **não alcança a VPS** (IP bloqueado; o painel fica na porta 3000, que o proxy não atravessa). **Atualização automática (16/11/2026):** o dono ligou um webhook no GitHub (evento push) apontando para o Gatilho de Implantação do serviço (endereço secreto); todo push no repositório implanta sozinho em 1 a 3 minutos. Para conferir de fora se a versão nova chegou, compare um arquivo público (ex.: `/assets/app.css` ou `/assets/rotina.js`) com o do ramo. O painel do Easypanel também abre em `https://wgt1ud.easypanel.host` (login aceito com o e-mail em minúsculas), mas as chamadas de API daqui deram 401 por causa de uma chave salva nos Network secrets da sessão para esse host; o dono preferiu o webhook. Ele colou o login e a senha do Easypanel no chat e foi orientado a trocar a senha. **Nunca grave credenciais em arquivo.**

**Dados fictícios online (pedido em 13/11/2026, opção B):** o dono quis os dados de teste na versão online para treinar. Feito pela variável `ROTINA_DEMO` no Easypanel (Ambiente): `carregar` cria os 19 clientes fictícios (pelo menos 6 por dia útil, pedido do dono) e a semana da rotina ligados ao primeiro administrador ativo (sem a Ana, sem senhas `1`), uma vez só; `apagar` remove só o que a carga criou (clientes fictícios, todas as tarefas deles, inclusive as que a rotina gerar depois, as tarefas criadas na carga e a automação de exemplo) e mantém o que for real. Roda na partida do container (precisa Implantar). O registro do que é fictício fica em `meta.demo`. Testado em `tests/demo_test.php` e no Docker.

## 12. Fora deste projeto (não tratar aqui)

O dono tem pendências no WhatsRedirect (outra pasta, outro repositório): um erro de conta MCC do Google Ads aguardando decisão dele, alterações locais aguardando "pode publicar", e um lembrete para trocar a senha do Easypanel que foi colada num chat. Nada disso pertence ao Rotina. Se ele tocar nesses assuntos numa sessão do Rotina, avise que é outro projeto.

## 13. Roteiro da primeira sessão na nuvem

1. Ler este arquivo e o `README.md`.
2. Conferir o ambiente: `php -v`, `php -m | grep -i sqlite`, `node -v`.
3. Rodar `php tests/run.php`. Tem de dar **90 de 90**.
4. Rodar o teste de navegador (com `EDGE_PATH` apontando para um Chromium). Se não houver navegador no ambiente, diga isso ao dono em vez de pular em silêncio.
5. Subir o preview (seção 4) e entrar com `admin` / `1`.
6. Perguntar ao dono o que ele quer mudar. Se ele não trouxer nada, apresentar as 4 perguntas da seção 11.
7. A cada mudança: código, teste novo ou ajustado, os dois conjuntos de teste, preview, explicação simples, commit e push (vai para o ar).
8. No fim, atualizar este arquivo.
