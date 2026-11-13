# Rotina

Antes de qualquer coisa, leia `CONTEXTO-NOVA-SESSAO.md` inteiro: ele traz o estado do projeto, as decisões já tomadas, as pendências e como rodar.

Regras fixas:

- Responda sempre em português do Brasil, em linguagem simples. O dono não é programador e pede mudanças por print de tela.
- Não publique nada (deploy, VPS, domínio) sem pedido explícito. Commit e push só quando ele pedir.
- Faça exatamente a mudança pedida; quando ela deixar algo sem acesso ou sem uso, avise e pergunte.
- PHP 8.2 + SQLite + JavaScript puro, sem dependências. Sem script ou estilo inline (a CSP bloqueia). Mudança de banco = nova função `vN` em `src/Db.php`.
- Toda mudança termina com `php tests/run.php` (78 testes) e `node tests/browser_test.js` passando, e com o preview no ar quando for visual.
- Login de teste: `admin` / `1` (dados de `php scripts/seed-demo.php`).
- Ao fim da sessão, atualize `CONTEXTO-NOVA-SESSAO.md`.
