<?php

namespace App\Http\Requests\Retaguarda\Concerns;

use Illuminate\Validation\Rules\Enum;

/**
 * Mensagens comuns das telas do edital (o formulário e o questionário). O app tem poucas traduções do Laravel; as
 * mensagens aparecem embaixo de cada campo, sem o nome dele.
 */
trait MensagensDosCampos
{
    /**
     * @return array<string, mixed>
     */
    private function mensagensDosCampos(): array
    {
        return [
            'required' => 'Este campo é obrigatório.',
            'string' => 'Digite um texto.',
            'max' => [
                'string' => 'Use no máximo :max caracteres.',
                'numeric' => 'Informe um valor de no máximo :max.',
                'array' => 'Cadastre no máximo :max itens.',
                'file' => 'O arquivo deve ter no máximo :max KB.',
            ],
            'min' => [
                'numeric' => 'Informe um valor de no mínimo :min.',
            ],
            'integer' => 'Informe um número inteiro.',
            'numeric' => 'Informe um número.',
            'in' => 'Selecione uma opção da lista.',
            Enum::class => 'Selecione uma opção da lista.',
            'distinct' => 'Esta opção foi escolhida mais de uma vez.',
            'boolean' => 'Valor inválido.',
            'array' => 'Valor inválido.',
            'list' => 'Valor inválido.',
            'date' => 'Digite uma data válida.',
        ];
    }
}
