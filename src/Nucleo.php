<?php

declare(strict_types=1);

/** Monta os servicos ligados ao mesmo banco (uma unica instancia de cada). */
final class Nucleo
{
    public Auth $auth;
    public Clientes $clientes;
    public Listas $listas;
    public Tarefas $tarefas;
    public Automacoes $automacoes;
    public Rotina $rotina;
    public Importacao $importacao;

    private function __construct(public PDO $pdo)
    {
        $this->auth = new Auth($pdo);
        $this->clientes = new Clientes($pdo);
        $this->listas = new Listas($pdo);
        $this->tarefas = new Tarefas($pdo);
        $this->automacoes = new Automacoes($pdo, $this->tarefas, $this->clientes, $this->auth);
        $this->tarefas->definirAutomacoes($this->automacoes);
        $this->rotina = new Rotina($pdo, $this->tarefas, $this->automacoes);
        $this->importacao = new Importacao($pdo, $this->clientes);
    }

    public static function montar(PDO $pdo): self
    {
        return new self($pdo);
    }

    /** Usuario "Sistema": quem age quando uma automacao roda. */
    public static function sistema(): array
    {
        return ['id' => null, 'nome' => 'Sistema', 'papel' => 'admin', 'sistema' => true];
    }
}
