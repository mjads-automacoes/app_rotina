// Teste de ponta a ponta no navegador (Edge headless via CDP): node tests/browser_test.js
// Sobe o proprio servidor PHP com banco temporario + dados de exemplo. SHOT=pasta salva prints (tema escuro e claro).
const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const RAIZ = path.resolve(__dirname, '..');
const EDGE = process.env.EDGE_PATH || (process.platform === 'win32' ? 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' : 'chromium');
// Fora do Windows (nuvem, conteiner como root) o Chromium so abre sem a sandbox.
const EXTRA_NAV = process.platform === 'win32' ? [] : ['--no-sandbox'];
const PORTA = 8231;
const BASE = `http://127.0.0.1:${PORTA}`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const EXT = ['-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3'];

(async () => {
  const dados = fs.mkdtempSync(path.join(os.tmpdir(), 'rotina-e2e-'));
  const env = { ...process.env, ROTINA_DATA_DIR: dados };
  const seed = spawnSync('php', [...EXT, path.join(RAIZ, 'scripts', 'seed-demo.php')], { env, encoding: 'utf8' });
  assert.ok(seed.stdout.includes('Pronto'), 'dados de exemplo: ' + seed.stdout + seed.stderr);
  const php = spawn('php', [...EXT, '-S', `127.0.0.1:${PORTA}`, '-t', path.join(RAIZ, 'public'), path.join(RAIZ, 'public', 'router.php')], { env, stdio: 'ignore' });
  const edge = spawn(EDGE, [...EXTRA_NAV, '--headless=new', '--remote-debugging-port=9362', '--window-size=1440,900', '--user-data-dir=' + path.join(dados, 'edge'), 'about:blank'], { stdio: 'ignore' });
  let ws;
  try {
    for (let i = 0; i < 50; i++) { try { await fetch(BASE + '/login', { redirect: 'manual' }); break; } catch { await sleep(200); } }
    let lista;
    for (let i = 0; i < 40; i++) { try { lista = await (await fetch('http://127.0.0.1:9362/json')).json(); break; } catch { await sleep(250); } }
    ws = new WebSocket(lista.find((t) => t.type === 'page').webSocketDebuggerUrl);
    await new Promise((r) => (ws.onopen = r));
    let id = 0; const pend = new Map(); const erros = [];
    ws.onmessage = (m) => {
      const d = JSON.parse(m.data);
      if (d.id && pend.has(d.id)) { pend.get(d.id)(d); pend.delete(d.id); }
      if (d.method === 'Runtime.exceptionThrown') erros.push(d.params.exceptionDetails.text + ' ' + (d.params.exceptionDetails.exception?.description || ''));
      if (d.method === 'Runtime.consoleAPICalled' && d.params.type === 'error') erros.push('console.error: ' + d.params.args.map((a) => a.value || a.description).join(' '));
      if (d.method === 'Log.entryAdded' && d.params.entry.level === 'error' && !String(d.params.entry.url).includes('favicon')) erros.push('log: ' + d.params.entry.text + ' ' + d.params.entry.url);
    };
    const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
    const ev = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })).result.result.value;
    const esperar = async (expr, n = 80) => { for (let i = 0; i < n; i++) { if (await ev(expr)) return true; await sleep(150); } return false; };
    const ir = async (url) => { await send('Page.navigate', { url: BASE + url }); await sleep(250); await esperar("document.readyState === 'complete'"); };
    const texto = (sel) => ev(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ''`);
    const clicar = (sel) => ev(`document.querySelector(${JSON.stringify(sel)}).click()`);
    const n = (sel) => ev(`document.querySelectorAll(${JSON.stringify(sel)}).length`);
    const preencher = (campos) => ev(Object.entries(campos).map(([nome, v]) => `(()=>{const e=document.querySelector('form [name=${nome}]'); if(e){ e.value=${JSON.stringify(v)}; }})()`).join(';'));
    const shot = async (nome) => {
      if (!process.env.SHOT) return;
      fs.mkdirSync(process.env.SHOT, { recursive: true });
      fs.writeFileSync(path.join(process.env.SHOT, nome), Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).result.data, 'base64'));
    };
    const tema = (t) => ev(`document.documentElement.dataset.tema = ${JSON.stringify(t)}`);
    await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    // ---- login
    await ir('/');
    assert.equal(await ev('location.pathname'), '/login', 'sem sessao cai no login');
    await preencher({ email: 'admin', senha: '1' });
    await ev("document.querySelector('form').requestSubmit()");
    assert.ok(await esperar("location.pathname === '/'"), 'entrou');
    assert.ok(await esperar("!!document.querySelector('.menu .marca')"), 'menu carregou');
    assert.equal(await texto('.menu .marca'), 'RRotina', 'nome do produto (logo + nome) vem da configuracao');

    await ev("window.localStorage.setItem('rotina_tema', 'escuro')");
    await tema('escuro');

    // ---- Rotina (modo foco)
    assert.ok(await esperar("document.querySelector('#rotinaDias strong')"), 'a rotina carregou');
    const hoje = await ev("document.getElementById('rotina').dataset.hoje");
    assert.ok((await n('#rotinaClientes .rotina-cliente')) >= 2, 'clientes do dia aparecem');
    // formato: cliente em foco em cima, na largura toda; checklist e clientes lado a lado embaixo
    assert.equal(await ev("[...document.getElementById('rotinaGrade').children].map(c => c.id).join(',')"), 'rotinaFoco,rotinaChecklist,rotinaClientes', 'ordem: foco, checklist, clientes');
    assert.equal(await ev("(() => { const f = rotinaFoco.getBoundingClientRect(), c = rotinaChecklist.getBoundingClientRect(), k = rotinaClientes.getBoundingClientRect(); return f.bottom <= c.top && Math.abs(c.top - k.top) < 2 && Math.abs(c.width - k.width) < 2 && Math.abs(c.height - k.height) < 2 && f.width > c.width * 1.8; })()"), true, 'foco na largura toda; checklist e clientes lado a lado, mesma largura e altura');
    assert.equal(await ev("(() => { const i = document.querySelector('.rotina-item'); return i.firstElementChild.className + '|' + i.lastElementChild.className; })()"), 'rotina-check|rotina-item-texto', 'caixa de marcacao a esquerda do texto');
    assert.ok((await texto('#rotinaDias small')).endsWith('concluídos'), 'barra do dia: N de M concluidos');
    assert.equal(await ev('document.documentElement.scrollHeight <= innerHeight + 1'), true, 'Rotina cabe na tela, sem barra de rolagem da pagina');
    // foco em duas colunas: a direita, depois de uma linha, as tarefas do cliente com o status
    assert.ok(await esperar("document.querySelectorAll('#rotinaFoco .rotina-foco-tarefas .rotina-tarefa .pilula').length > 0 && rotinaFoco.querySelector('.rotina-foco-tarefas').getBoundingClientRect().left > rotinaFoco.querySelector('.rotina-foco-principal').getBoundingClientRect().right - 1"), 'tarefas do cliente ao lado direito do foco, com status');
    const nome1 = await texto('.rotina-foco-nome');
    const antes = await texto('#rotinaDias small');
    const semNull = "!/\\bnull\\b|undefined/.test(document.getElementById('rotina').innerText)";
    assert.equal(await ev(semNull), true, 'a tela da Rotina nao mostra null nem undefined');
    await shot('1-rotina-escuro.png');
    // foco no formato fixo: selo, nome, status, nova tarefa (sem subtitulo)
    assert.equal(await ev("(() => { const f = rotinaFoco.querySelector('.rotina-foco-principal'); return [...f.children].map(c => c.className.split(' ')[0]).join(','); })()"), 'rotina-selo,rotina-foco-nome,rotina-foco-status,rotina-foco-nota,rotina-foco-acoes', 'formato fixo do foco');
    assert.equal(await ev("(() => { const b = rotinaFoco.querySelector('[data-acao=\"abrir-cliente\"]').getBoundingClientRect(), p = rotinaFoco.querySelector('.rotina-foco-principal').getBoundingClientRect(), i = rotinaFoco.querySelector('[data-acao=\"iniciar\"]').getBoundingClientRect(); return b.left - p.left < 4 && i.right > p.right - 30; })()"), true, 'Ver informacoes a esquerda, Iniciar a direita');
    const tarefasAntes = await ev("document.querySelectorAll('#rotinaFoco .rotina-tarefa').length");
    await ev("document.getElementById('rotinaNota').value = 'Tarefa nova pelo foco'; document.getElementById('rotinaNota').dispatchEvent(new Event('input', { bubbles: true }))");
    await clicar('[data-acao="nova-tarefa"]');
    assert.ok(await esperar("[...document.querySelectorAll('#rotinaFoco .rotina-tarefa')].some(x => x.textContent.includes('Tarefa nova pelo foco'))"), 'nova tarefa aparece em Tarefas do cliente');
    assert.ok(await ev("document.querySelectorAll('#rotinaFoco .rotina-tarefa').length") > tarefasAntes, 'lista cresceu');
    assert.equal(await ev("document.getElementById('rotinaNota').value"), '', 'campo limpo apos criar');
    for (let i = 0; i < 3 && (await n('#rotinaFoco .rotina-tarefa')) <= 3; i++) {
      await ev("document.getElementById('rotinaNota').value = 'Extra " + i + "'; document.getElementById('rotinaNota').dispatchEvent(new Event('input', { bubbles: true }))");
      const antes2 = await n('#rotinaFoco .rotina-tarefa');
      await clicar('[data-acao="nova-tarefa"]');
      await esperar(`document.querySelectorAll('#rotinaFoco .rotina-tarefa').length > ${antes2}`);
    }
    assert.equal(await ev("(() => { const ul = rotinaFoco.querySelector('.rotina-tarefas-lista'), r = ul.getBoundingClientRect(); const vis = [...ul.children].filter(li => { const b = li.getBoundingClientRect(); return b.top >= r.top - 1 && b.bottom <= r.bottom + 1; }).length; return ul.children.length > 3 && vis === 3 && ul.scrollHeight > ul.clientHeight; })()"), true, 'tarefas do cliente: 3 visiveis, o resto rolando');
    assert.equal(await ev("document.querySelector('[data-acao=\"concluir\"]') === null"), true, 'Concluir so aparece depois de Iniciar');
    await clicar('[data-acao="abrir-cliente"]');
    assert.ok(await esperar("!!document.querySelector('.janela-quadro')"), 'ficha abre em janela');
    await sleep(900);
    await shot('1b-ficha-janela.png');
    assert.equal(await ev("!!document.querySelector('.janela-topo .janela-titulo .btn-lixeira') && !document.querySelector('.janela-quadro').contentDocument.querySelector('.form-lixeira').offsetParent"), true, 'lixeira no topo da janela, ao lado do nome (e nao repetida dentro)');
    // aviso salvo dentro da janela aparece na tela principal, no canto superior direito
    await ev("(() => { const d = document.querySelector('.janela-quadro').contentDocument; const t = d.querySelector('.nota-form textarea'); t.value = 'Nota pela janela'; t.form.requestSubmit(); })()");
    assert.ok(await esperar("[...document.querySelectorAll('#notificacoes .notificacao')].some(n => n.textContent.includes('Nota adicionada'))"), 'notificacao da janela aparece na tela principal');
    assert.equal(await ev("(() => { const r = document.querySelector('#notificacoes .notificacao').getBoundingClientRect(); return r.top < 40 && innerWidth - r.right < 40; })()"), true, 'notificacao no canto superior direito');
    await shot('1c-notificacao.png');
    await ev("document.querySelector('.janela-topo [data-janela-fechar]').click()");
    assert.equal(await ev("document.querySelector('.janela-fundo') === null"), true, 'janela fecha');
    await clicar('[data-acao="iniciar"]');
    assert.ok(await esperar("!!document.querySelector('[data-acao=\"concluir\"]') && !document.querySelector('[data-acao=\"iniciar\"]')"), 'Iniciar vira Concluir');
    await clicar('[data-acao="concluir"]');
    assert.notEqual(await texto('#rotinaDias small'), antes, 'o progresso andou na hora');
    assert.notEqual(await texto('.rotina-foco-nome'), nome1, 'o proximo cliente entra em foco');
    assert.equal(await ev(semNull), true, 'sem null depois de concluir');
    await sleep(700);
    await clicar('#rotinaChecklist .rotina-item');
    const marcado = await ev("document.querySelector('#rotinaChecklist .rotina-item').getAttribute('aria-checked')");
    assert.equal(marcado, 'true', 'item do checklist marcado');
    await sleep(700);
    await ir('/');
    assert.ok(await esperar("document.querySelector('#rotinaDias strong')"));
    assert.equal(await ev("document.querySelector('#rotinaChecklist .rotina-item').getAttribute('aria-checked')"), 'true', 'o check sobrevive ao recarregar');
    await tema('claro');
    await shot('2-rotina-claro.png');
    await tema('escuro');

    // ---- Tarefas: lista, tarefa rapida, gaveta, quadro
    await ir('/#tarefas');
    assert.ok(await esperar("document.querySelectorAll('.tarefa-linha').length > 3"), 'lista de tarefas');
    await ev("document.getElementById('fLista').value = '2'; document.getElementById('fLista').dispatchEvent(new Event('change'))");
    await sleep(500);
    await ev("document.getElementById('rapidoTitulo').value = 'Tarefa rápida do teste'; document.getElementById('rapido').requestSubmit()");
    assert.ok(await esperar("[...document.querySelectorAll('.tarefa-titulo')].some(x => x.textContent === 'Tarefa rápida do teste')"), 'tarefa rapida criada');
    await ev("[...document.querySelectorAll('.tarefa-linha')].find(x => x.textContent.includes('Tarefa rápida do teste')).click()");
    assert.ok(await esperar("!document.getElementById('gaveta').hidden && document.querySelector('#gaveta .titulo-input')"), 'gaveta abriu');
    await ev("(() => { const f = document.querySelector('#gaveta .linha-add input'); f.value = 'Conferir tudo'; f.closest('form').requestSubmit(); })()");
    assert.ok(await esperar("document.querySelectorAll('#gaveta .item-check').length === 1"), 'item do checklist adicionado');
    await ev("(() => { const forms = document.querySelectorAll('#gaveta .linha-add'); const f = forms[forms.length - 1].querySelector('input'); f.value = 'Tudo certo por aqui'; forms[forms.length - 1].requestSubmit(); })()");
    assert.ok(await esperar("document.querySelector('#gaveta .atividade-comentario')"), 'comentario aparece na atividade');
    await shot('3-tarefa-gaveta-escuro.png');
    await ev("document.querySelector('#gaveta .gaveta-topo .btn-icone').click()");
    await clicar('[data-vista="quadro"]');
    assert.ok(await esperar("document.querySelectorAll('.coluna-quadro').length === 4"), 'quadro com as 4 colunas da lista Geral');
    assert.ok((await n('.card-tarefa')) >= 3, 'cartoes no quadro');
    await shot('4-quadro-escuro.png');
    await tema('claro');
    await shot('5-quadro-claro.png');
    await tema('escuro');

    // ---- Automacoes
    await ir('/automacoes');
    assert.ok(await esperar("document.querySelectorAll('#autoLista .auto-card').length >= 5"), 'automacoes padrao + exemplo');
    await shot('6-automacoes-escuro.png');
    await ev("document.querySelector('#autoLista .interruptor').click()");
    assert.ok(await esperar("document.querySelector('#autoLista .interruptor').getAttribute('aria-checked') === 'false'"), 'automacao desligada');
    await clicar('#autoNova');
    assert.ok(await esperar("!document.getElementById('gaveta').hidden && document.querySelector('#gaveta input[type=text]')"), 'construtor abriu');
    await ev("(() => { const f = document.querySelector('#gaveta input[type=text]'); f.value = 'Teste automático'; f.dispatchEvent(new Event('input', { bubbles: true })); })()");
    await ev("(() => { const t = [...document.querySelectorAll('#gaveta input[type=text]')].pop(); t.value = 'Olá do teste'; t.dispatchEvent(new Event('input', { bubbles: true })); })()");
    await shot('7-automacao-construtor-escuro.png');
    await ev("[...document.querySelectorAll('#gaveta button')].find(b => b.textContent === 'Criar automação').click()");
    assert.ok(await esperar("[...document.querySelectorAll('#autoLista strong')].some(x => x.textContent === 'Teste automático')"), 'automacao criada');

    // ---- Clientes e ficha
    await ir('/clientes');
    assert.ok((await n('.cliente-card')) >= 8, 'cards de clientes');
    await shot('8-clientes-escuro.png');
    await ev("document.querySelector('.cliente-card').click()");
    assert.ok(await esperar("location.pathname.startsWith('/clientes/')"), 'ficha do cliente');
    await ev("(() => { const t = document.querySelector('.nota-form textarea'); t.value = 'Nota criada no teste'; t.form.requestSubmit(); })()");
    assert.ok(await esperar("document.body.textContent.includes('Nota criada no teste')"), 'nota salva');
    // tarefas do cliente: adicionar pela ficha e excluir com confirmacao
    await ev("(() => { const f = document.querySelector('.tarefa-nova'); f.querySelector('[name=titulo]').value = 'Tarefa criada na ficha'; f.querySelector('[name=prazo]').value = '2026-12-01'; f.requestSubmit(); })()");
    assert.ok(await esperar("[...document.querySelectorAll('.lista-simples li')].some(li => li.textContent.includes('Tarefa criada na ficha') && li.textContent.includes('01/12'))"), 'tarefa adicionada na ficha');
    await ev("window.confirm = () => true; [...document.querySelectorAll('.lista-simples li')].find(li => li.textContent.includes('Tarefa criada na ficha')).querySelector('.btn-excluir-tarefa').click()");
    await sleep(400);
    assert.ok(await esperar("document.readyState === 'complete' && !document.body.textContent.includes('Tarefa criada na ficha') && document.body.textContent.includes('Tarefa excluída')"), 'tarefa excluida na ficha');
    assert.ok(await esperar("!!document.querySelector('#notificacoes .notificacao')"), 'aviso do servidor vira notificacao');
    assert.equal(await ev("document.querySelector('.conteudo .aviso') === null"), true, 'sem faixa de aviso parada no topo');
    await sleep(5600);
    assert.equal(await ev("document.querySelectorAll('#notificacoes .notificacao').length"), 0, 'notificacao some em 5 segundos');
    await shot('9-cliente-ficha-escuro.png');
    // menu de selecao dos dias: abre, marca, atualiza o rotulo e fecha ao clicar fora
    await ev("document.querySelector('[data-multi] summary').click()");
    assert.ok(await esperar("document.querySelector('[data-multi]').open"), 'menu de dias abre');
    await ev("(() => { const i = document.querySelector('[data-multi] input[value=quarta]'); i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); })()");
    assert.ok(await esperar("document.querySelector('[data-multi-rotulo]').textContent.includes('Qua')"), 'rotulo mostra os dias marcados');
    await shot('9b-dias-menu-aberto.png');
    await ev("document.querySelector('h1').click()");
    assert.ok(await esperar("!document.querySelector('[data-multi]').open"), 'menu de dias fecha ao clicar fora');
    // salva sozinha: sem botao Salvar; muda um campo, espera o 'Salvo', recarrega e o valor continua
    assert.equal(await ev("[...document.querySelectorAll('form[data-autosalvar] button')].some(b => b.textContent.trim() === 'Salvar')"), false, 'sem botao Salvar');
    await ev("(() => { const i = document.querySelector('form[data-autosalvar] [name=contato_nome]'); i.value = 'Contato salvo sozinho'; i.dispatchEvent(new Event('input', { bubbles: true })); })()");
    assert.ok(await esperar("document.querySelector('[data-salvo-status]').textContent.includes('Salvo')"), 'aparece Salvo');
    await ir(await ev('location.pathname'));
    assert.ok(await esperar("document.readyState === 'complete' && document.querySelector('[name=contato_nome]').value === 'Contato salvo sozinho'"), 'valor continua depois de recarregar');
    assert.equal(await ev("!!document.querySelector('.cliente-nome-linha .btn-lixeira')"), true, 'lixeira ao lado do nome');
    await tema('claro');
    await shot('10-cliente-ficha-claro.png');
    await tema('escuro');

    // ---- Historico e Importar
    await ir('/historico');
    assert.ok((await texto('.tabela')).includes(nome1), 'historico mostra a conclusao da Rotina');
    await ir('/importar');
    assert.ok((await texto('main')).includes('Importar do ClickUp'), 'tela de importacao');
    await shot('11-importar-escuro.png');

    // ---- Tema: escolha fica salva (parte do escuro: o navegador pode abrir claro, conforme o sistema)
    await ev("window.localStorage.setItem('rotina_tema', 'escuro')");
    await ir('/');
    assert.equal(await ev('document.documentElement.dataset.tema'), 'escuro', 'tema escolhido anteriormente e respeitado ao abrir');
    await clicar('.menu [data-theme-toggle]');
    assert.equal(await ev('document.documentElement.dataset.tema'), 'claro', 'trocou para o claro');
    await ir('/clientes');
    assert.equal(await ev('document.documentElement.dataset.tema'), 'claro', 'o tema claro foi lembrado');
    await clicar('.menu [data-theme-toggle]');
    assert.equal(await ev('document.documentElement.dataset.tema'), 'escuro');

    // ---- celular
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 2, mobile: true });
    await ir('/');
    assert.ok(await esperar("document.querySelector('#rotinaDias strong')"));
    assert.ok(await ev('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'sem rolagem horizontal no celular');
    await shot('12-rotina-celular.png');

    assert.equal((await fetch(BASE + '/api/tarefas')).status, 401, 'a API exige login');
    assert.deepEqual(erros, [], 'sem erros de JavaScript/console: ' + erros.join(' / '));
    console.log('PASS: Rotina no navegador (login, rotina, tarefas, quadro, automacoes, clientes, historico, importar, temas, celular)');
  } finally {
    try { ws && ws.close(); } catch {}
    edge.kill(); php.kill();
    await sleep(300);
    try { fs.rmSync(dados, { recursive: true, force: true }); } catch {}
  }
})().catch((e) => { console.error('FAIL:', e.message); process.exit(1); });
