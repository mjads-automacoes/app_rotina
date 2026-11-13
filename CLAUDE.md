# Rotina

Antes de qualquer coisa, leia `CONTEXTO-NOVA-SESSAO.md` inteiro: ele traz o estado do projeto, as decisões já tomadas, as pendências e como rodar.

Regras fixas:

- Responda sempre em português do Brasil, em linguagem simples. O dono não é programador e pede mudanças por print de tela.
- A versão online é `https://rotina.whatsredirect.pro` (Easypanel, ramo `claude/new-session-10ewul`). Regra do dono (13/11/2026): **toda mudança feita vai para o ar**. Com os testes passando, faça commit e push nesse ramo sem esperar pedido. Se um teste falhar, não envie e avise. Mexer no Easypanel, na VPS, no domínio ou na infraestrutura continua exigindo pedido explícito.
- Faça exatamente a mudança pedida; quando ela deixar algo sem acesso ou sem uso, avise e pergunte.
- PHP 8.2 + SQLite + JavaScript puro, sem dependências. Sem script ou estilo inline (a CSP bloqueia). Mudança de banco = nova função `vN` em `src/Db.php`.
- Toda mudança termina com `php tests/run.php` (82 testes) e `node tests/browser_test.js` passando, e com o preview no ar quando for visual.
- Login de teste: `admin` / `1` (dados de `php scripts/seed-demo.php`).
- Ao fim da sessão, atualize `CONTEXTO-NOVA-SESSAO.md`.
