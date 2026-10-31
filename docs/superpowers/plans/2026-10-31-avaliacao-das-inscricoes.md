# Avaliação das inscrições — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Avaliadores do edital com convite por e-mail, distribuição das inscrições homologadas (igual, manual, por categoria, transferência) e a área do avaliador (link pessoal + CPF) para dar notas por critério e concluir; a equipe vê as notas concluídas.

**Architecture:** Quatro tabelas novas (vínculo edital–avaliador com as categorias dele, avaliação da inscrição, nota por critério com cópia de peso e pontuação máxima) e o acesso guardado no avaliador. A retaguarda ganha **Inscrições → Avaliação**, por edital, com as abas Avaliadores, Distribuição e Notas. A área do avaliador é um terceiro espaço (`/avaliacao`), sem `User`, com sessão própria conferida a cada requisição. O convite sai por um comando `artisan` lançado solto (o padrão `Lancador*` do projeto; não há fila), com a situação gravada no vínculo.

**Tech Stack:** Laravel 13, Inertia v2, Vue 3 + TS (`lang="ts"`), Tailwind v4, PHPUnit, Oracle (yajra) nos testes, PostgreSQL ≥ 15 nos colegas.

**Spec:** `docs/superpowers/specs/2026-10-31-avaliacao-das-inscricoes-design.md`

## Global Constraints

- Pasta de trabalho (worktree): `W=C:/Users/Breno/AppData/Local/Temp/claude/E--Dev-Mapa-Cultura/e4d54214-7206-4ddb-aab8-4b4296549565/scratchpad/wt-avaliacao`, branch `feat/avaliacao-inscricoes`.
- Testes (um arquivo por vez; o banco Oracle de testes é compartilhado):
  `MSYS_NO_PATHCONV=1 docker run --rm -v "$W:/var/www/html" -v "E:/Dev/Mapa_Cultura/vendor:/var/www/html/vendor:ro" --network mapa_cultura_default -w /var/www/html mapa_cultura-app php artisan test --compact <arquivo>`
  Ruído ORA-00054/00942/00904 é outro processo no banco: rode o arquivo de novo.
- Pint no mesmo container: `vendor/bin/pint --format agent <arquivos>` (sem `--dirty`: o container não tem git).
- Build: `npm run build` no worktree (vue-tsc + vite) sem erro. Se faltar `node_modules`, crie a junção: PowerShell `New-Item -ItemType Junction -Path "$W/node_modules" -Target "E:/Dev/Mapa_Cultura/node_modules"`.
- Nunca: push, merge, `migrate` no banco de dev, `migrate:fresh`, Prettier, dependência nova, subagentes.
- Oracle: ids voltam como string — sempre `(int)`; identificadores ≤ 30 caracteres (nomeie índices e chaves); `decimal:2` volta string; comparação de texto diferencia maiúsculas (`LOWER(..) LIKE ? ESCAPE '\'`); VARCHAR2 conta bytes — use `App\Rules\TamanhoEmBytes`; `''` vira NULL.
- Travas na ordem **edital → inscrição** (`App\Support\Inscricao\Travas::edital()`, `::inscricao()`), e o estado é conferido de novo na linha travada.
- Dia civil: `App\Models\Concerns\UsaDiaCivil` (America/Bahia), comparação por `toDateString()`, como `EditalChamada::naHomologacao()`.
- Rotas: `whereNumber` em todo id; edital pelo slug (`{edital}`); páginas GET com dados pessoais sob `inertia.encrypt`; `scopeBindings()` onde há pai e filho. Mensagens de erro em `public const string` no controller, em português. Flash `sucesso` / `erro` / `aviso`.
- Front: Vue `lang="ts"` com props tipadas em `resources/js/types/index.ts`; componentes do rebrand (`PaginaRetaguarda`, `PainelLista`, `BarraAcoes`, `IconeAcao`, `SecaoCadastro`, `CampoCadastro`); **toggle (`Interruptor`, role=switch) em vez de checkbox**; campo obrigatório com `*` vermelho no rótulo (`CampoCadastro obrigatorio`) e erro por `aria-invalid` (o destaque "opção C" é CSS global). Caminhos escritos à mão (sem helper de rotas), com comentário citando o nome da rota.
- O **código do link** do avaliador nunca vai para props (exceto a URL da própria página de acesso), sessão (só o hash), flash, log, mensagem de erro, chave de cache ou URL da retaguarda.
- "Homologada" = `inscricoes.situacao = finalizada` **e** `inscricoes.homologacao = homologada`.
- Os campos do edital `nota_maxima`, `nota_corte` e `pontuacao_extra` **não entram** nesta entrega: a nota final é só a soma de nota × peso dos critérios; esses campos ficam para a classificação.
- Comentários e PHPDoc como os do código vizinho (PHPDoc curto em português; tipos de array com shape).

## Review Focus

1. **Avaliador troca o id na URL** (inscrição, documento) para ver a inscrição de outro avaliador → 404, sem vazar nada. Testes na Task 9.
2. **Transferência ou nova distribuição enquanto o avaliador salva/conclui** → travas edital→inscrição; avaliação concluída nunca muda de dono nem perde nota. Testes nas Tasks 8 e 9.
3. **Segunda rodada da distribuição** mexe em inscrição que já tem avaliador → não mexe, e equilibra pela carga atual. Testes na Task 7.
4. **Edital editado depois da primeira nota** (peso, pontuação, escopo do critério, categoria excluída) → recusado; a nota final usa a cópia gravada. Testes nas Tasks 2 e 3.
5. **Link antigo ou avaliador desativado com a sessão aberta** → a próxima requisição derruba a sessão. Testes na Task 4.

---

### Task 1: Dados da avaliação

**Files:**
- Create: `app/Enums/SituacaoConvite.php` (`NaFila='na_fila'`, `Enviado='enviado'`, `Falhou='falhou'`; `rotulo()`: "Na fila", "Enviado", "Falhou"), `app/Enums/SituacaoAvaliacao.php` (`NaoIniciada`, `EmAndamento`, `Concluida`; `rotulo()`: "Não iniciada", "Em andamento", "Concluída"), `app/Enums/ModoDistribuicao.php` (`Igual='igual'`, `PorCategoria='por_categoria'`; `rotulo()`: "Igual entre os avaliadores", "Por categoria").
- Create migrations (`php artisan make:migration`): `add_acesso_to_avaliadores_table` — `acesso_hash` string(64) nullable, unique `avaliadores_acesso_uk`; `acesso_cifrado` string(1000) nullable. `create_edital_avaliadores_table` — `id`, `edital_id` FK (sem cascade), `avaliador_id` FK, `convite_situacao` string(20) nullable, `convite_erro` string(500) nullable, `convite_enviado_em` datetime nullable, `convite_iniciado_em` datetime nullable, `convite_execucao` string(36) nullable, timestamps, unique (`edital_id`,`avaliador_id`) `edital_aval_unico`; pivot `edital_avaliador_categoria` (`edital_avaliador_id` FK cascadeOnDelete, `edital_categoria_id` FK cascadeOnDelete, primary `edital_aval_cat_pk`). `create_inscricao_avaliacoes_table` — `inscricao_avaliacoes`: `id`, `inscricao_id` FK unique `insc_aval_inscricao_uk`, `avaliador_id` FK index `insc_aval_avaliador_idx`, `atribuida_por` FK users nullable `insc_aval_atrib_por_fk`, `atribuida_em` datetime, `observacao` string(4000) nullable, `concluida_em` datetime nullable, `nota_final` decimal(10,2) nullable, timestamps; `inscricao_avaliacao_notas`: `id`, `inscricao_avaliacao_id` FK cascadeOnDelete `insc_aval_nota_aval_fk`, `edital_criterio_id` FK restrictOnDelete `insc_aval_nota_crit_fk`, `nota` decimal(6,2), `peso` decimal(6,2), `pontos_maximos` decimal(6,2), timestamps, unique (`inscricao_avaliacao_id`,`edital_criterio_id`) `insc_aval_nota_uk`.
- Create models + factories: `EditalAvaliador` (table `edital_avaliadores`; casts `convite_situacao` → `SituacaoConvite`, datas; `edital()`, `avaliador()`, `categorias(): BelongsToMany` via `edital_avaliador_categoria`), `InscricaoAvaliacao` (`inscricao()`, `avaliador()`, `atribuidor()`, `notas(): HasMany`; casts datas, `nota_final` `decimal:2`), `InscricaoAvaliacaoNota` (`avaliacao()`, `criterio()`; casts `decimal:2`). Factories with states `InscricaoAvaliacaoFactory::concluida()`.
- Modify: `app/Models/EditalChamada.php` (`temPeriodoDeAvaliacao(): bool`, `naAvaliacao(?CarbonInterface $agora = null): bool` sobre `validacao_inicio`/`validacao_fim`, espelho de `naHomologacao`), `app/Models/Inscricao.php` (`avaliacao(): HasOne<InscricaoAvaliacao>`, `naAvaliacao(?CarbonInterface $agora = null): bool`), `app/Models/Avaliador.php` (`vinculos(): HasMany<EditalAvaliador>`, `avaliacoes(): HasMany<InscricaoAvaliacao>`; `acesso_hash`/`acesso_cifrado` fora do fillable e em `$hidden`), `app/Models/Edital.php` (`avaliadores(): HasMany<EditalAvaliador>`).
- Test: `tests/Feature/Avaliacao/DadosDaAvaliacaoTest.php`

**Interfaces:**
- Produces: os enums, models e relações acima; `InscricaoAvaliacao::situacao(): SituacaoAvaliacao` (concluida_em → Concluida; com nota ou observação → EmAndamento; senão NaoIniciada); `EditalChamada::naAvaliacao()`, `Inscricao::naAvaliacao()`.

- [ ] **Step 1: Write the failing tests** — `test_periodo_de_avaliacao_em_dias_inteiros_de_salvador` (chamada `validacao_inicio=2026-12-15`, `validacao_fim=2026-12-20`; `naAvaliacao` falso em `2026-12-14 23:59` Bahia, verdadeiro em `2026-12-15 00:00` e `2026-12-20 23:59`, falso em `2026-12-21 00:00`; chamada sem as datas → falso e `temPeriodoDeAvaliacao()` falso); `test_inscricao_tem_uma_avaliacao_so` (segunda `InscricaoAvaliacao` da mesma inscrição → `UniqueConstraintViolationException`); `test_nota_por_criterio_e_unica` (idem para a nota); `test_avaliador_entra_uma_vez_no_edital` (idem para `EditalAvaliador`); `test_situacao_da_avaliacao` (sem nota → NaoIniciada; com uma nota → EmAndamento; só observação → EmAndamento; `concluida_em` → Concluida); `test_acesso_fica_fora_da_serializacao` (`Avaliador::toArray()` sem `acesso_hash`/`acesso_cifrado`).
- [ ] **Step 2: Run** — FAIL (classes/tabelas inexistentes).
- [ ] **Step 3: Implement** os arquivos acima.
- [ ] **Step 4: Run** — PASS. Rode também `tests/Feature/Retaguarda/AvaliadoresTest.php` e `tests/Feature/Inscricao/DadosDaHomologacaoTest.php`.
- [ ] **Step 5: Pint + commit** `feat: dados da avaliação das inscrições`.

### Task 2: Critérios que valem, nota final e pendências

**Files:**
- Create: `app/Support/Avaliacao/CriteriosDaInscricao.php`, `app/Support/Avaliacao/NotaFinal.php`
- Modify: `app/Models/Edital.php`
- Test: `tests/Feature/Avaliacao/RegrasDaAvaliacaoTest.php`

**Interfaces:**
- Produces:
  - `CriteriosDaInscricao::para(Inscricao $inscricao): Collection<int, EditalCriterio>` — os do edital com `todas_categorias` mais os ligados à `edital_categoria_id` da inscrição; sem categoria, só os de todas; ordem `ordem`, `id`.
  - `NotaFinal::de(iterable<InscricaoAvaliacaoNota> $notas): string` — soma de `nota × peso` das notas gravadas (peso já copiado, 1 quando o critério não tinha), em centésimos inteiros para não errar arredondamento, devolvida com 2 casas (`'21.00'`).
  - `NotaFinal::PESO_PADRAO = '1.00'`.
  - `Edital::avaliacaoIniciada(): bool` — existe nota em alguma avaliação de inscrição do edital.
  - `Edital::pendenciasDaDistribuicao(): list<string>` com as constantes `PENDENCIA_SEM_AVALIADOR = 'Inclua ao menos um avaliador ativo no edital.'`, `PENDENCIA_SEM_CRITERIO = 'Cadastre ao menos um critério na aba Avaliação do edital.'`, `PENDENCIA_CRITERIO_SEM_PONTOS = 'Preencha a pontuação máxima de todos os critérios na aba Avaliação do edital.'`.
  - `Edital::AVISO_CRITERIOS_TRAVADOS = 'Este edital já tem notas dadas: os critérios não podem ser acrescentados, removidos, nem ter peso, pontuação máxima ou categorias alterados. Nome e descrição continuam editáveis.'` e `Edital::AVISO_CATEGORIAS_TRAVADAS = 'Este edital já tem notas dadas: as categorias salvas não podem ser removidas.'`

- [ ] **Step 1: Write the failing tests** — `test_criterios_de_todas_mais_os_da_categoria` (3 critérios: todas; só da categoria A; só da B → inscrição da A recebe [todas, A] em ordem); `test_inscricao_sem_categoria_so_os_de_todas`; `test_nota_final_soma_nota_vezes_peso` (8 com peso 2 + 5 com peso 1 → `'21.00'`; 7.25 com peso 1.5 + 3 com peso 1 → `'13.88'`); `test_avaliacao_iniciada` (falso; verdadeiro depois de uma nota de rascunho); `test_pendencias_da_distribuicao` (sem avaliador, avaliador só inativo, sem critério, critério sem `pontos_maximos` — cada uma devolve exatamente a sua constante; tudo certo → `[]`).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Pint + commit** `feat: critérios que valem, nota final e pendências da avaliação`.

### Task 3: Critérios e categorias travados no edital

**Files:**
- Modify: `app/Http/Requests/Retaguarda/SalvarEditalRequest.php`, `app/Http/Controllers/Retaguarda/EditalController.php`, `resources/js/Pages/Retaguarda/Editais/Form.vue`, `resources/js/Components/Retaguarda/Edital/CriteriosEdital.vue`, o componente da lista de categorias do edital (o que monta `ListaEditavel` com `prefixo="categorias"`), `resources/js/types/index.ts` (`RetaguardaEditaisFormProps`)
- Test: `tests/Feature/Retaguarda/EditalCriteriosTravadosTest.php` (pode extrair os helpers `dadosCompletos`/envio de `EditalEstruturaSalvarTest` para um trait em `tests/Support/`)

**Interfaces:**
- Consumes: `Edital::avaliacaoIniciada()`, `AVISO_CRITERIOS_TRAVADOS`, `AVISO_CATEGORIAS_TRAVADAS` (Task 2).
- Produces: `SalvarEditalRequest::mudaCriterioSalvo(Edital $edital): bool` e `::removeCategoriaSalva(Edital $edital): bool` — mesmo padrão de `removeDocumentoSalvo()` (só com `comListas()`). `mudaCriterioSalvo` é verdadeiro quando: some critério salvo da lista; entra critério novo (id nulo); num critério salvo muda `peso` ou `pontos_maximos` (comparados com 2 casas), `todas_categorias`, ou o conjunto de **ids** de categoria (posição enviada → `categorias.{posição}.id`; posição de categoria nova conta como mudança). Props novas: `criteriosTravados: bool`, `avisoCriteriosTravados: string`, `avisoCategoriasTravadas: string`.

- [ ] **Step 1: Write the failing tests** — com uma nota de rascunho numa inscrição do edital: `test_com_nota_nao_remove_criterio`, `test_com_nota_nao_acrescenta_criterio`, `test_com_nota_nao_muda_peso_pontos_nem_categorias` (três envios, cada um `assertSessionHasErrors(['criterios' => Edital::AVISO_CRITERIOS_TRAVADOS])` e nada muda no banco), `test_com_nota_edita_nome_e_descricao` (salva), `test_com_nota_nao_remove_categoria` (erro em `categorias`), `test_sem_nota_edita_criterios_normalmente`, `test_nota_durante_o_salvamento_trava` (hook `Edital::saving` cria a nota no meio, como `test_decisao_durante_o_salvamento_trava_a_remocao`), `test_tela_de_edicao_avisa_a_trava_dos_criterios` (props `criteriosTravados` true e os avisos).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — regra no `after()` do request e a mesma conferência repetida em `EditalController::salvar` depois do `lockForUpdate` (lança `ValidationException`). No front: `CriteriosEdital` recebe `travados`/`avisoTravados`, mostra o aviso (`role="note"`, como `DocumentosEdital`), passa `remover-so-novos` e esconde "acrescentar" quando travado, e deixa peso, pontuação máxima, "todas as categorias" e as categorias dos critérios salvos só para leitura; a lista de categorias recebe `remover-so-novos` com o aviso. O erro igual ao aviso não aparece duas vezes (`errosDaLista`, como em `DocumentosEdital`).
- [ ] **Step 4: Run** — PASS; rode também `EditalEstruturaSalvarTest.php` e `EditaisTest.php`; `npm run build`.
- [ ] **Step 5: Pint + commit** `feat: critérios e categorias travados depois da primeira nota`.

### Task 4: Acesso do avaliador (link pessoal + CPF)

**Files:**
- Create: `routes/avaliacao.php` (incluído por `routes/web.php`, `Route::prefix('avaliacao')->name('avaliacao.')`), `app/Http/Controllers/Avaliacao/AcessoDoAvaliadorController.php`, `app/Http/Controllers/Avaliacao/InicioController.php` (provisório: só a saudação; a Task 9 completa), `app/Http/Middleware/GarantirAvaliador.php`, `app/Support/Avaliacao/AvaliadorDaSessao.php`, `resources/js/Layouts/AvaliacaoLayout.vue` (a partir do `PortalLayout`, com o nome do avaliador e o botão "Sair"), `resources/js/Pages/Avaliacao/Acesso.vue`, `resources/js/Pages/Avaliacao/Inicio.vue`
- Modify: `app/Models/Avaliador.php`, `bootstrap/app.php` (alias `avaliador`; ramo do `ThrottleRequestsException` para `avaliacao.acesso.confirmar` → `back()->withErrors(['cpf' => ...])`), `app/Providers/AppServiceProvider.php` (limitador `avaliacao-cpf`), `app/Http/Middleware/HandleInertiaRequests.php` (prop `avaliador: {nome} | null`; `version()` separa `avaliacao/*`), `resources/js/types/index.ts`
- Test: `tests/Feature/Avaliacao/AcessoDoAvaliadorTest.php`

**Interfaces:**
- Produces:
  - `Avaliador::novoAcesso(): string` — gera `Str::random(48)`, grava `acesso_hash = hash('sha256', $codigo)` e `acesso_cifrado = Crypt::encryptString($codigo)`, devolve o código; `Avaliador::linkDeAcesso(): ?string` — `route('avaliacao.acesso', $codigo)` a partir do cifrado (null sem acesso ou se não decifrar); `Avaliador::porCodigo(string $codigo): ?Avaliador` — ativo e com o hash.
  - Rotas: `GET /avaliacao/acesso/{codigo}` `avaliacao.acesso` (`{codigo}` = `[A-Za-z0-9]{48}`; código desconhecido → 404); `POST` mesma URL `avaliacao.acesso.confirmar` com `throttle:avaliacao-cpf`; `POST /avaliacao/sair` `avaliacao.sair`; `GET /avaliacao` `avaliacao.inicio` sob `avaliador`.
  - Sessão: `avaliacao.avaliador` (id) e `avaliacao.acesso` (o hash). `GarantirAvaliador` confere a cada requisição avaliador **ativo** com o mesmo hash; senão tira as duas chaves e manda para `/` com `erro` `GarantirAvaliador::ERRO_SEM_ACESSO = 'Seu acesso de avaliador terminou. Use o link mais recente enviado por e-mail.'`. Põe o avaliador em `$request->attributes` (`AvaliadorDaSessao::atual(Request $request): Avaliador`).
  - `AcessoDoAvaliadorController::ERRO_CPF = 'O CPF não confere com o deste link.'`; CPF validado com `Cpf::valido` (mensagem `'CPF inválido. Confira os números.'`) e comparado normalizado com `hash_equals`. Entrar faz `session()->regenerate()`. Sair tira só as chaves do avaliador e `regenerateToken()`.
  - Limitador `avaliacao-cpf`: `Limit::perMinute(5)->by(hash('sha256', $codigo).'|'.$ip)`.

- [ ] **Step 1: Write the failing tests** — `test_link_e_cpf_certos_entram` (redireciona para `/avaliacao`; a página abre com `avaliador.nome`); `test_cpf_com_pontuacao_tambem_entra`; `test_cpf_errado_nao_entra` (erro `cpf` = `ERRO_CPF`, sem chaves na sessão); `test_sem_link_nao_entra` (GET `/avaliacao` sem sessão → `/`); `test_codigo_desconhecido_da_404`; `test_avaliador_desativado_perde_a_sessao` (entra, desativa, GET `/avaliacao` → `/` com `ERRO_SEM_ACESSO`); `test_novo_link_derruba_a_sessao_e_invalida_o_antigo` (entra, `novoAcesso()`, GET → `/`; o código antigo → 404); `test_limite_de_tentativas_de_cpf` (6ª tentativa → erro `cpf` com "Muitas tentativas"); `test_sair_encerra_so_o_avaliador`; `test_codigo_nao_fica_na_sessao` (o código cru não aparece em `serialize(session()->all())`).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** A página `Acesso.vue` posta para a própria URL; não repete o código em props.
- [ ] **Step 4: Run** — PASS; rode também `tests/Feature/TrocaDeAreaTest.php` e `tests/Feature/Retaguarda/LoginTest.php`; `npm run build`.
- [ ] **Step 5: Pint + commit** `feat: acesso do avaliador por link pessoal e CPF`.

### Task 5: Convite por e-mail

**Files:**
- Create: `app/Mail/ConviteDaAvaliacao.php`, `resources/views/emails/convite-avaliacao.blade.php` (texto puro), `app/Console/Commands/EnviarConviteCommand.php` (`avaliacao:enviar-convite {avaliador} {execucao}`), `app/Support/Avaliacao/LancadorDeConvite.php` (padrão `LancadorDeAnalise`), `app/Support/Avaliacao/Convites.php`
- Modify: `app/Models/EditalAvaliador.php`
- Test: `tests/Feature/Avaliacao/ConviteDaAvaliacaoTest.php`

**Interfaces:**
- Consumes: `Avaliador::novoAcesso()`, `linkDeAcesso()` (Task 4); `App\Support\FalhaNoEnvioDeEmail::mensagem()`.
- Produces:
  - `ConviteDaAvaliacao(string $nome, string $link, list<array{titulo: string, periodo: string}> $editais)`; assunto `'Convite para avaliar inscrições · Portal da FGM'`; o texto traz o nome, cada edital com o período de avaliação, o link, "Ao abrir, confirme o seu CPF." e "Este link é pessoal: não o encaminhe.".
  - `Convites::enviar(Avaliador $avaliador, Collection<int, EditalAvaliador> $vinculos): void` — cria o acesso se faltar; marca os vínculos `NaFila` com uma `execucao` nova (uuid) e `convite_iniciado_em`; chama o lançador; se o lançamento falhar, marca `Falhou` com `EditalAvaliador::ERRO_AO_INICIAR = 'Não foi possível iniciar o envio. Tente reenviar.'`.
  - O comando envia **um** e-mail para os vínculos do avaliador com aquela `execucao`, pelo mailer padrão (`Mail::to(...)->send(...)`), e marca `Enviado` (+ `convite_enviado_em`, erro nulo) ou `Falhou` (+ `convite_erro` da `FalhaNoEnvioDeEmail::mensagem`, com host e porta da configuração em uso; `Log::warning` só com a classe e o código da falha). Execução que não é mais a gravada é ignorada.
  - `EditalAvaliador::situacaoDoConvite(?CarbonInterface $agora = null): ?SituacaoConvite` e `erroDoConvite(...)`: `NaFila` há mais de `MINUTOS_LIMITE = 15` vira `Falhou` com `ERRO_SEM_TERMINO = 'O envio não terminou. Tente reenviar.'`.

- [ ] **Step 1: Write the failing tests** (lançador trocado no container por um que roda o comando na hora; `Mail::fake()`): `test_convite_vai_com_o_link_e_os_editais` (`assertSent` com `hasTo` do e-mail, link contendo `/avaliacao/acesso/`, títulos; vínculo `Enviado` com data); `test_cria_o_acesso_quando_falta`; `test_falha_no_envio_marca_falhou_sem_vazar` (servidor `127.0.0.1` porta 1 como em `ProvedorEmailTest`; `convite_erro` é a mensagem amigável e não contém a senha nem o código); `test_falha_ao_iniciar_marca_falhou`; `test_na_fila_ha_mais_de_15_minutos_aparece_como_falhou`; `test_execucao_antiga_e_ignorada`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; rode também `ProvedorEmailTest.php`.
- [ ] **Step 5: Pint + commit** `feat: convite do avaliador por e-mail`.

### Task 6: Retaguarda — editais da avaliação e aba Avaliadores

**Files:**
- Create: `app/Http/Controllers/Retaguarda/AvaliacaoController.php` (`index`), `app/Http/Controllers/Retaguarda/AvaliadoresDaAvaliacaoController.php` (`index`, `store`, `categorias`, `destroy`, `convite`, `novoLink`), requests `IncluirAvaliadoresRequest`, `CategoriasDoAvaliadorRequest`, `resources/js/Pages/Retaguarda/Avaliacao/Index.vue`, `.../Avaliacao/Avaliadores.vue`, `resources/js/Components/Retaguarda/Avaliacao/AbasDaAvaliacao.vue` (links das abas Avaliadores, Distribuição, Notas, com `aria-current="page"`)
- Modify: `routes/retaguarda.php`, `resources/js/Components/Retaguarda/menuRetaguarda.ts` (item "Avaliação" `/retaguarda/avaliacao` no grupo Inscrições, e o comentário das rotas), `resources/js/types/index.ts`
- Test: `tests/Feature/Retaguarda/AvaliacaoAvaliadoresTest.php`

**Interfaces:**
- Consumes: `Convites::enviar` (Task 5), `pendenciasDaDistribuicao` (Task 2).
- Produces: rotas `retaguarda.avaliacao.index` (GET `/avaliacao`), `retaguarda.avaliacao.avaliadores.index` (GET `/avaliacao/editais/{edital}/avaliadores`), `.avaliadores.store` (POST, `avaliadores: int[]`), `.avaliadores.categorias` (PUT `/avaliadores/{vinculo}/categorias`, `categorias: int[]`), `.avaliadores.destroy` (DELETE `/avaliadores/{vinculo}`), `.avaliadores.convite` (POST `/avaliadores/{vinculo}/convite`, `throttle:10,1,convite-avaliador`), `.avaliadores.novo-link` (POST `/avaliadores/{vinculo}/novo-link`). Edital não publicado → 404; `{vinculo}` de outro edital → 404.
  - `index`: editais publicados com ao menos uma inscrição homologada; busca pelo título; colunas `homologadas`, `semAvaliador`, `concluidas`.
  - `store`: só avaliadores **ativos** e ainda fora do edital; manda o convite; flash `'N avaliador(es) incluído(s). O convite está sendo enviado.'`.
  - `categorias`: só categorias do edital (`'Escolha categorias deste edital.'`).
  - `destroy`: recusa com `ERRO_TEM_INSCRICOES = 'Este avaliador tem inscrições do edital. Transfira-as antes de tirá-lo.'`.
  - `novoLink`: `novoAcesso()` e `Convites::enviar` com **todos** os vínculos do avaliador; flash `'Link novo gerado e enviado para {email}. O link antigo deixou de valer.'`.
  - Props da aba: `edital {slug, titulo, temCategorias, periodos}` (períodos de **avaliação** por chamada, no formato da homologação — extraia o formatador de `HomologacaoController::periodosDeHomologacao` para `App\Support\Edital\PeriodosDoEdital::formatar(Edital $edital, string $prefixo): list<string>` e use nos dois), `avaliadores` (por vínculo: `id`, `nome`, `email`, `ativo`, `categorias: int[]`, `convite {situacao, rotulo, enviadoEm, erro}`, `inscricoes`, `naoConcluidas`), `disponiveis` (`id`, `nome`, `cpf` mascarado), `categorias` (`id`, `nome`), `pendencias`.
  - Tela: categorias de cada avaliador com **toggles**; aviso para avaliador desativado com inscrições não concluídas.

- [ ] **Step 1: Write the failing tests** — `test_lista_editais_com_homologadas_e_contagens`; `test_inclui_avaliadores_ativos_e_manda_o_convite` (lançador falso registra a chamada; vínculo `NaFila`); `test_nao_inclui_inativo_nem_repetido`; `test_define_categorias_so_do_edital`; `test_nao_tira_avaliador_com_inscricoes`; `test_tira_avaliador_sem_inscricoes`; `test_reenvia_convite`; `test_novo_link_troca_o_acesso_e_reenvia_para_todos_os_editais`; `test_vinculo_de_outro_edital_da_404`; `test_edital_nao_publicado_da_404`; `test_props_sem_codigo_do_link` (nenhuma prop contém o código nem `/avaliacao/acesso/`); `test_quem_nao_e_gestor_vai_para_o_login`; `test_menu_tem_avaliacao`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; rode também `HomologacaoListaTest.php`; `npm run build`.
- [ ] **Step 5: Pint + commit** `feat: avaliadores do edital na retaguarda`.

### Task 7: Distribuição igual e por categoria

**Files:**
- Create: `app/Support/Avaliacao/DistribuidorDeInscricoes.php`, `app/Support/Avaliacao/PlanoDeDistribuicao.php` (readonly), `app/Http/Controllers/Retaguarda/DistribuicaoController.php` (`index`, `distribuir`), `app/Http/Requests/Retaguarda/DistribuirRequest.php`, `resources/js/Pages/Retaguarda/Avaliacao/Distribuicao.vue`
- Modify: `routes/retaguarda.php`, `resources/js/types/index.ts`
- Test: `tests/Feature/Retaguarda/AvaliacaoDistribuicaoTest.php`, `tests/Unit/DistribuidorDeInscricoesTest.php`

**Interfaces:**
- Produces:
  - `new DistribuidorDeInscricoes(?Random\Randomizer $sorteio = null)`; `plano(Edital $edital, ModoDistribuicao $modo): PlanoDeDistribuicao`. Candidatas: homologadas **sem** avaliação, embaralhadas. Destinos: vínculos com avaliador **ativo** (por categoria: os ligados à categoria da inscrição). Cada inscrição vai para o destino com **menos inscrições no edital** naquele momento (carga atual + o que o plano já deu); empate sorteado.
  - `PlanoDeDistribuicao { array<int, int> $atribuicoes (inscrição → avaliador); array<int, int> $porAvaliador; array<string, int> $semAvaliador (nome da categoria → quantas sobraram) }`.
  - Rotas `retaguarda.avaliacao.distribuicao.index` (GET `/avaliacao/editais/{edital}/distribuicao`, `?modo=` mostra a estimativa) e `.distribuicao.store` (POST, `modo`). `distribuir` trava o edital, recusa com as pendências (`erro` com a primeira), refaz o plano e grava (`atribuida_por`, `atribuida_em`); flash `'Distribuídas N inscrições: Ana 3, Bruno 2.'` e, se sobrou, `aviso` `'Ficaram sem avaliador: 2 de Teatro. Inclua um avaliador para essas categorias.'`. Nada para distribuir → `aviso` `'Não há inscrições homologadas sem avaliador.'`.
  - Props: `edital`, `pendencias`, `modos` (por categoria só com categorias), `previa` (`porAvaliador {nome, quantidade}[]`, `semAvaliador`), `inscricoes` paginadas (`id`, `numero`, `proponente`, `categoria`, `avaliador`, `situacaoRotulo`), `destinos` (vínculos ativos: `avaliadorId`, `nome`) — usados pela Task 8.

- [ ] **Step 1: Write the failing tests** — Unit (`Randomizer` com `Mt19937(1)`): `test_empate_e_sorteado_e_reproduzivel`, `test_carga_atual_conta`. Feature: `test_igual_divide_cinco_entre_dois_em_tres_e_dois`; `test_so_homologadas_entram` (não homologada, em recurso, recurso negado, sem decisão e rascunho ficam de fora); `test_segunda_rodada_so_pega_as_novas_e_equilibra` (Ana 3, Bruno 2, uma nova → Bruno; as antigas não mudam); `test_por_categoria_respeita_as_categorias_e_avisa_sobras`; `test_inativo_nao_recebe`; `test_pendencias_impedem_distribuir` (cada uma); `test_previa_mostra_a_estimativa`; `test_edital_nao_publicado_da_404`; `test_quem_nao_e_gestor_vai_para_o_login`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; `npm run build`.
- [ ] **Step 5: Pint + commit** `feat: distribuição das inscrições entre os avaliadores`.

### Task 8: Atribuição manual e transferência

**Files:**
- Create: `app/Http/Controllers/Retaguarda/AtribuicaoController.php` (`atribuir`, `transferirTodas`), requests `AtribuirRequest` (`avaliador_id`), `TransferirRequest` (`de`, `para`)
- Modify: `routes/retaguarda.php`, `resources/js/Pages/Retaguarda/Avaliacao/Distribuicao.vue` (seleção do avaliador por linha; "Transferir todas de … para …"), `resources/js/Pages/Retaguarda/Avaliacao/Avaliadores.vue` (atalho de transferência no aviso do desativado)
- Test: `tests/Feature/Retaguarda/AvaliacaoAtribuicaoTest.php`

**Interfaces:**
- Produces: `retaguarda.avaliacao.atribuicao` (PUT `/avaliacao/editais/{edital}/inscricoes/{inscricao}/avaliador`) e `retaguarda.avaliacao.transferencia` (POST `/avaliacao/editais/{edital}/transferencia`). Trava edital → inscrição. Inscrição de outro edital ou não homologada → 404. Destino: vínculo do edital com avaliador ativo, senão erro `'Escolha um avaliador ativo deste edital.'`. Sem avaliação → cria; concluída → `ERRO_JA_CONCLUIDA = 'Esta avaliação já foi concluída e não pode ser transferida.'`; mesmo avaliador → nada; senão troca o avaliador, apaga as notas e a observação, regrava `atribuida_por/em`. `transferirTodas` move só as não concluídas de `de` para `para` e informa quantas.

- [ ] **Step 1: Write the failing tests** — `test_atribui_manualmente_inscricao_sem_avaliador`; `test_transfere_e_apaga_o_rascunho`; `test_nao_transfere_concluida`; `test_destino_precisa_ser_ativo_e_do_edital`; `test_inscricao_de_outro_edital_da_404`; `test_transfere_todas_as_nao_concluidas` (as concluídas ficam); `test_quem_nao_e_gestor_vai_para_o_login`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; rode também `AvaliacaoDistribuicaoTest.php`; `npm run build`.
- [ ] **Step 5: Pint + commit** `feat: atribuição manual e transferência de inscrições`.

### Task 9: Área do avaliador — inscrições, notas e conclusão

**Files:**
- Create: `app/Http/Controllers/Avaliacao/InscricoesDoAvaliadorController.php` (`show`, `arquivo`, `salvar`, `concluir`), `app/Http/Requests/Avaliacao/SalvarNotasRequest.php`, `resources/js/Pages/Avaliacao/Inscricao.vue`, `resources/js/Components/Avaliacao/InscricaoCompleta.vue` (só leitura: escolhas e perfil, documentos, questionário — sem parecer da IA e sem marcação; a partir das seções do `Homologacao/Show.vue`), `resources/js/Components/Avaliacao/NotasDosCriterios.vue`
- Modify: `app/Http/Controllers/Avaliacao/InicioController.php`, `resources/js/Pages/Avaliacao/Inicio.vue`, `routes/avaliacao.php`, `resources/js/types/index.ts`
- Test: `tests/Feature/Avaliacao/AvaliacaoDaInscricaoTest.php`

**Interfaces:**
- Consumes: `AvaliadorDaSessao::atual`, `CriteriosDaInscricao::para`, `NotaFinal::de`, `Inscricao::naAvaliacao`, `ConferenciaDaInscricao::documentos()`, `QuestionarioDaInscricao`, `PropsDaInscricao::secoes`, `RetratoDoPerfil::linhas`.
- Produces:
  - Rotas sob `avaliador` (GETs também sob `inertia.encrypt`): `avaliacao.inicio` (GET `/avaliacao`: editais com inscrições dele, período de avaliação, e cada inscrição com `numero`, `proponente`, `categoria`, `situacaoRotulo`, `notaFinal` só se concluída), `avaliacao.inscricoes.show` (GET `/avaliacao/inscricoes/{inscricao}`), `avaliacao.inscricoes.arquivo` (GET `.../documentos/{documento}/arquivo`), `avaliacao.inscricoes.notas` (PUT `.../notas`), `avaliacao.inscricoes.conclusao` (POST `.../conclusao`). Inscrição que não é do avaliador da sessão → **404** em todas.
  - `salvar`: trava edital → inscrição; recusa concluída (`ERRO_CONCLUIDA = 'Esta avaliação já foi concluída.'`) e fora do período (`ERRO_FORA_DO_PERIODO = 'Fora do período de avaliação: a inscrição fica só para consulta.'`); grava as notas dos critérios que valem (`peso` copiado, 1 quando vazio; `pontos_maximos` copiado), apaga as deixadas em branco, grava a observação. `concluir`: o mesmo e exige nota em todos (`ERRO_FALTAM_NOTAS = 'Dê nota a todos os critérios para concluir.'`), grava `concluida_em` e `nota_final`.
  - `SalvarNotasRequest`: `notas` objeto `{criterioId: string|null}` só com ids dos critérios que valem (outro id → erro); cada nota `numeric`, `min:0`, até 2 casas (`/^\d{1,4}([.,]\d{1,2})?$/`, vírgula vira ponto no `prepareForValidation`), máximo do critério conferido no `after()` com `'A nota de "{nome}" vai de 0 a {máximo}.'`; `observacao` nullable, `max:4000` + `TamanhoEmBytes(4000)`, com `\r\n` normalizado.
  - Props do `show`: `inscricao` (como a conferência: `numero`, `edital`, `chamada`, `proponente`, `documento` mascarado, `cadastro`, `categoria`, `cotas`), `perfil`, `documentos` (`id`, `nome`, `etapaRotulo`, `obrigatorio`, `enviado {nome, url}` pela rota do avaliador), `secoes`, `respostas`, `perguntasVisiveis`, `criterios` (`id`, `nome`, `descricao`, `peso`, `pontosMaximos`), `notas`, `observacao`, `situacaoRotulo`, `notaFinal`, `podeAvaliar`, `aviso`.
  - Tela: notas com `inputmode="decimal"` e `*` vermelho (obrigatórias para concluir); "Salvar rascunho" e "Concluir avaliação" (confirmação `'Depois de concluir, as notas não podem mais ser alteradas.'`); concluída ou fora do período → só leitura com o aviso.

- [ ] **Step 1: Write the failing tests** — `test_inicio_mostra_so_as_inscricoes_dele`; `test_inscricao_de_outro_avaliador_da_404` (show, arquivo, notas, conclusão); `test_abre_o_arquivo_da_propria_inscricao`; `test_props_sem_caminho_de_arquivo` (nenhuma prop contém `'inscricoes/'`); `test_salva_rascunho_no_periodo_com_copia_de_peso_e_pontos`; `test_fora_do_periodo_so_consulta` (antes e depois; `podeAvaliar` falso; salvar recusado); `test_nota_acima_do_maximo_e_recusada`; `test_aceita_virgula_e_duas_casas`; `test_criterio_que_nao_vale_e_recusado`; `test_concluir_exige_todas_as_notas`; `test_concluir_grava_nota_final_e_trava` (salvar e concluir de novo → `ERRO_CONCLUIDA`); `test_observacao_longa_e_recusada`; `test_inscricao_transferida_sai_do_avaliador_antigo`; `test_nota_final_nao_muda_se_o_criterio_mudar_depois` (altera `peso` direto no banco depois de concluir; `nota_final` e a tela continuam iguais).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; rode também `AcessoDoAvaliadorTest.php` e `HomologacaoConferenciaTest.php`; `npm run build`.
- [ ] **Step 5: Pint + commit** `feat: área do avaliador com notas e conclusão`.

### Task 10: Notas na retaguarda

**Files:**
- Create: `app/Http/Controllers/Retaguarda/NotasDaAvaliacaoController.php` (`index`, `show`), `resources/js/Pages/Retaguarda/Avaliacao/Notas.vue`, `resources/js/Pages/Retaguarda/Avaliacao/NotasDaInscricao.vue`
- Modify: `routes/retaguarda.php`, `resources/js/types/index.ts`
- Test: `tests/Feature/Retaguarda/AvaliacaoNotasTest.php`

**Interfaces:**
- Produces: `retaguarda.avaliacao.notas.index` (GET `/avaliacao/editais/{edital}/notas`, filtro por situação: sem avaliador, não iniciada, em andamento, concluída) e `retaguarda.avaliacao.notas.show` (GET `/avaliacao/editais/{edital}/notas/{inscricao}`). Linhas: `numero`, `proponente`, `categoria`, `avaliador`, `situacaoRotulo`, `notaFinal` **só quando concluída**. `show` só de avaliação **concluída** (senão 404): cada critério com nome, nota, peso e pontuação máxima **copiados**, nota final, observação, avaliador, `concluidaEm`.

- [ ] **Step 1: Write the failing tests** — `test_lista_com_nota_final_so_das_concluidas`; `test_rascunho_nunca_aparece` (em andamento: `notaFinal` nulo e nenhuma nota nem observação nas props); `test_mostra_a_nota_por_criterio_com_a_copia`; `test_avaliacao_nao_concluida_da_404`; `test_inscricao_de_outro_edital_da_404`; `test_filtra_por_situacao`; `test_quem_nao_e_gestor_vai_para_o_login`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; `npm run build`.
- [ ] **Step 5: Pint + commit** `feat: notas das avaliações na retaguarda`.

### Task 11: Verificação final

- [ ] Suíte completa (só quando nada mais usa o banco de testes): `... php artisan test --compact` — tudo passa.
- [ ] `npm run build` limpo.
- [ ] PostgreSQL 16 descartável: `migrate`, `migrate:rollback --step=4`, `migrate` sem erro (os índices nomeados existem).
- [ ] Nenhum `dd(`, `dump(`, `console.log` novo; nenhum arquivo `.env` no diff.
