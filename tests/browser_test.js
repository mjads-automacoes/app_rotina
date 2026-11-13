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
    assert.equal(await ev("document.getElementById('rotinaGrade').children[0].id"), 'rotinaChecklist', 'checklist fixa a esquerda');
    assert.equal(await ev("(() => { const i = document.querySelector('.rotina-item'); return i.firstElementChild.className + '|' + i.lastElementChild.className; })()"), 'rotina-item-texto|rotina-check', 'caixa de marcacao a direita do texto');
    assert.equal(await ev("getComputedStyle(document.querySelector('#rotinaChecklist')).position"), 'sticky', 'checklist acompanha a rolagem');
    const nome1 = await texto('.rotina-foco-nome');
    const antes = await texto('#rotinaDias small');
    await shot('1-rotina-escuro.png');
    await ev("document.getElementById('rotinaNota').value = 'Nota enviada sem concluir'; document.getElementById('rotinaNota').dispatchEvent(new Event('input', { bubbles: true }))");
    await clicar('[data-acao="enviar-nota"]');
    assert.ok(await esperar("[...document.querySelectorAll('.nota-enviada')].some(x => x.textContent.includes('Nota enviada sem concluir'))"), 'nota enviada aparece sem concluir');
    assert.equal(await ev("document.getElementById('rotinaNota').value"), '', 'campo limpo apos enviar');
    await ev("document.getElementById('rotinaNota').value = 'Ajustei o orçamento'; document.getElementById('rotinaNota').dispatchEvent(new Event('input', { bubbles: true }))");
    assert.equal(await ev("document.querySelector('[data-acao=\"concluir\"]') === null"), true, 'Concluir so aparece depois de Iniciar');
    await clicar('[data-acao="abrir-cliente"]');
    assert.ok(await esperar("!!document.querySelector('.janela-quadro')"), 'ficha abre em janela');
    await sleep(900);
    await shot('1b-ficha-janela.png');
    await ev("document.querySelector('.janela-topo button').click()");
    assert.equal(await ev("document.querySelector('.janela-fundo') === null"), true, 'janela fecha');
    await clicar('[data-acao="iniciar"]');
    assert.ok(await esperar("!!document.querySelector('[data-acao=\"concluir\"]') && !document.querySelector('[data-acao=\"iniciar\"]')"), 'Iniciar vira Concluir');
    await clicar('[data-acao="concluir"]');
    assert.notEqual(await texto('#rotinaDias small'), antes, 'o progresso andou na hora');
    assert.notEqual(await texto('.rotina-foco-nome'), nome1, 'o proximo cliente entra em foco');
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
    await shot('9-cliente-ficha-escuro.png');
    // menu de selecao dos dias: abre, marca, atualiza o rotulo e fecha ao clicar fora
    await ev("document.querySelector('[data-multi] summary').click()");
    assert.ok(await esperar("document.querySelector('[data-multi]').open"), 'menu de dias abre');
    await ev("(() => { const i = document.querySelector('[data-multi] input[value=quarta]'); i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); })()");
    assert.ok(await esperar("document.querySelector('[data-multi-rotulo]').textContent.includes('Qua')"), 'rotulo mostra os dias marcados');
    await shot('9b-dias-menu-aberto.png');
    await ev("document.querySelector('h1').click()");
    assert.ok(await esperar("!document.querySelector('[data-multi]').open"), 'menu de dias fecha ao clicar fora');
    await tema('claro');
    await shot('10-cliente-ficha-claro.png');
    await tema('escuro');

    // ---- Historico e Importar
    await ir('/historico');
    assert.ok((await texto('.tabela')).includes('Ajustei o orçamento'), 'historico mostra a conclusao da Rotina com a nota');
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
