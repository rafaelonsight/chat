<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Fala com o whisper.cpp rodando em 127.0.0.1 como servico do systemd. O modelo
// fica residente na RAM: sem isso cada audio pagaria ~8s so para ler os 182 MB
// do disco. Duas threads no servidor — nunca os 6 nucleos, porque esta maquina
// hospeda producao de terceiros.
class TranscriptionService
{
    public function __construct(
        private readonly bool $ativa,
        private readonly string $url,
        private readonly int $maxSegundos,
        private readonly string $vocabulario,
    ) {}

    public function ativa(): bool
    {
        return $this->ativa;
    }

    /**
     * @return array{status: string, texto: ?string, erro: ?string}
     */
    public function transcrever(string $mediaPath): array
    {
        if (! $this->ativa) {
            return $this->resultado('ignorada', null, 'transcrição desligada');
        }

        $disco = Storage::disk('local');

        if (! $disco->exists($mediaPath)) {
            return $this->resultado('falhou', null, 'arquivo não encontrado');
        }

        $origem = $disco->path($mediaPath);
        $duracao = $this->duracao($origem);

        if ($duracao !== null && $duracao > $this->maxSegundos) {
            return $this->resultado('ignorada', null, sprintf(
                'áudio de %ds acima do teto de %ds', (int) $duracao, $this->maxSegundos
            ));
        }

        $wav = $this->paraWav($origem);

        if (! $wav) {
            return $this->resultado('falhou', null, 'conversão para wav falhou');
        }

        try {
            $resposta = Http::timeout(240)
                ->attach('file', (string) file_get_contents($wav), 'audio.wav', ['Content-Type' => 'audio/wav'])
                ->post(rtrim($this->url, '/').'/inference', [
                    'language'        => 'pt',
                    'response_format' => 'json',
                    'temperature'     => '0.0',
                    // Dica de vocabulario: sem ela "a ONU esta piscando" vira
                    // "a onda esta piscando".
                    'prompt'          => $this->vocabulario,
                ]);

            if ($resposta->failed()) {
                return $this->resultado('falhou', null, 'servidor devolveu '.$resposta->status());
            }

            $texto = trim((string) ($resposta->json('text') ?? ''));

            if ($texto === '') {
                return $this->resultado('falhou', null, 'transcrição vazia');
            }

            return $this->resultado('pronta', $texto, null);
        } catch (\Throwable $e) {
            return $this->resultado('falhou', null, mb_substr($e->getMessage(), 0, 200));
        } finally {
            @unlink($wav);
        }
    }

    private function resultado(string $status, ?string $texto, ?string $erro): array
    {
        return ['status' => $status, 'texto' => $texto, 'erro' => $erro];
    }

    private function duracao(string $caminho): ?float
    {
        exec(sprintf(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 %s',
            escapeshellarg($caminho)
        ), $saida, $codigo);

        if ($codigo !== 0 || ! isset($saida[0]) || ! is_numeric(trim($saida[0]))) {
            return null;
        }

        return (float) trim($saida[0]);
    }

    // 16 kHz mono PCM: o formato que o whisper quer. Convertemos do nosso lado
    // de proposito — o --convert do whisper-server quebra sob o sandbox do
    // systemd, e aqui a conversao fica sob nosso controle.
    private function paraWav(string $origem): ?string
    {
        $destino = sys_get_temp_dir().'/onchat-transc-'.bin2hex(random_bytes(6)).'.wav';

        exec(sprintf(
            'nice -n 15 ffmpeg -y -i %s -vn -ar 16000 -ac 1 -c:a pcm_s16le %s 2>/dev/null',
            escapeshellarg($origem),
            escapeshellarg($destino)
        ), $s, $codigo);

        if ($codigo !== 0 || ! is_file($destino) || filesize($destino) === 0) {
            @unlink($destino);

            return null;
        }

        return $destino;
    }
}
