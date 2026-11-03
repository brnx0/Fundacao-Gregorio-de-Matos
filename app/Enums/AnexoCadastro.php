<?php

namespace App\Enums;

/**
 * Arquivos que a pessoa envia na etapa Anexos do cadastro, na ordem da tela. Cada um é parametrizado na retaguarda
 * (Parametrização > Anexos do cadastro): se a IA confere o arquivo e o que ela verifica.
 */
enum AnexoCadastro: string
{
    case FotoPerfil = 'foto_perfil';
    case DocumentoCpf = 'documento_cpf';
    case DocumentoRg = 'documento_rg';
    case DocumentoPassaporte = 'documento_passaporte';
    case DocumentoCrnm = 'documento_crnm';
    case DocumentoOutros = 'documento_outros';
    case ComprovanteResidencia = 'comprovante_residencia';

    public function rotulo(): string
    {
        return match ($this) {
            self::FotoPerfil => 'Foto de perfil',
            self::DocumentoCpf => 'Cópia do CPF',
            self::DocumentoRg => 'Cópia do RG',
            self::DocumentoPassaporte => 'Passaporte',
            self::DocumentoCrnm => 'Registro Nacional Migratório (CRNM)',
            self::DocumentoOutros => 'Outros documentos',
            self::ComprovanteResidencia => 'Comprovante de residência',
        };
    }

    /** Sem ele a pessoa não conclui a etapa Anexos. */
    public function obrigatorio(): bool
    {
        return $this === self::DocumentoCpf || $this === self::DocumentoRg;
    }

    /** Só imagem (JPG ou PNG): a foto aparece no perfil. Os documentos aceitam também PDF. */
    public function somenteImagem(): bool
    {
        return $this === self::FotoPerfil;
    }

    /** @return list<string> */
    public function extensoes(): array
    {
        return $this->somenteImagem() ? ['jpg', 'jpeg', 'png'] : ['pdf', 'jpg', 'jpeg', 'png'];
    }

    /**
     * Tipos MIME aceitos no envio: os mesmos que a conferência por IA lê.
     *
     * @return list<string>
     */
    public function tiposDeArquivo(): array
    {
        return $this->somenteImagem() ? ['image/jpeg', 'image/png'] : ['application/pdf', 'image/jpeg', 'image/png'];
    }

    /** O que o arquivo deve ser, para a equipe na retaguarda e para a IA que escreve as instruções. */
    public function descricao(): string
    {
        return match ($this) {
            self::FotoPerfil => 'Foto do rosto da pessoa, mostrada no perfil dela no portal.',
            self::DocumentoCpf => 'Cópia de um documento com o número do CPF da pessoa: o cartão do CPF, o comprovante de situação cadastral da Receita Federal ou um documento de identidade que traga o CPF.',
            self::DocumentoRg => 'Cópia da carteira de identidade da pessoa: o RG ou a nova Carteira de Identidade Nacional (CIN).',
            self::DocumentoPassaporte => 'Cópia da página de identificação do passaporte da pessoa, brasileiro ou estrangeiro.',
            self::DocumentoCrnm => 'Cópia da Carteira de Registro Nacional Migratório (CRNM, antigo RNE), o documento de identidade do imigrante que mora no Brasil.',
            self::DocumentoOutros => 'Outro documento que a pessoa queira anexar ao cadastro (ex.: CNH, carteira de trabalho, registro profissional, DRT).',
            self::ComprovanteResidencia => 'Conta de consumo (água, luz, telefone, internet), contrato de aluguel, correspondência bancária ou declaração de residência com o endereço da pessoa.',
        };
    }

    /**
     * Dados do cadastro que vão para a IA junto com o arquivo: só os que servem para conferir este documento.
     *
     * @return list<string>
     */
    public function dadosConferidos(): array
    {
        return match ($this) {
            self::FotoPerfil => [],
            self::DocumentoCpf => ['nome completo', 'CPF'],
            self::DocumentoOutros => ['nome completo'],
            self::DocumentoRg => ['nome completo', 'número do RG', 'data de nascimento'],
            self::DocumentoPassaporte, self::DocumentoCrnm => ['nome completo', 'data de nascimento', 'país de origem'],
            self::ComprovanteResidencia => ['nome completo', 'endereço'],
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
