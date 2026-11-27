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
    await ev("(() => { const c = document.querySelector('form [name=email]'); c.focus(); c.dispatchEvent(new FocusEvent('focusin', { bubbles: true })); })()");
    assert.equal(await ev("!document.querySelector('.emoji-botao') || document.querySelector('.emoji-botao').hidden"), true, 'sem emoji na tela de login');
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
    // 6 pontinhos: arrastar o ultimo cliente da Tarde para a Manha e de volta (ordem e periodo gravados)
    assert.equal(await ev("[...document.querySelectorAll('#rotinaClientes .rotina-cliente')].every(b => b.firstElementChild.classList.contains('rotina-alca') && b.querySelectorAll('.rotina-alca i').length === 6)"), true, '6 pontinhos na frente de cada cliente');
    const arrastar = (de, para) => ev(`(async () => {
      const linha = [...document.querySelectorAll('#rotinaClientes [data-grupo-turno="${de}"] .rotina-cliente')].pop();
      const id = linha.dataset.cliente, g = document.querySelector('#rotinaClientes [data-grupo-turno="${para}"]');
      linha.querySelector('.rotina-alca').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
      const dt = new DataTransfer();
      linha.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
      g.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientY: g.getBoundingClientRect().bottom + 500 }));
      linha.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));
      for (let i = 0; i < 60; i++) { await new Promise(r => setTimeout(r, 150)); const r = await fetch('/api/rotina?data=' + rotina.dataset.hoje, { headers: { Accept: 'application/json' } }).then(x => x.json()); const t = r.semana[rotina.dataset.hoje].turnos['${para}'].clientes; if (t.length && t[t.length - 1].id == id) return true; }
      return false;
    })()`);
    if ((await n('#rotinaClientes [data-grupo-turno="tarde"] .rotina-cliente')) > 0) {
      assert.equal(await arrastar('tarde', 'manha'), true, 'arrastado da Tarde para o fim da Manha');
      assert.equal(await arrastar('manha', 'tarde'), true, 'e de volta para o fim da Tarde');
    }
    // formato: cliente em foco em cima, na largura toda; checklist e clientes lado a lado embaixo
    assert.equal(await ev("[...document.getElementById('rotinaGrade').children].map(c => c.id).join(',') + '|' + (rotinaClientes.getBoundingClientRect().left < rotinaFoco.getBoundingClientRect().left)"), 'rotinaChecklist,rotinaClientes,rotinaFoco|true', 'ordem: checklist em cima; clientes (esquerda) e foco (direita) embaixo');
    assert.equal(await ev("(() => { const f = rotinaFoco.getBoundingClientRect(), c = rotinaChecklist.getBoundingClientRect(), k = rotinaClientes.getBoundingClientRect(); return c.bottom <= k.top && Math.abs(f.top - k.top) < 2 && Math.abs(f.width - k.width) < 2 && Math.abs(f.height - k.height) < 2 && c.width > k.width * 1.8; })()"), true, 'checklist na largura toda; clientes e foco lado a lado, mesma largura e altura');
    assert.equal(await ev("(() => { const i = document.querySelector('#rotinaChecklist .rotina-item'); return [...i.children].map(c => c.className).join('|'); })()"), 'rotina-item-texto|rotina-mais-wrap|rotina-concluir', 'checklist: tres pontinhos antes do concluir');
    assert.ok((await texto('#rotinaDias small')).endsWith('concluídos'), 'barra do dia: N de M concluidos');
    // visual Premium (gamificado) nos dois temas, sem o seletor antigo
    assert.equal(await ev("document.documentElement.dataset.tema + '|' + getComputedStyle(document.querySelector('#rotinaDias .gamer-xp')).display + '|' + !!document.querySelector('#rotinaDias .gamer-anel') + '|' + !document.querySelector('[data-visual-toggle]')"), 'escuro|block|true|true', 'escuro e o visual Premium');
    // botao de tema roda: escuro -> neon (inspirado no GTA VI) -> claro -> escuro
    await clicar('.menu-acoes [data-theme-toggle]');
    assert.equal(await ev("document.documentElement.dataset.tema + '|' + getComputedStyle(document.body).backgroundImage.includes('gradient') + '|' + getComputedStyle(document.documentElement).getPropertyValue('--acento').trim()"), 'neon|true|#ff3ea5', 'tema Neon');
    await clicar('.menu-acoes [data-theme-toggle]');
    assert.equal(await ev("document.documentElement.dataset.tema + '|' + getComputedStyle(document.querySelector('#rotinaDias .gamer-xp')).display"), 'claro|block', 'tema claro tambem no visual Premium (com XP)');
    await clicar('.menu-acoes [data-theme-toggle]');
    assert.equal(await ev('document.documentElement.scrollHeight <= innerHeight + 1'), true, 'Rotina cabe na tela, sem barra de rolagem da pagina');
    // cabecalhos: tres pontinhos para editar o nome e mudar a cor
    await clicar('[data-cab="menu"][data-bloco="clientes"]');
    await clicar('[data-cab="editar"][data-bloco="clientes"]');
    await ev("(() => { const c = document.getElementById('rotinaCabNome'); c.value = 'Meus clientes'; c.dispatchEvent(new Event('input', { bubbles: true })); })()");
    await clicar('[data-cab="salvar-nome"]');
    await clicar('[data-cab="menu"][data-bloco="clientes"]');
    await clicar('[data-cab="cores"]');
    await clicar('[data-cab="cor"][data-cor="rosa"]');
    assert.ok(await esperar("(() => { const h = document.querySelector('#rotinaClientes .rotina-cabecalho'); return h.textContent.includes('Meus clientes') && rotinaClientes.classList.contains('cor-rosa') && [...rotinaClientes.querySelectorAll('.rotina-grupo-topo')].every(g => getComputedStyle(g).borderLeftColor === 'rgb(236, 72, 153)'); })()"), 'nome e cor mudam, e os cabecalhos de turno acompanham');
    await sleep(500);
    await ir('/');
    assert.ok(await esperar("(() => { const h = document.querySelector('#rotinaClientes .rotina-cabecalho'); return h && h.textContent.includes('Meus clientes') && rotinaClientes.classList.contains('cor-rosa'); })()"), 'cabecalho continua depois de recarregar');
    assert.equal(await ev("getComputedStyle(document.querySelector('#rotinaChecklist .rotina-cabecalho h3'), '::before').display"), 'none', 'sem a barrinha antes do titulo');
    await clicar('[data-cab="menu"][data-bloco="foco"]');
    await clicar('[data-cab="cor"][data-bloco="foco"][data-cor="laranja"]');
    assert.ok(await esperar("rotinaFoco.classList.contains('cor-laranja') && getComputedStyle(rotinaFoco.querySelector('.rotina-foco-tarefas h3')).borderLeftColor === 'rgb(249, 115, 22)'"), 'tres pontinhos do Em foco mudam a cor do bloco');
    assert.equal(await ev("(() => { const h = document.querySelector('#painelRotina h1').getBoundingClientRect(), d = rotinaDias.getBoundingClientRect(); return d.top < h.bottom && d.bottom > h.top && d.left > h.right; })()"), true, 'barra do dia na mesma linha do titulo Rotina');
    // foco em duas colunas: a direita, depois de uma linha, as tarefas do cliente com o status
    assert.ok(await esperar("document.querySelectorAll('#rotinaFoco .rotina-foco-tarefas .rotina-tarefa .pilula').length > 0 && rotinaFoco.querySelector('.rotina-foco-tarefas').getBoundingClientRect().top >= rotinaFoco.querySelector('.rotina-foco-principal').getBoundingClientRect().bottom - 1"), 'tarefas do cliente embaixo do foco, com status');
    const nome1 = await texto('.rotina-foco-nome');
    const antes = await texto('#rotinaDias small');
    const semNull = "!/\\bnull\\b|undefined/.test(document.getElementById('rotina').innerText)";
    assert.equal(await ev(semNull), true, 'a tela da Rotina nao mostra null nem undefined');
    await shot('1-rotina-escuro.png');
    // foco no formato fixo: selo, nome, status, nova tarefa (sem subtitulo)
    assert.equal(await ev("(() => { const f = rotinaFoco.querySelector('.rotina-foco-principal'); return [...f.children].map(c => c.className.split(' ')[0]).join(','); })()"), 'rotina-selo,rotina-foco-nome,rotina-foco-status,rotina-foco-nota,rotina-foco-acoes,rotina-mais-wrap', 'formato fixo do foco (com os tres pontinhos de cor)');
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
    assert.equal(await ev("[...document.querySelectorAll('#rotinaFoco .rotina-tarefa small')].every(x => x.textContent === rotina.dataset.hoje.slice(8, 10) + '/' + rotina.dataset.hoje.slice(5, 7))"), true, 'tarefas do cliente: so as de hoje');
    assert.equal(await ev("[...document.querySelectorAll('#rotinaFoco .rotina-tarefa-certo')].every(b => b.disabled)"), true, 'certo bloqueado antes de Iniciar');
    // botao de mais: detalhes da tarefa
    await clicar('#rotinaFoco .rotina-tarefa .rotina-tarefa-mais');
    assert.ok(await esperar("!!document.getElementById('rotinaDetalhe')"), 'mais abre o pop-up de detalhes');
    await ev("(() => { const t = document.getElementById('rotinaDetalhe'); t.value = 'Conferir verba da campanha'; t.dispatchEvent(new Event('input', { bubbles: true })); })()");
    assert.equal(await ev("(() => { const r = document.querySelector('.detalhe-caixa').getBoundingClientRect(); return Math.abs((r.left + r.right) / 2 - innerWidth / 2) < 3 && !document.querySelector('.detalhe-caixa button'); })()"), true, 'detalhes em pop-up no meio da tela, sem botoes');
    await clicar('.detalhe-fundo');
    assert.ok(await esperar("(document.querySelector('#rotinaFoco .rotina-tarefa-detalhe') || {}).textContent === 'Conferir verba da campanha'"), 'clicar fora salva e o detalhe aparece embaixo da tarefa');
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
    assert.ok(await esperar("!document.querySelector('[data-acao=\"concluir\"]') && !document.querySelector('[data-acao=\"iniciar\"]')"), 'depois de Iniciar nao ha botao Concluir (conclui pelo certo)');
    assert.ok(await esperar("[...document.querySelectorAll('#rotinaFoco .rotina-tarefa-certo')].length > 1 && [...document.querySelectorAll('#rotinaFoco .rotina-tarefa-certo')].every(b => !b.disabled)"), 'depois de Iniciar o certo fica liberado');
    // conclui a primeira: a segunda passa para Em andamento e o cliente continua em foco
    await clicar('#rotinaFoco .rotina-tarefa.is-foco .rotina-tarefa-certo');
    assert.ok(await esperar("(() => { const lis = [...document.querySelectorAll('#rotinaFoco .rotina-tarefa')]; return lis[0] && lis[0].textContent.includes('Concluído') && lis[1].textContent.includes('Em andamento'); })()"), 'primeira concluida, a segunda em andamento');
    assert.equal(await texto('.rotina-foco-nome'), nome1, 'cliente segue em foco ate terminar todas');
    assert.ok((await texto('#rotinaFoco .rotina-foco-status')).includes('Em andamento'), 'cliente continua Em andamento');
    for (let i = 0; i < 12 && (await texto('.rotina-foco-nome')) === nome1; i++) {
      await ev("(() => { const b = document.querySelector('#rotinaFoco .rotina-tarefa-certo:not(:disabled)'); if (b) b.click(); })()");
      await sleep(500);
    }
    assert.notEqual(await texto('#rotinaDias small'), antes, 'o progresso andou na hora');
    assert.notEqual(await texto('.rotina-foco-nome'), nome1, 'o proximo cliente entra em foco');
    assert.ok(await esperar("[...document.querySelectorAll('#rotinaClientes .rotina-cliente.is-concluido')].some(b => b.querySelector('.rotina-cliente-status').textContent === 'OK' && /⏱/.test(b.textContent))"), 'cliente concluido: verde, OK e tempo de execucao');
    assert.equal(await ev(semNull), true, 'sem null depois de concluir');
    await sleep(700);
    // checklist um por vez: o item da vez + a previa apagada do seguinte; concluir esconde o feito e traz o proximo
    const daVez = await texto('#rotinaChecklist .check-da-vez .rotina-item-texto');
    const previa = await texto('#rotinaChecklist .check-previa .rotina-item-texto');
    assert.ok(daVez !== '' && previa !== '' && await ev("getComputedStyle(document.querySelector('#rotinaChecklist .check-previa')).opacity < 0.6"), 'item da vez e previa apagada do seguinte');
    await clicar('#rotinaChecklist .check-da-vez .rotina-concluir');
    assert.ok(await esperar(`document.querySelector('#rotinaChecklist .check-da-vez .rotina-item-texto').textContent === ${JSON.stringify(previa)} && document.querySelector('#rotinaChecklist .contagem').textContent.startsWith('1 de')`), 'concluir esconde o feito e passa para o proximo');
    await sleep(700);
    await ir('/');
    assert.ok(await esperar("document.querySelector('#rotinaDias strong') && document.querySelector('#rotinaChecklist .contagem')"));
    assert.ok((await texto('#rotinaChecklist .contagem')).startsWith('1 de'), 'o check sobrevive ao recarregar');
    // lapis: renomeia o item
    await clicar('#rotinaChecklist .rotina-item .rotina-mais');
    assert.equal(await ev("[...document.querySelectorAll('#rotinaChecklist .rotina-item .rotina-menu-op')].map(b => b.textContent).join(',')"), 'Editar,Excluir', 'tres pontinhos: Editar e Excluir');
    assert.equal(await ev("(() => { const [a, b] = document.querySelectorAll('#rotinaChecklist .rotina-item .rotina-menu-op'); return b.getBoundingClientRect().top >= a.getBoundingClientRect().bottom - 1; })()"), true, 'menu em lista: Excluir abaixo de Editar');
    await clicar('#rotinaChecklist [data-check="editar"]');
    assert.ok(await esperar("!!document.getElementById('rotinaEditar')"), 'tres pontinhos abrem o campo do nome');
    await ev("(() => { const c = document.getElementById('rotinaEditar'); c.value = 'Item renomeado'; c.dispatchEvent(new Event('input', { bubbles: true })); })()");
    await clicar('[data-check="salvar-nome"]');
    assert.ok(await esperar("document.querySelector('#rotinaChecklist .rotina-item-texto').textContent === 'Item renomeado'"), 'nome trocado na hora');
    // checklist aparece aos poucos: "Ver todos" mostra a lista inteira
    assert.ok(await ev("!!document.querySelector('#rotinaChecklist .rotina-item.is-proximo') && document.querySelector('[data-check=ver-todos]').textContent === 'Expandir'"), 'checklist mostra o proximo e o botao Expandir');
    await clicar('[data-check="ver-todos"]');
    // Add +: novo item no checklist
    const itensAntes = await n('#rotinaChecklist .rotina-item');
    await clicar('[data-check="abrir-novo"]');
    await ev("(() => { const c = document.getElementById('rotinaAddTexto'); c.value = 'Item novo do teste'; c.dispatchEvent(new Event('input', { bubbles: true })); document.getElementById('rotinaAddTurno').value = 'fechamento'; })()");
    await clicar('[data-check="salvar-novo"]');
    assert.ok(await esperar(`document.querySelectorAll('#rotinaChecklist .rotina-item').length > ${itensAntes} && document.getElementById('rotinaChecklist').textContent.includes('Item novo do teste')`), 'Add + cria item no checklist');
    assert.ok(await ev("[...document.querySelectorAll('#rotinaChecklist .rotina-subtitulo')].some(x => x.textContent === 'Fechamento')"), 'checklist mostra o Fechamento');
    await sleep(700);
    await ir('/');
    await esperar("!!document.querySelector('[data-check=ver-todos]')");
    await clicar('[data-check="ver-todos"]');
    assert.ok(await esperar("document.getElementById('rotinaChecklist').textContent.includes('Item renomeado') && document.getElementById('rotinaChecklist').textContent.includes('Item novo do teste')"), 'nome e item novo continuam depois de recarregar');
    // Excluir pelo menu: pop-up de confirmacao proprio, no meio da tela
    const itensAntesExcluir = await n('#rotinaChecklist .rotina-item');
    await ev("[...document.querySelectorAll('#rotinaChecklist .rotina-item')].find(i => i.textContent.includes('Item novo do teste')).querySelector('.rotina-mais').click()");
    await clicar('#rotinaChecklist [data-check="excluir"]');
    assert.ok(await esperar("!!document.querySelector('.confirmar-caixa')"), 'pop-up de confirmacao abre');
    assert.equal(await ev("(() => { const r = document.querySelector('.confirmar-caixa').getBoundingClientRect(); return Math.abs((r.left + r.right) / 2 - innerWidth / 2) < 3 && Math.abs((r.top + r.bottom) / 2 - innerHeight / 2) < 3; })()"), true, 'pop-up no meio da tela');
    await clicar('[data-confirmar-ok]');
    assert.ok(await esperar(`document.querySelectorAll('#rotinaChecklist .rotina-item').length === ${itensAntesExcluir} - 1 && !document.getElementById('rotinaChecklist').textContent.includes('Item novo do teste')`), 'Excluir tira o item');
    await sleep(600);
    await ir('/');
    assert.ok(await esperar("!!document.querySelector('#rotinaChecklist .rotina-item') && !document.getElementById('rotinaChecklist').textContent.includes('Item novo do teste')"), 'item excluido nao volta ao recarregar');
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
    // kanban: 4 colunas, sem as iniciais; arrastar muda a situacao; opcao de Lista
    assert.equal(await ev("[...document.querySelectorAll('.kanban-coluna')].map(c => c.dataset.situacao).join(',')"), 'novo,ativo,alerta,churn', 'quadro com Novo, Ativo, Alerta e Churn');
    assert.equal(await n('.cliente-card .avatar'), 0, 'sem os icones de iniciais');
    const idMover = await ev("document.querySelector('.kanban-ativo .cliente-card').dataset.id");
    await ev(`window.Rotina.moverCliente('${idMover}', 'churn')`);
    assert.ok(await esperar(`!!document.querySelector('.kanban-churn .cliente-card[data-id="${idMover}"]') && document.querySelector('.kanban-churn [data-qtd]').textContent === '1'`), 'cliente vai para Churn');
    await ir('/clientes');
    assert.ok(await esperar(`!!document.querySelector('.kanban-churn .cliente-card[data-id="${idMover}"]')`), 'Churn continua depois de recarregar');
    await clicar('[data-vista-btn="lista"]');
    assert.equal(await ev(`document.getElementById('clientesQuadro').hidden && !document.getElementById('clientesTabela').hidden && document.querySelector('#clientesTabela tr[data-id="${idMover}"] [data-selo]').textContent + '|' + document.querySelector('#clientesTabela tr[data-id="${idMover}"]').closest('tbody').dataset.situacao + '|' + [...document.querySelectorAll('#clientesTabela .lista-grupo')].map(g => g.dataset.situacao).join(',')`), 'Churn|churn|novo,ativo,alerta,churn', 'Lista separada por situacao');
    await clicar('[data-vista-btn="quadro"]');
    await ev(`window.Rotina.moverCliente('${idMover}', 'ativo')`);
    await sleep(400);
    await shot('8-clientes-escuro.png');
    await ev("document.querySelector('.kanban-ativo .cliente-card').click()");
    assert.ok(await esperar("location.pathname.startsWith('/clientes/') && document.readyState === 'complete' && !!document.querySelector('.nota-form textarea')"), 'ficha do cliente');
    await ev("(() => { const t = document.querySelector('.nota-form textarea'); t.value = 'Nota criada no teste'; t.form.requestSubmit(); })()");
    assert.ok(await esperar("document.body.textContent.includes('Nota criada no teste')"), 'nota salva');
    assert.equal(await ev("(() => { const l = document.querySelector('[data-notas-lista]'), cs = getComputedStyle(l); return cs.overflowY === 'auto' && cs.scrollbarWidth === 'none' && document.querySelector('.coluna').getBoundingClientRect().bottom <= Math.max(document.querySelector('.ficha-grade > .cartao').getBoundingClientRect().bottom, document.querySelector('.coluna').getBoundingClientRect().top + 9999) ; })()"), true, 'notas rolam por dentro, sem barra');
    // editar e excluir a propria nota (lapis e lixeira)
    await ev("[...document.querySelectorAll('[data-nota]')].find(n => n.textContent.includes('Nota criada no teste')).querySelector('[data-nota-editar]').click()");
    await ev("(() => { const f = [...document.querySelectorAll('[data-nota-form]')].find(x => !x.hidden); f.querySelector('textarea').value = 'Nota editada no teste'; f.requestSubmit(); })()");
    assert.ok(await esperar("document.readyState === 'complete' && document.body.textContent.includes('Nota editada no teste') && !!document.querySelector('[data-nota]')"), 'nota editada');
    await ev("[...document.querySelectorAll('[data-nota]')].find(n => n.textContent.includes('Nota editada no teste')).querySelector('.nota-btn-excluir').click()");
    assert.ok(await esperar("!!document.querySelector('[data-confirmar-ok]')"), 'confirma antes de excluir a nota');
    await clicar('[data-confirmar-ok]');
    assert.ok(await esperar("document.readyState === 'complete' && !document.body.textContent.includes('Nota editada no teste') && !!document.querySelector('.nota-form')"), 'nota excluida');
    // calendario proprio do prazo: dias passados bloqueados, escolher preenche o campo
    await clicar('[data-cal-abrir]');
    assert.ok(await esperar("!!document.querySelector('.cal-painel')"), 'calendario abre');
    assert.equal(await ev("(() => { const min = document.querySelector('[data-calendario]').dataset.min; return [...document.querySelectorAll('.cal-dia')].every(b => b.disabled === (b.dataset.dia < min)); })()"), true, 'dias antes de hoje bloqueados');
    await ev("[...document.querySelectorAll('.cal-atalho')].find(b => b.textContent === 'Amanhã').click()");
    assert.equal(await ev("(() => { const v = document.querySelector('[data-calendario] input[name=prazo]').value; const t = document.querySelector('[data-cal-texto]').textContent; return /^\\d{4}-\\d{2}-\\d{2}$/.test(v) && t === v.slice(8, 10) + '/' + v.slice(5, 7) + '/' + v.slice(0, 4) && !document.querySelector('.cal-painel'); })()"), true, 'Amanha preenche o prazo e fecha');
    // emojis: ao focar um campo de texto aparece o 😊; ele abre a lista e o emoji entra no cursor
    await ev("(() => { const c = document.querySelector('.nota-form textarea'); c.value = 'Oi '; c.focus(); c.setSelectionRange(3, 3); c.dispatchEvent(new FocusEvent('focusin', { bubbles: true })); })()");
    assert.ok(await esperar("(() => { const b = document.querySelector('.emoji-botao'); return b && !b.hidden; })()"), 'botao de emoji aparece no campo');
    await clicar('.emoji-botao');
    assert.ok(await esperar("document.querySelectorAll('.emoji-painel .emoji-item').length > 20"), 'lista de emojis abre');
    assert.equal(await ev("(() => { const g = document.querySelector('.emoji-grade'); return g.scrollWidth <= g.clientWidth && g.querySelectorAll('.emoji-grupo').length === 6; })()"), true, 'todos os emojis numa lista, sem rolagem para o lado');
    await ev("document.querySelector('.emoji-painel .emoji-item').click()");
    assert.equal(await ev("document.querySelector('.nota-form textarea').value"), 'Oi 😀', 'emoji entra no campo');
    await ev("document.querySelector('h1').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))");
    assert.ok(await esperar("!document.querySelector('.emoji-painel') && document.querySelector('.emoji-botao').hidden"), 'clicar fora fecha os emojis');
    // CNPJ | Cidade | Estado no lugar do Site do Cliente; o CNPJ se formata enquanto digita
    assert.equal(await ev("!document.querySelector('form[data-autosalvar] [name=link]') && !!document.querySelector('[name=cnpj]') && !!document.querySelector('[name=cidade]') && document.querySelectorAll('select[name=estado] option').length === 28"), true, 'CNPJ, Cidade e Estado no lugar do site');
    assert.equal(await ev("(() => { const c = document.querySelector('[name=cnpj]'); c.value = '12345678000199'; c.dispatchEvent(new Event('input', { bubbles: true })); return c.value; })()"), '12.345.678/0001-99', 'CNPJ formatado');
    assert.equal(await ev("(() => { const c = document.querySelector('[name=cidade]'); c.focus(); c.dispatchEvent(new FocusEvent('focusin', { bubbles: true })); const b = document.querySelector('.emoji-botao'); return !b || b.hidden; })()"), true, 'sem emoji nos campos de empresa e contato');
    // Entregavel: etiqueta colorida (como no ClickUp), ao lado das plataformas, separada por uma linha; o admin edita a lista
    await clicar('[data-entregavel-abrir]');
    assert.ok(await esperar("document.querySelectorAll('.etiquetas-painel .etiqueta').length === 12"), 'lista de entregaveis abre com as etiquetas');
    await clicar('.etiquetas-painel [data-escolher="trafego"]');
    assert.equal(await ev("document.querySelector('[data-entregavel-atual] .etiqueta').textContent + '|' + document.querySelector('input[name=entregaveis]').value + '|' + !document.querySelector('.etiquetas-painel')"), 'Tráfego|trafego|true', 'escolher uma etiqueta');
    assert.equal(await ev("(() => { const c = document.querySelector('.entregavel'); c.style.width = '200px'; document.querySelector('input[name=entregaveis]').value = 'trafego_video_social'; window.Rotina.entregaveis.escolher('trafego_video_social'); const b = document.querySelector('.entregavel-botao').getBoundingClientRect(), e = document.querySelector('.entregavel-botao .etiqueta').getBoundingClientRect(); c.style.width = ''; return b.right - e.right >= 36; })()"), true, 'etiqueta longa nao passa por cima da seta');
    await clicar('[data-entregavel-abrir]');
    await clicar('.etiquetas-painel [data-editar-etiqueta="bot_disparos"]');
    await ev("(() => { const i = document.querySelector('.etiqueta-edicao .etiqueta-nome'); i.value = 'Bot de Disparos'; })()");
    await ev("document.querySelector('.etiqueta-edicao .btn-primario').click()");
    assert.ok(await esperar("[...document.querySelectorAll('.etiquetas-painel .etiqueta')].some(x => x.textContent === 'Bot de Disparos')"), 'admin renomeia a etiqueta');
    assert.equal(await ev("(() => { const p = document.querySelector('.plataformas').getBoundingClientRect(), e = document.querySelector('.plat-entregas-dir').getBoundingClientRect(); return e.left > p.right && getComputedStyle(document.querySelector('.plat-entregas-dir')).borderLeftStyle === 'solid'; })()"), true, 'entregaveis a direita, com linha divisoria');
    await ev("document.querySelector('h1').click()");
    // menus de selecao proprios: a lista abre no visual do sistema e a escolha vai para o campo original
    await clicar('select[name=status] + .selecao-botao');
    assert.ok(await esperar("document.querySelectorAll('.selecao-lista .selecao-op').length === 4"), 'lista propria do menu abre');
    await ev("[...document.querySelectorAll('.selecao-lista .selecao-op')].find(b => b.textContent === 'Alerta').click()");
    assert.equal(await ev("document.querySelector('select[name=status]').value + '|' + document.querySelector('select[name=status] + .selecao-botao').textContent + '|' + !document.querySelector('.selecao-lista')"), 'alerta|Alerta|true', 'escolha vai para o campo e fecha a lista');
    assert.equal(await ev("[...document.querySelectorAll('.selecao-original')].every(s => getComputedStyle(s).display === 'none')"), true, 'menu antigo do navegador nunca aparece');
    // barra das observacoes: B, I, U e # aplicam a marcacao no texto selecionado
    assert.equal(await ev("(() => { const e = document.querySelector('[data-obs-editar]'); if (e) e.click(); const t = document.querySelector('textarea[name=observacoes]'); const fmt = (f, a, b) => { t.setSelectionRange(a, b); document.querySelector('[data-formato=' + f + ']').click(); }; t.value = 'abc def'; fmt('negrito', 0, 3); fmt('sublinhado', 2, 5); t.setSelectionRange(0, 0); document.querySelector('[data-formato=titulo]').click(); return t.value; })()"), '# **++abc++** def', 'barra: negrito, sublinhado e titulo');
    assert.equal(await ev("[...document.querySelectorAll('[data-obs-barra] .obs-fmt')].map(b => b.textContent).join('')"), '#BIU', 'botoes #, B, I, U');
    assert.equal(await ev("[...document.querySelectorAll('.lista-simples .tarefa-item')].every(li => { const t = li.querySelector('.tarefa-abrir').getBoundingClientRect(), x = li.querySelector('.tarefa-excluir').getBoundingClientRect(); return x.top < t.bottom && x.left >= t.right - 1; })"), true, 'X sempre na mesma linha da tarefa');
    // clicar numa tarefa da ficha mostra os detalhes dela
    await clicar('.lista-simples [data-tarefa-abrir]');
    assert.equal(await ev("(() => { const d = document.querySelector('.lista-simples [data-tarefa-detalhes]'); return !d.hidden && d.textContent.includes('Status') && d.textContent.includes('Prazo'); })()"), true, 'detalhes da tarefa aparecem ao clicar');
    await clicar('.lista-simples [data-tarefa-abrir]');
    // tarefas do cliente: adicionar pela ficha e excluir com confirmacao
    await ev("window.__semRecarregar = 1");
    await ev("(() => { const f = document.querySelector('.tarefa-nova'); f.querySelector('[name=titulo]').value = 'Tarefa criada na ficha'; f.querySelector('[name=prazo]').value = document.querySelector('[data-calendario]').dataset.min; f.requestSubmit(); })()");
    assert.ok(await esperar("[...document.querySelectorAll('.lista-simples li')].some(li => li.textContent.includes('Tarefa criada na ficha') && /\\d{2}\\/\\d{2}/.test(li.textContent))"), 'tarefa adicionada na ficha');
    assert.equal(await ev("window.__semRecarregar === 1"), true, 'ao vivo: a pagina nao recarregou');
    await ev("[...document.querySelectorAll('.lista-simples li')].find(li => li.textContent.includes('Tarefa criada na ficha')).querySelector('.btn-excluir-tarefa').click()");
    assert.ok(await esperar("!!document.querySelector('[data-confirmar-ok]')"), 'confirmacao da ficha abre');
    await clicar('[data-confirmar-ok]');
    await sleep(400);
    assert.ok(await esperar("document.readyState === 'complete' && !document.body.textContent.includes('Tarefa criada na ficha') && document.body.textContent.includes('Tarefa excluída')"), 'tarefa excluida na ficha');
    assert.ok(await esperar("!!document.querySelector('#notificacoes .notificacao')"), 'aviso do servidor vira notificacao');
    assert.equal(await ev("document.querySelector('.conteudo .aviso') === null"), true, 'sem faixa de aviso parada no topo');
    await sleep(5600);
    assert.equal(await ev("document.querySelectorAll('#notificacoes .notificacao').length"), 0, 'notificacao some em 5 segundos');
    await shot('9-cliente-ficha-escuro.png');
    // menu de selecao dos dias: abre, marca, atualiza o rotulo e fecha ao clicar fora
    await ev("document.querySelector('.campo-multi [data-multi] summary').click()");
    assert.ok(await esperar("document.querySelector('.campo-multi [data-multi]').open"), 'menu de dias abre');
    await ev("(() => { const i = document.querySelector('.campo-multi [data-multi] input[value=quarta]'); i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); })()");
    assert.ok(await esperar("document.querySelector('.campo-multi [data-multi-rotulo]').textContent.includes('Qua')"), 'rotulo mostra os dias marcados');
    await shot('9b-dias-menu-aberto.png');
    await ev("document.querySelector('h1').click()");
    assert.ok(await esperar("!document.querySelector('.campo-multi [data-multi]').open"), 'menu de dias fecha ao clicar fora');
    // salva sozinha: sem botao Salvar e sem o aviso "Salva sozinha"; muda um campo, recarrega e o valor continua
    assert.equal(await ev("[...document.querySelectorAll('form[data-autosalvar] button')].some(b => b.textContent.trim() === 'Salvar')"), false, 'sem botao Salvar');
    await ev("(() => { const i = document.querySelector('form[data-autosalvar] [name=contato_nome]'); i.value = 'Contato salvo sozinho'; i.dispatchEvent(new Event('input', { bubbles: true })); })()");
    assert.equal(await ev("!document.querySelector('[data-salvo-status]')"), true, 'sem o aviso Salva sozinha');
    await sleep(1500);
    await ir(await ev('location.pathname'));
    assert.ok(await esperar("document.readyState === 'complete' && document.querySelector('[name=contato_nome]').value === 'Contato salvo sozinho'"), 'valor continua depois de recarregar');
    assert.equal(await ev("!!document.querySelector('.cliente-nome-linha .btn-lixeira')"), true, 'lixeira ao lado do nome');
    await tema('claro');
    await shot('10-cliente-ficha-claro.png');
    await tema('escuro');

    // ---- Agentes: formulario montado pela especificacao; envio, detalhe e comentario
    await ir('/agentes');
    assert.equal(await ev("[...document.querySelectorAll('.agente-card')].map(c => c.querySelector('.agente-funcao').textContent).join(',') + '|' + document.querySelectorAll('.agente-nome').length"), 'Gestor de Tráfego,Criativos,Dev Sites,Social Media|0', 'agentes como personagens, sem nome proprio');
    await ev("document.querySelector('.agente-card[data-agente=criativos] .agente-pedir').click()");
    assert.ok(await esperar("location.pathname === '/agentes/novo' && document.getElementById('pedAgente') && document.getElementById('pedAgente').value === 'criativos'"), 'Fazer pedido (Criativos) abre o formulario ja com Criativos');
    await ir('/agentes/novo');
    assert.ok(await esperar("!!document.getElementById('pedAgente')"), 'formulario de pedido carrega');
    await ev("(() => { const s = document.getElementById('pedAgente'); s.value = 'gestor_trafego'; s.dispatchEvent(new Event('change', { bubbles: true })); })()");
    await ev("(() => { const s = document.getElementById('pedTipo'); s.value = 'outro'; s.dispatchEvent(new Event('change', { bubbles: true })); })()");
    assert.ok(await esperar("document.querySelectorAll('.pedido-campo').length > 10"), 'campos do tipo aparecem');
    await ev("document.querySelector('.pedido-form button[type=submit]').click()");
    assert.ok(await esperar("!!document.querySelector('.pedido-campo.is-erro')"), 'falta campo obrigatorio: marca o campo');
    const idPedido = await ev(`(async () => { const d = await window.Rotina.chamar('GET', '/api/agentes/esquema'); const campos = {}; d.esquema.agents.gestor_trafego.forms.outro.fields.forEach(f => { if (f.required && !f.required_if) campos[f.id] = f.allow_none_option ? { nenhum: f.allow_none_option } : (f.options ? (typeof f.options[0] === 'object' ? f.options[0].value : f.options[0]) : 'teste'); }); const r = await window.Rotina.chamar('POST', '/api/pedidos', { agente: 'gestor_trafego', tipo: 'outro', cliente_id: d.clientes[0].id, prioridade: 'normal', prazo: d.hoje, campos }); return r.id; })()`);
    assert.ok(idPedido > 0, 'pedido criado');
    await ir('/agentes/' + idPedido);
    assert.ok((await texto('main')).includes('Pedido enviado') && (await texto('.pedido-status')).includes('A fazer'), 'detalhe do pedido com a linha do tempo');
    await ev("(() => { const f = document.querySelector('.pedido-comentar'); f.querySelector('textarea').value = 'Comentario de teste'; f.requestSubmit(); })()");
    assert.ok(await esperar("document.readyState === 'complete' && document.querySelector('.pedido-linha').textContent.includes('Comentario de teste')"), 'comentario no pedido');
    await ir('/agentes');
    assert.ok((await n('.pedido-cartao')) >= 1, 'pedido aparece na lista');
    // ---- Integracoes
    await ir('/integracoes');
    assert.ok((await texto('main')).includes('Integrações') && (await texto('main')).includes('ClickUp'), 'tela de integracoes');
    await shot('11-importar-escuro.png');

    // ---- Tema: escolha fica salva (parte do escuro: o navegador pode abrir claro, conforme o sistema)
    await ev("window.localStorage.setItem('rotina_tema', 'escuro')");
    await ir('/');
    assert.equal(await ev('document.documentElement.dataset.tema'), 'escuro', 'tema escolhido anteriormente e respeitado ao abrir');
    await clicar('.menu [data-theme-toggle]');
    assert.equal(await ev('document.documentElement.dataset.tema'), 'neon', 'trocou para o neon');
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
    console.log('PASS: Rotina no navegador (login, rotina, tarefas, quadro, automacoes, clientes, integracoes, temas, celular)');
  } finally {
    try { ws && ws.close(); } catch {}
    edge.kill(); php.kill();
    await sleep(300);
    try { fs.rmSync(dados, { recursive: true, force: true }); } catch {}
  }
})().catch((e) => { console.error('FAIL:', e.message); process.exit(1); });
