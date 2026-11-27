# Aba Agentes · Formulários de solicitação

27/11/2026 · Mateus Augusto

## Visão geral

Na aba **Agentes** da Rotina, cada pedido ao escritório vira um formulário com campos fixos, no lugar da mensagem "Diretor, pedido: …" colada no WhatsApp. São 4 agentes e 10 tipos de pedido: 8 vindos dos modelos atuais e 2 sugeridos (Campanha Google e Aprovação de OTM), que hoje já existem como comando no WhatsApp.

| Agente | Tipos de pedido | Origem |
| --- | --- | --- |
| Gestor de Tráfego | Campanha no Meta (rascunho) · Análise da conta · Campanha no Google (rascunho) · Aprovação de OTM · Outro | modelos atuais + 2 sugeridos |
| Criativos | Vídeo · Imagem | modelo de vídeo + versão imagem |
| Dev Sites | Landing page nova · Ajuste em landing page | modelos atuais |
| Social Media | Post de social | modelo atual |

O que muda em relação ao WhatsApp:

- **Cliente vem do cadastro.** O campo Cliente é uma busca na base da Rotina; ao escolher, a ficha abre ao lado e os campos que a ficha já tem (ID da conta, WhatsApp, domínio, cores) chegam preenchidos com o selo "da ficha". Some o "ver ficha" digitado.
- **Anexo vai junto.** Upload direto no formulário (fotos, vídeos, logo, PDF, áudio), com o link do Drive como alternativa. A regra "anexo no grupo não chega" deixa de existir.
- **"Não se aplica" vira opção.** Campos opcionais têm um botão "não se aplica" ou "sem oferta" em vez de texto livre, para o agente saber que a resposta foi intencional.
- **O agente não adivinha.** Validação acontece antes do envio; o que faltar volta como pergunta no próprio pedido (status Falta informação), não no grupo.

**Regras de ouro que o formulário reforça** (aparecem como aviso fixo no topo de cada formulário do setor):

1. Nada vai para o cliente nem é publicado sem passar pela equipe. Campanhas são criadas pausadas ou em rascunho; quem ativa é o gestor.
2. Oferta, preço, prazo e depoimento precisam ser reais. Se faltar, o pedido volta com a pergunta.
3. Imagem por IA só quando o campo Imagens marcar isso, e nunca para inventar fachada, equipe, dono ou trabalho real do cliente.
4. Sem promessa de resultado garantido, sem antes e depois em saúde ou estética, sem citar características pessoais ("Você está acima do peso?").
5. Um pedido por formulário, de um cliente só.

## Estrutura comum a todo pedido

Todo formulário começa com o mesmo cabeçalho, e todo pedido passa pelos mesmos status. Isso permite uma lista única na aba Agentes, filtrada por setor, cliente, status ou responsável.

**Cabeçalho (todos os formulários)**

| Campo | Tipo | Obrigatório | Regra / ajuda |
| --- | --- | --- | --- |
| Agente | seleção (Gestor de Tráfego, Criativos, Dev Sites, Social Media) | sim | Define o setor; substitui "cada grupo só trata do próprio assunto". |
| Tipo de pedido | seleção (depende do agente) | sim | Troca os campos do formulário. |
| Cliente | busca no cadastro | sim | Um cliente só. Ao escolher, abre "ver ficha" ao lado e pré-preenche campos. Cliente sem ficha: bloqueia e mostra "cadastre o cliente primeiro". |
| Prioridade | seleção: urgente / alta / normal / baixa | sim | Padrão: normal. Urgente pede um motivo curto. |
| Prazo | data | sim | Não aceita data passada. Abaixo do prazo mínimo do tipo, mostra aviso (não bloqueia). |
| Solicitante | automático (usuário logado) | sim | Não editável. É quem recebe as respostas. |
| Responsável pela aprovação | pessoa da equipe | sim | Padrão por setor: gestor do cliente (Tráfego), solicitante (Criativos e Sites), Pablo (Social). |
| Observações | texto longo | não | Qualquer coisa que não coube nos campos. |
| Anexos gerais | upload múltiplo ou link do Drive | não | Até 50 MB por arquivo, como hoje. Áudio é transcrito e anexado ao pedido. |

**Prazo mínimo por tipo** (regra atual do escritório, usada só para o aviso): imagem, post e análise no mesmo dia se o pedido entrar até 16h; vídeo, landing page e campanha até o fim do próximo dia útil.

**Status do pedido**

| Status | Quem move | O que significa |
| --- | --- | --- |
| A fazer | sistema, ao enviar | Pedido validado, na fila do agente. Substitui o "📝 Anotado". |
| Fazendo | agente | Agente começou (meta: até 15 min, seg a sáb 8h–20h44). |
| Falta informação | agente | Algo obrigatório está faltando ou não é real. A pergunta aparece no pedido; o solicitante responde e o pedido volta para Fazendo. |
| Para aprovação | agente | Entrega pronta, aguardando o responsável. Alerta se ficar mais de 24 h parado. |
| Ajuste | aprovador | Aprovador pediu mudança com comentário. Volta para o agente. |
| Concluído | aprovador | Aprovado. No Social, é este status que libera a publicação no horário marcado. |
| Cancelado | solicitante ou aprovador | Pedido desistido, com motivo. |

O status "Falta informação" é o equivalente ao "rever task" das Demandas do Guilherme e ao "o pedido volta com a pergunta" dos grupos. Mantive separado de Ajuste porque um é pergunta antes da entrega e o outro é correção depois dela.

## Agente Gestor de Tráfego

Campanhas, análises de conta e otimizações. Aviso fixo no topo: "A campanha fica em rascunho/pausada. Quem revisa e ativa é o gestor. O agente nunca mexe em orçamento de campanha ativa, lance ou pagamento."

### Campanha no Meta (rascunho)

| Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- |
| ID da conta de anúncio | texto numérico | sim | Vem da ficha. Se a ficha não tem, o campo abre para digitar e a Rotina oferece salvar na ficha. |
| Página do Facebook e Instagram | texto (ou seleção, se a ficha tiver) | sim | Vem da ficha. |
| Objetivo | seleção: conversas no WhatsApp / leads / acessos à landing page | sim | Define o tipo do campo Destino. |
| Destino do anúncio | telefone com DDD ou URL | sim | WhatsApp: valida DDD + número (vem da ficha). Landing page: URL começando com https. |
| Orçamento diário combinado | moeda (R$) | sim | Só para montar o rascunho. Valor acima do combinado na ficha mostra aviso. |
| Produto ou serviço | texto curto | sim | Um foco por campanha. |
| Oferta real e validade | oferta + data, ou botão "sem oferta" | sim | Se tiver oferta, a data de validade é obrigatória e não pode estar vencida. |
| Cidade e raio | cidade (busca) + raio em km | sim | Ex.: Araçatuba + 15 km. Padrão vem da ficha. |
| Público | texto: idade, perfil e interesses | sim | |
| Criativos aprovados | escolher pedido concluído de Criativos, upload ou link do Drive | sim | Lista os criativos com status Concluído deste cliente. Criativo não aprovado não aparece. |
| Textos aprovados | texto, link, ou "criar com base na ficha" | sim | |
| Data desejada para publicar | data | sim | Data em que o gestor pretende ativar. |
| Gestor que vai revisar e publicar | pessoa | sim | Vira o aprovador do pedido. |

**O agente devolve:** link da campanha em rascunho no Gerenciador (ABO 1/3/1), resumo da estrutura (conjuntos, públicos, anúncios) e checklist do que o gestor confere antes de ativar. Status final Para aprovação; o gestor marca Concluído depois de ativar por conta própria.

### Análise da conta

| Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- |
| Plataforma | seleção: Meta / Google / ambas | sim | Novo: o modelo atual não separa. Puxa o ID certo da ficha. |
| ID da conta de anúncio | texto | sim | Da ficha. Google: conta sob o MCC 7755113695. |
| Período | seleção (últimos 7 / 14 / 30 dias) ou intervalo de datas | sim | |
| Campanha a olhar | seleção múltipla das campanhas da conta, ou "todas" | sim | Se a integração não listar, vira texto. |
| O que motivou a análise | texto | sim | Ex.: custo subiu, poucas conversas, cliente reclamou. |
| Resultado esperado | texto | não | Ex.: custo por conversa de até R$ X, se houver meta. |
| Mudanças recentes | seleção múltipla: criativo / orçamento / público / oferta / nenhuma + texto | sim | |
| Qualidade dos contatos | texto | não | O que o cliente tem relatado. |
| Pergunta a responder | texto | sim | O que o gestor quer saber no final. |

**O agente devolve:** diagnóstico e recomendações em texto (e PDF opcional). Recomendações que o escritório pode executar saem como itens OTM (ver abaixo). Quem executa o resto é o gestor.

### Campanha no Google (rascunho) — sugerido

Hoje existe como comando ("Diretor, campanha Google: …") e o ClickUp usa "Outro". O formulário só organiza o que o agente já pede.

| Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- |
| Conta Google Ads | texto (ID) | sim | Da ficha. Conta fora do MCC ou ficha sem ID bloqueia com aviso. |
| Objetivo | seleção: ligações / WhatsApp / formulário ou site | sim | |
| Página de destino | URL | sim | Pode escolher uma LP concluída do Dev Sites. |
| Serviços a anunciar | lista de 1 a 4 itens | sim | Cada serviço vira um grupo de anúncios. |
| Cidade e raio | cidade + km | sim | O gestor confere antes de ativar. |
| Termos que o cliente NÃO atende | texto | não | Viram negativas, além das negativas padrão de negócio local. |
| Oferta real e validade | oferta + data, ou "sem oferta" | sim | |
| Orçamento diário previsto | moeda (R$) | não | Só informativo. O agente não define orçamento. |
| Gestor que vai revisar e ativar | pessoa | sim | |

**O agente devolve:** campanha de pesquisa pausada com código CMP-NNN (2 a 4 grupos, palavras frase/exata, anúncios, negativas) e a lista do que o gestor completa: raio, horário, extensões, conversão e ativação.

### Otimização (OTM) — sugerido, não é formulário

OTM nasce do agente (análise semanal de segunda 8h30 ou pedido de Análise), não da equipe. Na aba Agentes ela aparece como um cartão de aprovação, no lugar do "Diretor, aprovar OTM-NNN":

- Código OTM-NNN, cliente, conta, campanha e o motivo em uma frase.
- Lista de itens, cada um marcado 🤖 (o escritório faz após aprovo) ou ✋ (manual), com caixa para aprovar item a item.
- Botões Aprovar selecionados, Recusar e, depois de executado, Desfazer.
- Só o gestor responsável pelo cliente vê os botões.
- Itens permitidos para 🤖: negativas, adicionar ou pausar palavra, pausar ou criar anúncio. Orçamento, lance, metas de conversão, ativar campanha e pagamento nunca aparecem como 🤖.
- Depois de executar, o cartão mostra o antes e depois registrado.

### Outro

| Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- |
| O que você precisa | texto curto (uma frase) | sim | |
| Para que será usado | texto | sim | Onde e por quem. |
| Como deve ser a entrega | seleção: arquivo / texto / link + formato | sim | |
| Informações necessárias | texto longo | sim | Dados, oferta, datas. |
| Arquivos | upload ou link do Drive, ou "não se aplica" | não | |
| Imagens | seleção: usar as fotos do cliente / melhorar as fotos do cliente com IA / gerar com IA / o escritório decide | sim | |
| O que a imagem gerada deve mostrar | texto | condicional | Obrigatório quando Imagens = gerar com IA. Escondido se "usar as fotos do cliente". |
| Referência | link/upload, ou "sem referência" | não | |
| O que NÃO pode aparecer ou ser dito | texto | não | |

O mesmo formulário "Outro" serve para os outros três agentes, trocando só o setor.

## Agente Criativos

Vídeos e artes para anúncio ou perfil do cliente. Os formulários mantêm as perguntas numeradas e em linguagem simples do modelo de vídeo, porque funcionam para quem não é da área. Aviso fixo: "A IA cria cenas do tipo de serviço. Nunca inventa a fachada, a equipe ou o trabalho real do cliente."

### Vídeo

| # | Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- | --- |
| 1 | Uso | seleção: anúncio pago / Reels no Instagram do cliente | sim | |
| 2 | Duração | seleção: curto 15 s / médio 30 s / longo até 45 s | sim | |
| 2 | Formato | seleção: em pé (Reels e Stories) / quadrado / feed | sim | Padrão: em pé. |
| 3 | Material do cliente | seleção: vídeos do cliente / só fotos / nada (o escritório monta com imagens) + upload ou link do Drive | sim | Upload obrigatório se escolher vídeos ou fotos. Aceita vários arquivos. |
| 4 | Narração | seleção: voz masculina / voz feminina / não, manter a fala do cliente / não, só música | sim | Ajuda: "O escritório escreve o texto da narração. Ele vem junto com o vídeo para você conferir." "Manter a fala" só aparece se o item 3 for vídeos do cliente. |
| 5 | Legenda na tela | sim / não | sim | Padrão: sim ("muita gente assiste sem som"). |
| 6 | Imagens extras | seleção: só o que o cliente mandou / completar com imagens de IA / o escritório decide | sim | Se o item 3 for "nada", "só o que o cliente mandou" fica bloqueado. |
| 7 | Clima | seleção: animado / sério e confiável / sofisticado / divertido / o escritório decide | sim | |
| 8 | Recado principal | texto curto | sim | Ajuda: "Escreva como se explicasse para um amigo." Exemplo no placeholder. |
| 9 | Oferta real | oferta + validade, ou "sem oferta" | sim | Com oferta, a validade é obrigatória e futura. |
| 10 | Chamada final | seleção: chamar no WhatsApp / ir até a loja / ligar | sim | O número ou endereço vem da ficha. |
| 11 | Não pode | texto, ou "nada" | sim | Ex.: não mostrar o preço antigo, não aparecer o rosto do funcionário. |
| 12 | Vídeo de referência | link (Instagram ou Biblioteca de Anúncios) ou upload, ou "sem referência" | não | Ajuda: "O escritório copia o jeito de editar (ritmo, legenda, animações, música), nunca o conteúdo. Se o link não abrir, grave a tela e suba aqui." |

Com upload direto, a dica "mande um áudio começando com Diretor, pedido…" pode virar um botão "Responder por áudio": a transcrição preenche os campos e a pessoa confere antes de enviar.

**O agente devolve:** prévia do vídeo (player na própria Rotina), arquivo final em alta, texto da narração (para conferir e pedir ajuste de frase) e a legenda em texto. Para aprovação do solicitante.

### Imagem

Mesma lógica do vídeo, com os campos que mudam para arte estática.

| # | Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- | --- |
| 1 | Uso | seleção: anúncio pago / post no feed do cliente / Stories / capa ou destaque | sim | Post no feed com data de publicação é pedido de Social Media; o formulário avisa. |
| 2 | Quantidade | seleção: 1 arte / 3 variações para teste / carrossel com N telas | sim | Carrossel pede o número de telas (2 a 10). |
| 2 | Formato | seleção: em pé 1080x1350 / Stories 1080x1920 / quadrado 1080x1080 | sim | Permite marcar mais de um. |
| 3 | Material do cliente | seleção: fotos do cliente / só a logo / nada + upload ou link do Drive | sim | Logo vem da ficha quando existir. |
| 4 | Imagens extras | seleção: só o que o cliente mandou / melhorar as fotos do cliente com IA / completar com imagens de IA / o escritório decide | sim | |
| 4b | O que a imagem gerada deve mostrar | texto | condicional | Aparece quando o item 4 envolve IA. Ex.: "mãos trocando óleo". |
| 5 | Clima | seleção igual ao vídeo | sim | |
| 6 | Recado principal | texto curto | sim | |
| 7 | Oferta real | oferta + validade, ou "sem oferta" | sim | |
| 8 | Chamada | seleção: chamar no WhatsApp / ir até a loja / ligar / acessar o site | sim | |
| 9 | Texto na arte | texto, ou "o escritório cria com base na ficha" | sim | Se digitado, o agente usa exatamente esse texto. |
| 10 | Não pode | texto, ou "nada" | sim | |
| 11 | Arte de referência | link ou upload, ou "sem referência" | não | Ajuda: "O escritório copia o estilo (cores, composição, tipografia), nunca o conteúdo." |

O item 4b foi acrescentado porque os modelos de Social e Sites já têm esse campo e ele evita a IA chutar a cena.

**O agente devolve:** as artes em PNG nos formatos pedidos, com miniaturas na Rotina e botão de baixar tudo. Para aprovação do solicitante. Arte concluída passa a aparecer como opção em "Criativos aprovados" do formulário de campanha.

## Agente Dev Sites

Landing pages novas e ajustes. Aviso fixo: "A publicação na HostGator é sempre manual. O agente entrega a página pronta para aprovação."

### Landing page nova

| Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- |
| Domínio ou subdomínio | texto (domínio) | sim | Valida formato (ex.: site.com.br). Ajuda: "Sem isso a página não pode ser finalizada." Pode enviar sem e o pedido fica em Falta informação só na entrega final. |
| Objetivo da página | seleção: conversa no WhatsApp / agendamento / orçamento | sim | |
| WhatsApp que recebe os contatos | telefone com DDD | sim | Da ficha. Valida DDD + 8 ou 9 dígitos. |
| Mensagem automática do botão | texto curto | sim | Ex.: Olá, quero um orçamento. |
| Produto ou serviço principal | texto curto | sim | Apenas um foco. |
| Outros serviços a citar | lista | não | |
| Oferta real e validade | oferta + data, ou "sem oferta" | sim | |
| Cidade e região atendidas | texto | sim | Da ficha. |
| Endereço e horário | texto | sim | Da ficha. "Atende só online/a domicílio" como opção. |
| Público | texto | sim | Quem é o cliente ideal e qual problema quer resolver. |
| Diferenciais reais | texto | sim | Tempo de mercado, garantia, estrutura etc. |
| Provas reais | texto + upload, ou "não temos" | sim | Depoimentos autorizados, números, marcas atendidas. Checkbox obrigatório "os depoimentos são reais e autorizados" quando preenchido. |
| Perguntas frequentes | lista de pares pergunta / resposta, ou texto | não | |
| Fotos e logo | upload ou link do Drive | sim | Logo vem da ficha quando existir. |
| Imagens | seleção: usar as fotos do cliente / melhorar com IA / gerar com IA / o escritório decide | sim | |
| O que a imagem gerada deve mostrar | texto | condicional | Obrigatório quando envolve IA. |
| Cores e estilo | texto, ou "usar da ficha" | sim | |
| Site de referência | link, ou "sem referência" | não | |
| O que NÃO pode aparecer ou ser dito | texto | não | |

**O agente devolve:** link de prévia da página, arquivos da página prontos para subir na HostGator (com GTM e botão de WhatsApp) e checklist de publicação. Para aprovação do solicitante. Depois de publicar, quem subiu cola a URL final no pedido e marca Concluído; essa URL fica disponível nos formulários de campanha.

### Ajuste em landing page

| Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- |
| Página | seleção das LPs do cliente, ou link | sim | Lista as páginas concluídas do cliente. |
| Ajustes | lista repetível (botão "+ adicionar ajuste"), cada um com: Onde (seção ou botão), Como está, Como deve ficar, e print opcional | sim, ao menos 1 | Substitui "Ajuste 1 / Ajuste 2" fixos. O print ajuda a apontar o lugar. |
| Arquivos novos | upload ou link do Drive, ou "não se aplica" | não | |
| Motivo do ajuste | seleção: pedido do cliente / correção / nova oferta | sim | Nova oferta exige oferta real e validade. |

**O agente devolve:** prévia atualizada com a lista dos ajustes feitos (um check por item) e os arquivos atualizados. Publicação continua manual.

## Agente Social Media

Posts para o perfil do cliente. Aprovador padrão: Pablo. Aviso fixo: "Toda arte segue o estilo do Instagram do cliente, usa a logo sem fundo e fala do serviço real. Nada é publicado sem o aprovo do Pablo."

### Post de social

| Campo | Tipo | Obrig. | Regra / ajuda |
| --- | --- | --- | --- |
| Data prevista da postagem | data + hora | sim | Hora é nova: hoje o horário fica no cartão e o publicador usa ele. Mostra o dia de social do cliente (lista Social Media) como sugestão. |
| Formato | seleção: post estático / carrossel / Reels / Stories / outro (explique) | sim | Reels abre os campos de vídeo do Criativos (duração, narração, legenda). Carrossel pede número de telas. |
| Tema | texto curto | sim | Assunto do post. |
| Objetivo | seleção: educar / mostrar bastidor / gerar confiança / divulgar serviço / oferta | sim | "Oferta" torna Oferta real obrigatória. |
| Mensagem principal | texto | sim | O que a pessoa precisa entender. |
| Texto na arte | texto (frases exatas), ou "à escolha" | sim | |
| Oferta real e validade | oferta + data, ou "sem oferta" | sim | Validade não pode ser anterior à data do post. |
| Chamada para ação da legenda | texto curto | sim | Ex.: Chame no WhatsApp. |
| Foto a usar | upload ou link do Drive, ou "não se aplica" | condicional | Obrigatória se Imagens = usar ou melhorar as fotos do cliente. |
| Imagens | seleção: usar as fotos do cliente / melhorar com IA / gerar com IA / o escritório decide | sim | |
| O que a imagem gerada deve mostrar | texto | condicional | Cena, produto, ambiente. Obrigatório quando envolve IA. |
| Data ou contexto especial | texto, ou "não se aplica" | não | Ex.: Dia das Mães. |
| Referência | link ou upload, ou "sem referência" | não | |
| O que NÃO pode aparecer ou ser dito | texto | não | |

**Posts da rotina diária** (os até 4 clientes do dia, segunda a sexta 7h) não precisam de formulário: o agente cria o pedido sozinho, com Solicitante = "Rotina diária", e ele entra direto em Para aprovação.

**O agente devolve:** a arte (ou artes do carrossel), a legenda pronta e a data e hora de publicação. Pablo aprova; Concluído libera a publicação automática no horário. Se o Instagram do cliente não estiver compartilhado com o portfólio da MJ ADS, o pedido mostra "publicação manual" e um botão "marcar como publicado".

## Retorno do agente

Toda resposta do agente entra no próprio pedido, numa linha do tempo, e o solicitante e o aprovador são avisados na Rotina (e, se quiser manter, por WhatsApp só com o link). A entrega tem sempre o mesmo formato, qualquer que seja o agente.

| Parte da entrega | Conteúdo | Exemplo |
| --- | --- | --- |
| Resumo | 1 a 3 frases do que foi feito | "3 artes em 1080x1350, clima sério, oferta revisão R$ 99 até 30/11." |
| Arquivos | arquivos com miniatura e botão de baixar | PNG, MP4, PDF, arquivos da LP |
| Links | links externos | rascunho no Gerenciador, prévia da LP, CMP-NNN |
| Textos | textos para conferir | narração, legenda, textos do anúncio |
| Confira antes de aprovar | checklist curto do que o humano verifica | "Raio e horário da campanha", "Preço na arte" |
| Versão | número da entrega (v1, v2…) | cada Ajuste gera nova versão, a anterior fica no histórico |

**Ações do aprovador** (só ele vê os botões):

- **Aprovar** → Concluído. No Social, agenda a publicação. Na campanha, só registra; ativar continua no Gerenciador, pelo gestor.
- **Pedir ajuste** → Ajuste. Comentário obrigatório; dá para marcar o arquivo ou a frase que muda.
- **Cancelar** → Cancelado, com motivo.

**Perguntas do agente** (status Falta informação) aparecem como um bloco destacado no pedido com campo de resposta. Ao responder, o pedido volta para Fazendo sem precisar reenviar o formulário.

**Alertas que já existem e passam para a aba:** pedido em Para aprovação há mais de 24 h (hoje às 9h no grupo do setor) e pedido parado em Falta informação.

**Histórico do cliente:** cada pedido concluído continua indo para o historico.md do cliente, como hoje. Na Rotina, a ficha do cliente pode ganhar uma lista "Pedidos aos agentes" com os mesmos registros.

## Notas para quem desenvolve a Rotina

O schema completo de todos os formulários está no arquivo `formularios-agentes.json` (nesta mesma pasta), com campos, tipos, opções, regras condicionais e o que cada agente devolve. A Rotina pode montar os formulários a partir dele, sem tela codificada à mão.

**Tipos de campo usados no schema**

| Tipo | Como se comporta |
| --- | --- |
| client_lookup | Busca no cadastro de clientes; abre a ficha ao lado. |
| select / multiselect | Opções fixas com value e label. |
| offer | Texto da oferta + data de validade, ou botão "sem oferta". |
| upload_or_drive | Upload (até 50 MB por arquivo) ou link do Drive. |
| link_or_upload | Link (Instagram, Biblioteca de Anúncios, site) ou arquivo. |
| request_ref_or_upload | Escolhe um pedido Concluído de outro agente (ex.: criativo aprovado) ou sobe arquivo. |
| phone / url / domain / currency_brl / city_radius | Validação de formato (DDD, https, domínio, R$, cidade + km). |
| repeater | Lista de blocos repetíveis (ajustes da LP). |

**Regras que o schema descreve:**

- prefill: campo vem da ficha do cliente, editável, com selo "da ficha".
- required_if: obrigatório só quando outro campo tem certo valor (ex.: "O que a imagem gerada deve mostrar" quando Imagens envolve IA).
- allow_none_option: botão explícito para "sem oferta", "não se aplica", "sem referência".
- option_visible_if / option_disabled_if: opção que só aparece em certos casos (ex.: "manter a fala do cliente" só se o cliente mandou vídeo).

**Fluxo com o n8n**

1. Rotina valida o formulário e grava o pedido com status A fazer.
2. Rotina chama um webhook do n8n com o pedido em JSON (id, agente, tipo, cliente, campos, anexos como URLs assinadas).
3. n8n entrega ao escritório (fila que hoje vem do WhatsApp e do ClickUp). Tokens de Meta, Google, ClickUp e UAZAPI ficam só no n8n e no servidor.
4. O agente responde por um webhook de volta (mudança de status, pergunta, entrega), sempre com o id do pedido.
5. Aprovar, pedir ajuste e aprovar OTM saem da Rotina para o n8n do mesmo jeito.

Exemplo do envelope que a Rotina manda ao n8n:

```json
{
  "pedido_id": "PED-000123",
  "agente": "criativos",
  "tipo": "imagem",
  "cliente_id": "cli_casa-du-deck",
  "prioridade": "normal",
  "prazo": "2026-11-28",
  "solicitante_id": "usr_guilherme",
  "aprovador_id": "usr_guilherme",
  "campos": { "uso": "anuncio", "quantidade": "3_variacoes", "formatos": ["1080x1350"], "oferta": { "sem_oferta": true } },
  "anexos": [ { "nome": "foto1.jpg", "url": "https://…" } ]
}
```

**Pontos em aberto para decidir**

- Se o WhatsApp continua como canal paralelo durante a transição (os dois alimentando a mesma fila) ou se desliga de uma vez.
- Se o ClickUp continua como espelho dos pedidos ou se a Rotina vira a única fila.
- Quais campos da ficha a Rotina já tem hoje (ID Meta, ID Google, WhatsApp comercial, logo, cores, orçamento combinado), para saber o que dá para pré-preencher no início.
