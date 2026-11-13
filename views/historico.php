<?php /** @var array $registros @var array $clientes @var array $filtros */ ?>
<header class="pagina-topo">
  <div>
    <h1>Histórico</h1>
    <p class="muted">Tudo o que foi concluído, com quem fez e a nota do que foi feito.</p>
  </div>
</header>
<form method="get" action="/historico" class="filtros-barra">
  <label>De <input type="date" name="de" value="<?= e((string) ($filtros['de'] ?? '')) ?>"></label>
  <label>Até <input type="date" name="ate" value="<?= e((string) ($filtros['ate'] ?? '')) ?>"></label>
  <label>Cliente
    <select name="cliente_id">
      <option value="">Todos</option>
      <?php foreach ($clientes as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) ($filtros['cliente_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e((string) $c['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn btn-contorno" type="submit">Filtrar</button>
</form>

<?php if ($registros === []): ?>
  <p class="muted">Nada concluído ainda.</p>
<?php else: ?>
  <div class="cartao tabela-cartao">
    <div class="tabela-rolagem">
      <table class="tabela">
        <thead><tr><th>Concluída em</th><th>Tarefa</th><th>Cliente</th><th>Responsável</th><th>Nota</th></tr></thead>
        <tbody>
          <?php foreach ($registros as $x): ?>
            <tr>
              <td><?= e(date('d/m/Y H:i', (int) strtotime((string) $x['concluida_em']))) ?></td>
              <td><?= e((string) $x['titulo']) ?></td>
              <td><?= e((string) ($x['cliente_nome'] ?? '—')) ?></td>
              <td><?= e((string) ($x['responsavel_nome'] ?? '—')) ?></td>
              <td><?= e((string) $x['nota']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
