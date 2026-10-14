# Workshop: decisões rápidas

Uma tela de live (no estilo Twitch) com chat funcional, onde cada mensagem passa por um moderador de IA. O projeto compara, lado a lado, formas diferentes de tomar a mesma decisão — bloquear ou liberar — medindo acerto, tempo e custo:

- **LLM convencional** (agente com raciocínio e tools)
- **Decisions API** (modelo que só devolve probabilidades)
- **Modelo de decisão local** (Laya, na sua máquina)
- **Endpoint de moderação pronto** (OpenAI Moderation)

Stack: Laravel 13, Livewire 4 (página em arquivo único), Blaze, Tailwind CSS 4, Laravel AI SDK, SQLite e fila em banco.

---

## Sumário

1. [Requisitos](#requisitos)
2. [Instalação](#instalação)
3. [Variáveis do `.env`](#variáveis-do-env)
4. [Rodando o projeto](#rodando-o-projeto)
5. [Comandos](#comandos)
6. [Modelos disponíveis](#modelos-disponíveis)
7. [Dataset de mensagens](#dataset-de-mensagens)
8. [Como funciona](#como-funciona)
9. [A tela](#a-tela)
10. [Laya: modelo de decisão local, base e treinado](#laya-modelo-de-decisão-local-base-e-treinado)
11. [Medições de referência](#medições-de-referência)
12. [Testes](#testes)
13. [Problemas comuns](#problemas-comuns)

---

## Requisitos

| Ferramenta | Versão | Observação |
|---|---|---|
| PHP | 8.3 ou superior | com as extensões `pdo_sqlite` e `pcntl` |
| Composer | 2.x | |
| Node.js | 20 ou superior | com npm |
| SQLite | 3.x | já vem com o PHP na maioria das instalações |

Opcional, só para o modelo local Laya: Python 3.10 a 3.13 e, de preferência, uma GPU NVIDIA.

Não é preciso Docker. O projeto roda direto na máquina, com SQLite.

---

## Instalação

```bash
# 1. Dependências do PHP
composer install

# 2. Arquivo de ambiente e chave da aplicação
cp .env.example .env
php artisan key:generate

# 3. Banco de dados (SQLite) e tabelas
touch database/database.sqlite
php artisan migrate

# 4. Dependências do front-end
npm install
```

Depois, preencha as chaves de API no `.env` (próxima seção).

Atalho: depois de criar o arquivo do banco (`touch database/database.sqlite`), `composer run setup` faz o resto de uma vez: instala as dependências, cria o `.env`, gera a chave, migra e gera o build do front-end.

---

## Variáveis do `.env`

### Obrigatórias para os modelos

Só é preciso a chave dos modelos que você for usar. Sem a chave, as mensagens daquele modelo passam sem moderação e aparecem como **Indisponível**.

| Variável | Para quê | Modelos que dependem dela |
|---|---|---|
| `OPENAI_API_KEY` | API da OpenAI | GPT Luna (LLM), GPT Luna (Decisions), OpenAI Moderation |
| `ANTHROPIC_API_KEY` | API da Anthropic | Claude Haiku |
| `TYPESAFE_API_KEY` | API da TypeSafe | Jev |

### Do projeto

| Variável | Padrão | Para quê |
|---|---|---|
| `MODERATION_STRIKES` | `false` | Se strikes e ban começam ligados. O botão da tela sobrepõe esse valor até o cache ser limpo. |
| `MODERATION_MODEL` | `gpt-6-luna` | Modelo selecionado ao abrir a tela e usado pelo `chat:replay` quando `--model` não é informado. Aceita qualquer chave da tabela de [modelos](#modelos-disponíveis). |
| `LAYA_URL` | `http://127.0.0.1:8765/v1` | Endereço do servidor local do Laya. |
| `LAYA_API_KEY` | `local` | Só é necessária se o servidor do Laya for iniciado com chave. |
| `SERVER_HOST` | `127.0.0.1` | Use `0.0.0.0` para acessar a tela de outra máquina ou do Windows quando o projeto roda no WSL. |

### Infraestrutura (já vêm prontas no `.env.example`)

| Variável | Valor | Por quê |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | Banco em arquivo, sem servidor. |
| `QUEUE_CONNECTION` | `database` | A moderação roda em fila; os jobs ficam na tabela `jobs`. |
| `CACHE_STORE` | `database` | Guarda o estado da chave "Strikes e ban", compartilhado entre a tela e os workers. |
| `SESSION_DRIVER` | `database` | Padrão do Laravel. |

---

## Rodando o projeto

```bash
composer run dev
```

Esse comando sobe tudo em um painel só:

| Processo | O que faz |
|---|---|
| `server` | Aplicação em http://localhost:8000 |
| `queue` + `queue-2` … `queue-15` | **15 workers** da fila, que moderam as mensagens em paralelo |
| `logs` | Logs em tempo real (Pail) |
| `vite` | Front-end com recarga automática |

Abra **http://localhost:8000/watch**.

Para mudar a quantidade de workers, altere o `range(2, 15)` em `app/Providers/AppServiceProvider.php`.

---

## Comandos

### `chat:replay` — envia mensagens do dataset para a fila

```bash
php artisan chat:replay [--model=CHAVE] [--limit=50] [--fresh]
```

| Opção | Padrão | Descrição |
|---|---|---|
| `--model=` | valor de `MODERATION_MODEL` | Chave do modelo (veja a tabela de modelos). |
| `--limit=` | `50` | Quantas mensagens enviar. Sempre as **primeiras N** do dataset, para todos os modelos receberem as mesmas. |
| `--fresh` | — | Apaga **todas** as mensagens de **todos** os modelos antes de enviar. Não mexe na chave de strikes. |

Para testar a moderação em todos os modelos, mande as mesmas 200 mensagens para cada um e compare na tela (`/watch`):

```bash
php artisan chat:replay --limit=200 --model=gpt-6-luna
php artisan chat:replay --limit=200 --model=claude-haiku
php artisan chat:replay --limit=200 --model=gpt-6-luna-decisions
php artisan chat:replay --limit=200 --model=jev
php artisan chat:replay --limit=200 --model=laya
php artisan chat:replay --limit=200 --model=laya-treinado
php artisan chat:replay --limit=200 --model=openai-moderation
```

> **Atenção ao custo:** cada mensagem é uma chamada paga nos modelos da OpenAI (LLM e Decisions), Anthropic e TypeSafe. Laya e OpenAI Moderation são gratuitos. `laya` e `laya-treinado` precisam do servidor local do Laya ligado (veja [Laya](#laya-modelo-de-decisão-local-base-e-treinado)).

> Rodar o mesmo modelo duas vezes soma as rodadas. Para começar uma comparação do zero, acrescente `--fresh` ao primeiro comando.

### `tickets:replay` — envia tickets do dataset para o Inbox

```bash
php artisan tickets:replay [--model=CHAVE] [--limit=N] [--context] [--fresh]
```

| Opção | Padrão | Descrição |
|---|---|---|
| `--model=` | valor de `TRIAGE_MODEL` (`jev`) | Chave de um modelo **decision** (o Inbox não usa LLM nem Moderation API). |
| `--limit=` | todos | Quantos tickets enviar, sempre os **primeiros N** de `database/datasets/tickets.jsonl` (o dataset tem 66). |
| `--context` | — | Manda o **perfil do cliente** no `state`, junto do ticket (veja abaixo). |
| `--fresh` | — | Apaga **todos** os tickets de **todos** os modelos antes de enviar. |

Para testar o Inbox em todos os modelos, mande os 66 tickets para cada um e troque de modelo no topo da tela (`/inbox`). Cada modelo tem o próprio quadro:

```bash
php artisan tickets:replay --model=gpt-6-luna-decisions
php artisan tickets:replay --model=jev
php artisan tickets:replay --model=laya
php artisan tickets:replay --model=laya-treinado
```

Os tickets têm gabarito de time, então a tela mostra "Time certo" e "Certo no automático". O `laya-treinado` foi ajustado só para moderação de chat; no Inbox ele serve para ver como o ajuste fino se comporta fora da tarefa em que foi treinado.

#### Perfil do cliente no `state`

O `state` não precisa ser só o texto do usuário. Com `--context`, cada ticket vai para o modelo junto do perfil fictício do cliente, tirado de `database/datasets/customers.json`:

```json
{"ticket": "Não consigo mais entrar no curso, aparece que meu acesso está bloqueado.",
 "cliente": {"cliente": true, "plano": "Clã mensal", "pagamento": "atrasado há 12 dias: cartão recusado em 3 tentativas", "...": "..."}}
```

Os 6 últimos tickets do dataset (tipo `contexto`) foram escritos para isso: o texto sozinho é ambíguo e o time certo só aparece no perfil. Os tickets 61 e 62 têm **o mesmo texto**; um cliente está com o pagamento atrasado (Cobrança) e o outro em dia, depois de trocar o e-mail (Acesso). O mesmo vale para 64 e 65, com o ticket aberto no histórico decidindo o time.

Para mostrar a diferença, rode sem e depois com o perfil, e compare o "Time certo" e os cards do tipo `contexto`:

```bash
php artisan tickets:replay --model=jev
php artisan tickets:replay --model=jev --context
```

As duas rodadas caem no mesmo quadro; os tickets com perfil têm o selo **+perfil** e, ao abrir um deles, o modal mostra o `state` exato que o modelo recebeu. Para comparar só uma rodada por vez, use `--fresh` no comando. Na tela, o seletor acima do campo da coluna Entrada abre um ticket como uma das personas (`config/triage.php`), com o perfil dela no `state`.

> Os workers precisam estar rodando (`composer run dev`); sem eles, os tickets ficam parados na coluna Entrada. Assim como no chat, rodar o mesmo modelo duas vezes soma as rodadas, e `--fresh` no primeiro comando começa do zero.

### `laya:export` — gera os arquivos de treino do Laya

```bash
php artisan laya:export [--path=DIRETORIO] [--valid=10]
```

Converte `database/datasets/chat-messages-train.jsonl` para o formato que o `laya-train` lê, com **as mesmas 6 perguntas que o projeto envia** à Decisions API. Grava `train.jsonl` e `valid.jsonl` em `storage/app/laya` (ou em `--path`). `--valid` é a porcentagem separada para validação.

Se você alterar as diretrizes em `config/moderation.php`, rode de novo e treine outra vez: o modelo aprende o texto exato das perguntas.

### Outros comandos úteis

| Comando | Para quê |
|---|---|
| `composer run dev` | Sobe servidor, 15 workers, logs e Vite. |
| `php artisan migrate` | Aplica migrations novas. Funciona com os workers ligados. |
| `php artisan migrate:fresh` | Recria o banco do zero. **Pare o `composer run dev` antes** (veja [Problemas comuns](#problemas-comuns)). Também volta a chave de strikes para o padrão de `MODERATION_STRIKES` (desligada). |
| `php artisan queue:flush` | Limpa a lista de jobs que falharam. |
| `php artisan test --compact` | Roda a suíte de testes. |
| `vendor/bin/pint --format agent` | Formata o código PHP. |

---

## Modelos disponíveis

Definidos em `config/moderation.php`.

| Chave (`--model=`) | Nome na tela | Tipo | Provider | Modelo | Preço por 1M tokens (entrada / saída) |
|---|---|---|---|---|---|
| `gpt-6-luna` | GPT Luna | LLM (agente) | OpenAI | `gpt-6-luna` | US$ 0,10 / US$ 0,50 |
| `claude-haiku` | Claude Haiku | LLM (agente) | Anthropic | `claude-haiku-5-5` | US$ 0,10 / US$ 0,50 |
| `gpt-6-luna-decisions` | GPT Luna | Decisions API | OpenAI | `gpt-6-luna` | US$ 0,10 / grátis |
| `jev` | Jev | Decisions API | TypeSafe | `jev-latest` | US$ 0,042 / grátis |
| `laya` | Laya base (local) | Decisions API | servidor local | `multilingual` | grátis |
| `laya-treinado` | Laya treinado (local) | Decisions API | servidor local | `moderador` (ajuste fino) | grátis |
| `openai-moderation` | OpenAI Moderation | Moderation API | OpenAI | `omni-moderation-latest` | grátis |

Os preços alimentam o custo mostrado na tela. Foram conferidos nas páginas oficiais em outubro de 2026; revise antes de apresentar.

### Os três tipos

| | LLM (agente) | Decisions API | Moderation API |
|---|---|---|---|
| O que recebe | mensagem + as 6 diretrizes + instruções | mensagem + 6 perguntas sim/não (uma por diretriz) | só a mensagem |
| O que devolve | veredito, diretriz violada e **raciocínio** | probabilidade de cada pergunta | pontuação por categoria fixa da OpenAI |
| Quem define a regra | você, no prompt | você, em cada pergunta | a OpenAI (não aceita instrução) |
| Strikes e ban | o modelo chama **tools** | **regra em código** | **regra em código** |

### Para adicionar um modelo

Acrescente uma linha em `config/moderation.php`:

```php
'minha-chave' => ['label' => 'Nome na tela', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'id-do-modelo', 'input_price' => 0.10, 'output_price' => 0.50],
```

`driver` aceita `agent`, `decision` ou `moderation`. O `provider` precisa existir em `config/ai.php`.

---

## Dataset de mensagens

Arquivo: `database/datasets/chat-messages.jsonl` — **1.272 mensagens** em português, uma por linha.

```json
{"id":1,"user":"zoeiro_toxico","text":"...","blocked":true,"category":"spam","kind":"adversarial","note":"..."}
```

| Campo | Descrição |
|---|---|
| `id` | Posição no arquivo. |
| `user` | Autor fictício, fixo por mensagem (30 usuários). |
| `text` | A mensagem, como digitada no chat. |
| `blocked` | **Gabarito**: `true` se deve ser bloqueada. |
| `category` | Violação principal, ou `normal` quando liberada. |
| `kind` | Tipo do caso de teste (tabela abaixo). |
| `note` | Por que o gabarito é esse. |

### Composição

**751 bloqueáveis, 521 liberáveis.**

| `kind` | Qtd. | O que testa |
|---|---|---|
| `claro` | 429 | Aprovado ou reprovado sem discussão. |
| `limiar` | 310 | Casos de fronteira: palavrão sem alvo, crítica dura ao código, ofensa disfarçada de elogio, ameaça velada, autopromoção sutil. |
| `ortografia` | 247 | Erros de digitação, internetês, leetspeak (`0t4r10`), letras separadas, link por extenso. |
| `misto` | 148 | Parte aceitável e parte reprovável na mesma mensagem. |
| `adversarial` | 138 | Injeção de prompt com violação junto, outros idiomas, formatos extremos. |

| `category` | Qtd. | | `category` | Qtd. |
|---|---|---|---|---|
| `normal` | 521 | | `golpe` | 109 |
| `ofensa` | 160 | | `odio` | 101 |
| `spam` | 123 | | `ameaca` | 95 |
| | | | `assedio` | 86 |
| | | | `sexual` | 77 |

### Usuários

30 autores fictícios em três perfis: 12 reincidentes (concentram as mensagens bloqueáveis), 12 ocasionais e 6 que nunca quebram regra. Nas 200 primeiras mensagens, 15 usuários deveriam ser banidos (3 ou mais mensagens bloqueáveis), 7 ficam com 1 ou 2 deslizes e 8 ficam limpos.

### Dataset de treino

Arquivo: `database/datasets/chat-messages-train.jsonl` — **4.239 mensagens**, usadas só para o ajuste fino do Laya.

```json
{"id":1,"text":"...","blocked":true,"violations":["spam_golpe"],"kind":"limiar","note":"..."}
```

A diferença para o dataset de teste é o campo `violations`: a lista das diretrizes violadas (chaves de `config/moderation.php`), vazia quando a mensagem é liberada. É ele que permite treinar uma pergunta por diretriz.

| | Qtd. |
|---|---|
| Bloqueáveis / liberáveis | 2.367 / 1.872 |
| `claro` / `limiar` / `misto` / `adversarial` / `ortografia` | 1.756 / 1.323 / 520 / 324 / 316 |
| Por diretriz | assédio 473, spam e golpe 471, ódio 416, assédio sexual 384, ameaça 373, dados pessoais 359 |
| Com duas diretrizes violadas | 109 |

Como foi feito, para o treino não contaminar o teste:

- **Geração separada**: outros agentes, outros cenários de live, sem acesso ao dataset de teste.
- **Auditoria por fatia**: 170 mensagens com rótulo disputável ou texto artificial foram removidas e 28 tiveram a diretriz corrigida.
- **Filtro contra o teste**: 30 mensagens idênticas a alguma de teste (quase todas curtas, como risadas) e 20 muito parecidas foram removidas.

Continua sendo dado sintético, gerado e rotulado por IA com a mesma política do teste. Mede "aprendeu a política deste canal", não "funciona em chat real".

### Observações importantes

- O arquivo está **embaralhado com semente fixa**: as primeiras N mensagens são sempre as mesmas e já misturam todos os tipos.
- As mensagens foram **geradas por IA** e os rótulos **auditados por IA** (55 casos com gabarito disputável foram removidos). Não houve revisão humana.
- O conteúdo reprovável é fictício: sem nomes de pessoas reais, e com links apenas em domínios inventados (`.test`).
- O gabarito segue a política **deste canal**, que inclui spam, golpe e divulgação. Um modelo pode ser bom no que se propõe e ainda assim ir mal aqui.
- Para strikes e ban, use até umas 200–300 mensagens. No dataset inteiro, quase todos os usuários acabam banidos e a maior parte das mensagens é pulada.

---

## Como funciona

```
mensagem enviada ──► grava em chat_messages ──► job ModerateChatMessage na fila
                                                        │
                              ┌─────────────────────────┼──────────────────────────┐
                              ▼                         ▼                          ▼
                        LLM (agente)             Decisions API              Moderation API
                     prompt + diretrizes      6 perguntas sim/não            só o texto
                              └─────────────────────────┼──────────────────────────┘
                                                        ▼
                                  veredito gravado ──► a tela atualiza a cada 1 s
```

1. A mensagem aparece no chat na hora e entra na fila.
2. Um worker chama o modelo, mede o tempo e grava o veredito.
3. Se for bloqueada, o texto vira *"mensagem removida pela moderação"*.
4. Se o moderador falhar (sem chave, sem crédito, fora do ar), a mensagem é **liberada** e marcada como **Indisponível**.

### Diretrizes

As regras ficam em `config/moderation.php`, em `guidelines`. São 6, destiladas das Diretrizes da Comunidade da Twitch mais as regras do canal: Violência e ameaças, Conduta de ódio, Assédio, Assédio sexual e conteúdo sexual, Informações privadas, Spam e golpes. Cada uma tem `block` (o que viola) e `allow` (as exceções).

- O **agente** recebe todas e diz qual foi violada.
- A **Decisions API** responde uma pergunta por diretriz na mesma chamada; a mais provável decide.
- A **Moderation API** ignora as diretrizes.

### Strikes e ban

Cada mensagem bloqueada dá um strike ao autor; no terceiro, ele é banido naquele modelo, e as mensagens seguintes são removidas sem chamar o moderador.

- **Agentes** fazem isso com duas tools: `RegistrarStrike` e `BanirUsuario` (`app/Ai/Tools/`).
- **Decisions e Moderation** não usam tool: a mesma regra roda em código, no job.
- O recurso começa **desligado** (`MODERATION_STRIKES=false`). A chave **Strikes e ban** na barra lateral liga e desliga para todos os modelos, da próxima mensagem em diante; a escolha fica no cache e vale até ele ser limpo.

### Arquivos principais

| Arquivo | Papel |
|---|---|
| `resources/views/pages/⚡watch.blade.php` | A tela inteira: comportamento e visual (Livewire 4, arquivo único). |
| `app/Jobs/ModerateChatMessage.php` | O job que chama o modelo e grava o veredito. |
| `app/Ai/Agents/ChatModerator.php` | O agente: instruções, saída estruturada, tools e as perguntas da Decisions API. |
| `app/Ai/Tools/` | As tools de strike e ban. |
| `app/Models/ChatMessage.php` | A mensagem e seu veredito. |
| `app/Console/Commands/ReplayChatDataset.php` | O comando `chat:replay`. |
| `config/moderation.php` | Diretrizes e modelos. |
| `config/ai.php` | Providers do Laravel AI SDK (inclui o `laya`). |

---

## A tela

- **Barra lateral:** os modelos, agrupados por tipo, com o tempo médio de cada um. Clicar troca o modelo; os resultados de cada um ficam guardados. Embaixo, a chave **Strikes e ban**.
- **Player:** a última decisão do modelo selecionado — veredito, diretriz violada, raciocínio (quando há), tempo, tokens, probabilidade, custo da mensagem e custo total, tempo da fila e histórico recente.
- **Chat:** mensagens em tempo real, com o selo de strikes (`1/3`, `2/3`, `banido`) ao lado do autor.
- **Comparar modelos** (botão no player): modal com resumo de cada modelo, acerto por tipo de mensagem, uso de strikes e bans e a lista de erros de cada um.

O acerto só considera mensagens enviadas pelo `chat:replay`, que têm gabarito. Mensagens digitadas no chat ficam fora da conta.

---

## Laya: modelo de decisão local, base e treinado

O Laya é um modelo de decisão de pesos abertos (Apache 2.0) que fala o mesmo protocolo do Jev. No projeto ele usa o mesmo driver da TypeSafe, só trocando a URL, e aparece duas vezes na barra lateral:

- **Laya base (local)**: o checkpoint multilíngue publicado, sem ajuste.
- **Laya treinado (local)**: o mesmo checkpoint depois do ajuste fino com o dataset de treino deste repositório.

Os dois recebem **exatamente a mesma requisição** que o Jev: 6 perguntas sim/não, uma por diretriz.

### 1. Instalar

```bash
mkdir ~/laya-serve && cd ~/laya-serve
python3 -m venv .venv            # Python 3.10 a 3.13
.venv/bin/pip install "laya[serve]"
```

Ocupa cerca de 5 GB. Fica fora do projeto.

### 2. Rodar só o modelo base

```bash
cd ~/laya-serve
LAYA_HOST=127.0.0.1 LAYA_PORT=8765 LAYA_DEVICE=cuda LAYA_MODELS=multilingual .venv/bin/laya-serve
```

A primeira execução baixa o checkpoint. Sem GPU, use `LAYA_DEVICE=cpu`.

### 3. Treinar

```bash
# No projeto: gera os arquivos de treino com as perguntas que o app envia
php artisan laya:export --path=$HOME/laya-serve/data

# No ~/laya-serve: ajuste fino a partir do checkpoint multilíngue
cd ~/laya-serve
.venv/bin/laya-train \
  --data data/train.jsonl \
  --eval data/valid.jsonl \
  --base multilingual \
  --out checkpoints/moderador \
  --device cuda \
  --epochs 4
```

- Em uma RTX 4080 Super levou **cerca de 16 minutos** (22.490 itens de treino: 3.815 mensagens × 6 perguntas, 4 épocas).
- No fim, o comando imprime o antes e depois no conjunto de validação e grava o checkpoint em `checkpoints/moderador` (~600 MB).
- O checkpoint treinado **não vai para o Git**. Quem clonar o repositório precisa treinar.

### 4. Rodar base e treinado juntos

```bash
cd ~/laya-serve
LAYA_HOST=127.0.0.1 LAYA_PORT=8765 LAYA_DEVICE=cuda \
LAYA_MODELS=multilingual LAYA_MAX_LOADED=2 \
LAYA_EXTRA_MODELS='{"moderador": "'$HOME'/laya-serve/checkpoints/moderador"}' \
.venv/bin/laya-serve
```

`LAYA_EXTRA_MODELS` registra o checkpoint treinado com o nome `moderador`, que é o `model` da entrada `laya-treinado` em `config/moderation.php`.

### 5. Comparar na ferramenta

```bash
php artisan chat:replay --limit=200 --model=laya --fresh
php artisan chat:replay --limit=200 --model=laya-treinado
```

Depois abra **Comparar modelos** na tela. Com o servidor fora do ar, ou sem o checkpoint treinado registrado, as mensagens passam como **Indisponível**.

### Resultado do ajuste fino

Medido direto no servidor, com a mesma requisição do app e sem strikes:

| | Laya base | Laya treinado |
|---|---|---|
| Teste, 200 primeiras mensagens | 56,5% | **86,0%** |
| Teste, as 1.272 mensagens | 59,1% | **86,9%** |
| Bloqueou (de 1.272; 751 são bloqueáveis) | 1.271 | 650 |
| Deixou passar / bloqueou à toa | 0 / 520 | 134 / 33 |
| Validação (10% do treino, por pergunta) | 15,2% | 96,5% |
| Tempo por mensagem | ~20 ms | ~21 ms |

Por tipo, nas 1.272 (treinado): adversarial 128/138, claro 384/429, ortografia 216/247, misto 124/148, limiar 253/310.

Como ler esses números:

- **O modelo base não decide nada neste formato**: responde "viola" para praticamente tudo. Os 59% são a proporção de mensagens bloqueáveis.
- **O ajuste fino funciona**: 27 a 30 pontos a mais, sem custo de latência, com 16 minutos de treino local.
- **A diferença entre 96,5% e 86,9% é a lição principal.** Na validação, que vem do mesmo lote do treino, o modelo parece quase perfeito. No teste, gerado em outro momento, cai dez pontos. É o efeito de treinar com dado sintético: o modelo aprende também o jeito do gerador. Com mensagens reais a queda tende a ser maior.
- **O erro mudou de lado**: o treinado erra mais deixando passar do que bloqueando à toa.
- Uma rodada de treino, uma semente, sem busca de hiperparâmetros. O Jev, no mesmo teste de 200, fica em 97,5%.

---

## Medições de referência

Medidas nas **200 primeiras mensagens** do dataset, com strikes desligados. São uma rodada por modelo, em um único dia: servem como ordem de grandeza, não como benchmark.

| Modelo | Acerto | Tempo médio por mensagem | Custo (200 mensagens) |
|---|---|---|---|
| GPT Luna (LLM) | 99,0% | ~2,4 s | ~US$ 0,024 |
| Jev (Decisions) | 97,5% | ~0,4 s | ~US$ 0,010 |
| Laya treinado (local) | 86,0% | ~0,03 s direto no servidor | grátis |
| OpenAI Moderation | 58,5% | ~0,6 s | grátis |
| Laya base (local) | 56,5% | ~0,03 s direto no servidor | grátis |

Referência: bloquear tudo sem olhar acerta 56,5% nessas 200 mensagens.

O que observamos, com as devidas ressalvas:

- **Diretrizes por pergunta ajudaram a Decisions API.** O Jev foi de 93,0% (uma pergunta genérica) para 97,5% (uma pergunta por diretriz), sem aumento de latência. As diretrizes foram escritas conhecendo os erros anteriores, então parte do ganho é ajuste ao dataset.
- **Tools funcionaram nos LLMs, a um custo.** Em 50 mensagens, GPT Luna e Claude Haiku registraram todos os strikes e baniram os usuários certos. No Claude Haiku, tempo e tokens de entrada cerca de dobraram com as tools ligadas.
- **Latência de LLM varia com o horário.** A mesma requisição ao GPT Luna foi de ~2,4 s para ~4,5 s em uma hora, enquanto o Jev ficou em ~0,4 s. Compare modelos sempre na mesma janela de tempo.
- **O resultado oscila entre rodadas.** O mesmo modelo, com as mesmas mensagens, variou cerca de 1 ponto percentual.
- **Ban automático amplifica o erro.** Um modelo que bloqueia demais expulsa os usuários, e as mensagens seguintes nem são avaliadas.
- **A Moderation API não aceita instrução.** Colocar um prompt dentro do texto não funciona: o prompt passa a ser classificado junto e enfraquece o sinal.
- **Laya base, usado como os autores recomendam** (perguntas curtas em inglês, sem critérios), chegou a ~70%. Com ajuste fino no formato do projeto, 86%.

---

## Testes

```bash
php artisan test --compact
```

Os testes usam fakes do Laravel AI SDK e do cliente HTTP: não fazem chamadas reais nem gastam crédito.

---

## Problemas comuns

**A página dá erro 500 depois de atualizar o código**
Provavelmente há migration pendente. Rode `php artisan migrate`.

**`database is locked` ao rodar `migrate:fresh`**
Os 15 workers e outros processos seguram o arquivo do SQLite. Pare o `composer run dev`, rode o comando e suba de novo. Se persistir, verifique se há processos `php artisan boost:mcp` abertos (`pkill -f "artisan boost:mcp"`) e remova `database/database.sqlite-shm` e `database/database.sqlite-wal`.
Para apenas limpar o chat, prefira `php artisan chat:replay --fresh`, que não recria o banco.

**Todas as mensagens aparecem como "Indisponível"**
O moderador falhou e as mensagens foram liberadas. Causas mais comuns: chave ausente no `.env`, conta sem crédito, ou servidor do Laya parado. O motivo exato aparece no painel do player e em `storage/logs/laravel.log`.

**Erro "rate limited" da OpenAI sem ter feito muitas chamadas**
O SDK chama de "rate limited" qualquer resposta 429, inclusive falta de crédito. Confira o saldo da conta.

**Mensagens paradas "na fila"**
Os workers não estão rodando. Confirme que o `composer run dev` está ativo.

**Não consigo acessar a tela pelo Windows com o projeto no WSL**
Defina `SERVER_HOST=0.0.0.0` no `.env` e reinicie o `composer run dev`.

**A chave "Strikes e ban" voltou a ficar ligada sozinha**
O `migrate:fresh` apaga o cache, onde o estado dela fica guardado. O padrão é ligada.

**`vendor/bin/pint --dirty` reclama de Git**
A opção `--dirty` só funciona em repositório Git. Use `vendor/bin/pint --format agent`.
