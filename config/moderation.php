<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat Moderation
    |--------------------------------------------------------------------------
    |
    | The models listed in the stream sidebar. The "agent" driver prompts the
    | ChatModerator agent on a conventional LLM, while the "decision" driver
    | asks the same question through the AI SDK decisions API, and the
    | "moderation" driver calls the OpenAI moderation endpoint, which has
    | its own fixed categories and ignores the guidelines above. The default
    | is the key of the model selected when the page loads. Prices are in
    | US dollars per million tokens and feed the cost shown in the player.
    |
    */

    'default' => env('MODERATION_MODEL', 'gpt-6-luna'),

    /*
    |--------------------------------------------------------------------------
    | Community Guidelines
    |--------------------------------------------------------------------------
    |
    | The rules every moderator decides by, distilled from the Twitch Community
    | Guidelines (safety.twitch.tv) plus the rules of this channel. The agent
    | receives all of them and names the one violated; the decisions API
    | answers one yes / no question per guideline in a single call.
    |
    */

    'guidelines' => [
        'ameaca' => [
            'label' => 'Violência e ameaças',
            'block' => 'Ameaça ferir ou matar alguém, ou ameaça hackear, derrubar, expor dados ou mandar a polícia à casa de alguém. Inclui ameaça velada e intimidação, como insinuar que sabe onde a pessoa mora, que vai aparecer ou que ela vai se arrepender.',
            'allow' => 'Exagero evidente sobre código, jogo ou trabalho sem pessoa como alvo, e termos técnicos como matar um processo.',
        ],
        'odio' => [
            'label' => 'Conduta de ódio',
            'block' => 'Ataca, desumaniza, exclui ou faz piada com pessoas por raça, etnia, origem, religião, sexo, gênero, identidade de gênero, orientação sexual, deficiência ou idade.',
            'allow' => 'Falar desses temas de forma neutra, relatar ou denunciar um caso de preconceito.',
        ],
        'assedio' => [
            'label' => 'Assédio',
            'block' => 'Xinga, humilha ou diminui uma pessoa ou um grupo do chat, mesmo em tom educado, irônico ou de brincadeira. Deseja mal a alguém, insiste depois de ser ignorado ou promete continuar incomodando.',
            'allow' => 'Palavrão como interjeição sem alvo, provocação leve e claramente amistosa, autodepreciação e crítica dura a código, aula, ferramenta ou decisão técnica sem atacar a pessoa.',
        ],
        'assedio_sexual' => [
            'label' => 'Assédio sexual e conteúdo sexual',
            'block' => 'Comentário, pedido ou investida sexual sobre alguém, objetificação do corpo de uma pessoa, conteúdo sexual explícito ou divulgação de conteúdo adulto.',
            'allow' => 'Elogio comum sem conotação sexual.',
        ],
        'dados_pessoais' => [
            'label' => 'Informações privadas',
            'block' => 'Expõe ou pede dados pessoais de alguém, como nome completo, endereço, local de trabalho, perfis pessoais ou rotina, ou diz que procurou e encontrou esses dados.',
            'allow' => 'Pergunta inofensiva em contexto de conversa, como primeiro nome, cidade ou tecnologia que a pessoa usa.',
        ],
        'spam_golpe' => [
            'label' => 'Spam, golpes e conduta maliciosa',
            'block' => 'Flood, corrente, anúncio, pedido de seguidor ou inscrição, divulgação de canal, curso, produto, cupom, grupo ou perfil próprio, link encurtado ou disfarçado, phishing, sorteio falso, promessa de dinheiro fácil, venda de seguidores e links de aposta.',
            'allow' => 'Link de documentação ou repositório oficial em resposta ao assunto da live, risada longa, comemoração, emote repetido poucas vezes e pergunta sobre o conteúdo do próprio canal.',
        ],
    ],

    'models' => [
        'gpt-6-luna' => ['label' => 'GPT Luna', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'gpt-6-luna', 'input_price' => 0.10, 'output_price' => 0.50],
        'claude-haiku' => ['label' => 'Claude Haiku', 'driver' => 'agent', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'input_price' => 0.10, 'output_price' => 0.50],
        'gpt-6-luna-decisions' => ['label' => 'GPT Luna', 'driver' => 'decision', 'provider' => 'openai', 'model' => 'gpt-6-luna', 'input_price' => 0.10, 'output_price' => 0.0],
        'jev' => ['label' => 'Jev', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest', 'input_price' => 0.042, 'output_price' => 0.0],
        'laya' => ['label' => 'Laya base (local)', 'driver' => 'decision', 'provider' => 'laya', 'model' => 'multilingual', 'input_price' => 0.0, 'output_price' => 0.0],
        'laya-treinado' => ['label' => 'Laya treinado (local)', 'driver' => 'decision', 'provider' => 'laya', 'model' => 'moderador', 'input_price' => 0.0, 'output_price' => 0.0],
        'openai-moderation' => ['label' => 'OpenAI Moderation', 'driver' => 'moderation', 'provider' => 'openai', 'model' => 'omni-moderation-latest', 'input_price' => 0.0, 'output_price' => 0.0],
    ],

];
