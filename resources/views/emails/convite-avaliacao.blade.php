Olá, {!! $nome !!}!

Você foi escolhido(a) para avaliar inscrições no portal da Fundação Gregório de Mattos:

@foreach ($editais as $edital)
- {!! $edital['titulo'] !!} (avaliação: {!! $edital['periodo'] !!})
@endforeach

Para começar, abra o seu link:

{!! $link !!}

Ao abrir, confirme o seu CPF.

Este link é pessoal: não o encaminhe.
