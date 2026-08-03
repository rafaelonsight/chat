<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// A midia fica em disco privado. Esta rota e o unico caminho para ela, e o
// escopo global de tenant faz mensagem de outro tenant simplesmente nao existir.
class MediaController extends Controller
{
    public function __invoke(string $message): StreamedResponse
    {
        $mensagem = Message::find($message);

        abort_if(! $mensagem || ! $mensagem->media_path, 404);
        abort_unless(Storage::disk('local')->exists($mensagem->media_path), 404);

        return Storage::disk('local')->response(
            $mensagem->media_path,
            $mensagem->media_nome ?: basename($mensagem->media_path),
            [
                'Content-Type'  => $mensagem->media_mime ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }
}
