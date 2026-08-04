<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportar')
                ->label('Exportar contatos')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportar()),

            CreateAction::make()->label('Novo contato'),
        ];
    }

    /**
     * CSV com ponto e virgula e BOM: e o que o Excel em portugues abre certo. Com
     * virgula e sem BOM, acentos viram lixo e tudo cai numa coluna — e a planilha
     * "quebrada" e culpa do arquivo, nao do usuario.
     */
    private function exportar(): StreamedResponse
    {
        $nomeArquivo = 'contatos-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");

            fputcsv($saida, [
                'Nome', 'Telefone', 'E-mail', 'Instagram', 'CEP', 'Logradouro',
                'Numero', 'Complemento', 'Bairro', 'Cidade', 'UF', 'Etiquetas',
                'Situacao', 'Criado em',
            ], ';');

            Contact::with('tags')->orderBy('nome')->chunk(500, function ($contatos) use ($saida) {
                foreach ($contatos as $c) {
                    fputcsv($saida, [
                        $c->nome, $c->telefone_e164, $c->email, $c->instagram,
                        $c->cep, $c->logradouro, $c->numero, $c->complemento,
                        $c->bairro, $c->cidade, $c->uf,
                        $c->tags->pluck('nome')->join(', '),
                        $c->bloqueado() ? 'bloqueado' : ($c->arquivado() ? 'arquivado' : 'ativo'),
                        $c->created_at?->format('d/m/Y H:i'),
                    ], ';');
                }
            });

            fclose($saida);
        }, $nomeArquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
