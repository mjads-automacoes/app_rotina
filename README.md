# Rotina

> Para continuar o trabalho em outra sessão, leia primeiro `CONTEXTO-NOVA-SESSAO.md`.

Sistema **independente** de gestão de tráfego: clientes, tarefas e a rotina diária dos gestores, com automações que rodam **dentro** do próprio sistema. A ideia, a longo prazo, é **parar de usar o ClickUp**: hoje ele serve só para importar os dados de cada cliente, e o resto já acontece aqui.

Não depende do WhatsRedirect (sem código, banco, login nem domínio em comum).

> O nome "Rotina" é provisório. Ele existe em **um único lugar**: `ROTINA_APP_NAME` (variável de ambiente) ou `src/config.php`.

## O que tem

| Tela | O que faz |
|---|---|
| **Rotina** | Modo foco, só o dia de hoje: "Check List do Dia" à esquerda (Manhã e Tarde), cliente em foco no centro (Nota da Tarefa, Ver Informações em pop-up, Iniciar e depois Concluir), clientes do dia à direita. |
| **Tarefas** | Painel dentro da tela Rotina (endereço `/#tarefas`). Estilo ClickUp, simples: **Lista** e **Quadro** (arrastar e soltar), filtros, tarefa rápida, e gaveta com status, responsável, prioridade, prazo, cliente, etiquetas, descrição, **checklist**, **comentários** e linha do tempo de atividade. |
| **Clientes** | Cards + ficha completa (empresa, contato, plataformas, orçamento, observações, dias/turno da rotina), notas e as tarefas do cliente. |
| **Automações** | "Quando X, faça Y" (veja abaixo). Liga/desliga, executar agora, histórico de execuções e construtor visual. |
| **Histórico** | Tudo o que foi concluído, com responsável e nota. |
| **Importar** | Traz clientes e comentários de uma lista do ClickUp (somente leitura). |
| **Equipe / Conta** | Admin cria gestores, ativa/desativa e redefine senha; cada um troca a própria senha. |

Tema **escuro** e **claro** (botão no menu; segue o sistema na primeira visita e lembra a escolha). Funciona no celular.

## Como as automações funcionam

- **Gatilhos:** em horário agendado (dias + hora), quando o status mudar, quando uma tarefa for criada, quando o prazo vencer.
- **Condições (opcionais):** lista, prioridade.
- **Ações:** criar tarefa (uma, uma por cliente agendado no dia, ou uma por gestor), mudar status, atribuir, definir prioridade, adicionar etiqueta, comentar, adicionar item ao checklist. Textos aceitam `{cliente}`, `{tarefa}`, `{data}`, `{gestor}`.
- **Já vêm prontas:** a *Rotina diária dos clientes* (cria "Analisar {cliente}" para cada cliente ativo agendado, às 05:00 de seg a sex), os três *Checklists do turno* (manhã, tarde, fechamento) e *Tarefa atrasada ganha prioridade alta*.
- **Onde rodam:** dentro do sistema. A cada requisição (no máximo 1 vez por minuto) ele vê o que está na hora. Para rodar mesmo sem ninguém acessando, agende `php scripts/tick.php` no cron do servidor.
- **Segurança das automações:** idempotentes (não duplicam tarefas), uma automação que dispara outra é contida (profundidade máxima 3) e só o administrador cria/edita.

## Tecnologia

PHP 8.2 + SQLite (um arquivo), sem Composer e sem dependências. Roda em Docker (`php:8.2-apache`). Banco versionado por migração (`src/Db.php`).

## Rodar no computador

```powershell
powershell -File scripts/serve.ps1            # http://127.0.0.1:8200
php -d extension=pdo_sqlite -d extension=sqlite3 scripts/seed-demo.php   # dados de exemplo (opcional)
```

Com os dados de exemplo, entre com `admin` / `1` (ou `ana` / `1`). Sem eles, a tela **Primeiro acesso** cria o administrador. Os dados ficam em `data/rotina.sqlite` (fora do git).

## Testes

```powershell
php tests/run.php          # 81 testes: banco, login, clientes, tarefas, automações, rotina, importação, dados de exemplo e camada web
node tests/browser_test.js # ponta a ponta no Edge (todas as telas, os 2 temas e o celular); SHOT=pasta salva prints
```

## Configuração (variáveis de ambiente)

| Variável | Padrão | Para quê |
|---|---|---|
| `ROTINA_APP_NAME` | `Rotina` | Nome mostrado no sistema |
| `ROTINA_DATA_DIR` | `./data` (`/data` no Docker) | Pasta do banco SQLite (e da chave que protege o token do ClickUp) |
| `ROTINA_TIMEZONE` | `America/Fortaleza` | Fuso (define "hoje", o turno e a hora das automações) |
| `ROTINA_COOKIE_SECURE` | `0` | Use `1` em produção com HTTPS |
| `ROTINA_SENHA_MIN` | `1` | Tamanho mínimo da senha. **Use `8` em produção** |
| `ROTINA_DEMO` | (vazio) | `carregar` põe dados fictícios ligados ao primeiro administrador (uma vez só); `apagar` remove só eles. Roda na partida do container |
| `ROTINA_SECRET` | (gerada em `data/chave.key`) | Chave que criptografa o token do ClickUp |

## Publicar na VPS (projeto separado)

Crie um **projeto novo** no Easypanel (por exemplo `rotina`), sem reaproveitar `whatsredirect`, `track` nem os serviços deles:

1. Serviço do tipo **App** a partir deste repositório (build pelo `Dockerfile`).
2. **Volume** persistente montado em `/data` (banco e chave).
3. Variáveis: `ROTINA_COOKIE_SECURE=1`, `ROTINA_SENHA_MIN=8` e, se quiser, `ROTINA_APP_NAME`.
4. **Domínio:** enquanto não houver domínio próprio, use o endereço provisório que o Easypanel gera para o serviço (`*.easypanel.host`, já com HTTPS). Depois, aponte o domínio próprio para o mesmo serviço; os dados continuam no volume. Porta interna do container: **80**.
5. As automações rodam sozinhas a cada minuto dentro do container (`scripts/docker-entrypoint.sh`); não é preciso cron no servidor. O mesmo script acerta a permissão do volume `/data` na partida.
6. Backup: copiar `rotina.sqlite` do volume (`sqlite3 rotina.sqlite ".backup rotina-AAAAMMDD.sqlite"`).

## Importar do ClickUp

Somente leitura (só `GET`). Em **Importar**: o administrador cola o API Token (fica criptografado e nunca é exibido), escolhe a lista de clientes e importa. Cada tarefa vira um cliente (status "ativo" entra na rotina, "novo" fica de fora; o dia vem da etiqueta ou do vencimento) e os comentários viram notas. Importar de novo atualiza sem duplicar.

## Próximas ideias

- Tarefas recorrentes por cliente (além da rotina diária), subtarefas e anexos.
- Visão de calendário e de carga por gestor.
- Checklist do turno editável pelo gestor e notificações.
- Recuperação de senha por e-mail.
