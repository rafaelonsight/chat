<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaService
{
    // Base64 de 32 MB ocupa ~43 MB de string na memoria; com o pool em 256 MB
    // este e o teto seguro para documento. Midia visual fica menor porque o
    // proprio WhatsApp recomprime.
    public const LIMITES_MB = [
        'image'    => 16,
        'video'    => 16,
        'audio'    => 16,
        'sticker'  => 5,
        'document' => 32,
    ];

    private const EXTENSOES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
        'audio/aac' => 'aac', 'audio/wav' => 'wav', 'audio/webm' => 'webm',
        'application/pdf' => 'pdf',
    ];

    public function tipoPorMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        // webp e o formato de figurinha do WhatsApp; tratar como imagem faria a
        // figurinha ser enviada com moldura de foto.
        return match (true) {
            $mime === 'image/webp'            => 'sticker',
            str_starts_with($mime, 'image/')  => 'image',
            str_starts_with($mime, 'video/')  => 'video',
            str_starts_with($mime, 'audio/')  => 'audio',
            default                           => 'document',
        };
    }

    public function guardarBase64(Conversation $conversa, string $base64, string $mime, ?string $nome = null): array
    {
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('conteudo base64 invalido');
        }

        return $this->guardarBytes($conversa, $bytes, $mime, $nome);
    }

    public function guardarUpload(Conversation $conversa, UploadedFile $arquivo): array
    {
        return $this->guardarBytes(
            $conversa,
            (string) $arquivo->get(),
            $this->mimeEfetivo($arquivo),
            $arquivo->getClientOriginalName(),
        );
    }

    // O finfo adivinha pelo conteudo, e um conteiner webm ou ogg com apenas
    // faixa de audio ele chama de video/*. Nota de voz classificada como video
    // vai pelo endpoint errado e chega no cliente sem onda nem play. Quando o
    // navegador declara audio, o ffprobe da a palavra final: se nao existe
    // faixa de video, e audio.
    private function mimeEfetivo(UploadedFile $arquivo): string
    {
        $detectado = strtolower($arquivo->getMimeType() ?: 'application/octet-stream');
        $declarado = strtolower((string) $arquivo->getClientMimeType());

        if (! str_starts_with($declarado, 'audio/')) {
            return $detectado;
        }

        return $this->temFaixaDeVideo($arquivo->getRealPath()) ? $detectado : $declarado;
    }

    private function temFaixaDeVideo(?string $caminho): bool
    {
        if (! $caminho || ! is_file($caminho) || ! $this->temFfprobe()) {
            return false;
        }

        exec(sprintf(
            'ffprobe -v error -select_streams v -show_entries stream=codec_type -of csv=p=0 %s',
            escapeshellarg($caminho)
        ), $saida, $codigo);

        return $codigo === 0 && trim(implode('', $saida)) !== '';
    }

    /**
     * Duracao em segundos, lida do proprio arquivo.
     *
     * A Evolution informa a duracao no payload; a Meta NAO manda. Sem isto, audio que chega
     * pelo canal oficial aparece sem tempo na bolha, e o atendente nao sabe se vai ouvir
     * cinco segundos ou tres minutos antes de dar play — o que muda se ele ouve agora ou
     * depois.
     *
     * Arredonda para CIMA: audio de 0,4 s existe, e mostrar "0s" pareceria defeito.
     */
    public function duracaoSegundos(?string $caminhoAbsoluto): ?int
    {
        if (! $caminhoAbsoluto || ! is_file($caminhoAbsoluto) || ! $this->temFfprobe()) {
            return null;
        }

        // 2>/dev/null: arquivo que nao e midia faz o ffprobe escrever no stderr, e isso
        // vazava para a saida dos testes. Saida de teste com ruido ensina a ignorar saida de
        // teste — e o codigo de retorno ja diz tudo o que precisamos saber aqui.
        exec(sprintf(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($caminhoAbsoluto)
        ), $saida, $codigo);

        if ($codigo !== 0) {
            return null;
        }

        $segundos = (float) trim(implode('', $saida));

        // Container sem duracao declarada devolve 0 ou "N/A". null e melhor que "0s" na
        // tela: ausencia de informacao nao deve se disfarcar de informacao.
        return $segundos > 0 ? (int) ceil($segundos) : null;
    }

    public function temFfprobe(): bool
    {
        exec('which ffprobe', $s, $c);

        return $c === 0;
    }

    /**
     * Publico porque a API oficial entrega BYTES, nao base64.
     *
     * Passar por base64 so para reaproveitar o guardarBase64 significaria inflar 33% um
     * video de 16 MB na memoria, codificar e decodificar de volta, para nada.
     */
    public function guardarBytes(Conversation $conversa, string $bytes, string $mime, ?string $nome): array
    {
        $tipo = $this->tipoPorMime($mime);
        $limite = (self::LIMITES_MB[$tipo] ?? 16) * 1024 * 1024;
        $tamanho = strlen($bytes);

        if ($tamanho > $limite) {
            throw new RuntimeException(sprintf(
                'Arquivo de %s tem %s MB e o limite e %d MB.',
                $tipo, number_format($tamanho / 1048576, 1), self::LIMITES_MB[$tipo] ?? 16
            ));
        }

        $path = sprintf(
            'media/%d/%d/%s.%s',
            $conversa->tenant_id,
            $conversa->id,
            Str::uuid(),
            $this->extensao($mime, $nome),
        );

        Storage::disk('local')->put($path, $bytes);

        return [
            'path'    => $path,
            'tipo'    => $tipo,
            'mime'    => strtolower(trim(explode(';', $mime)[0])),
            'nome'    => $nome,
            'tamanho' => $tamanho,
        ];
    }

    private function extensao(string $mime, ?string $nome): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if (isset(self::EXTENSOES[$mime])) {
            return self::EXTENSOES[$mime];
        }

        $doNome = $nome ? pathinfo($nome, PATHINFO_EXTENSION) : '';

        return preg_match('/^[a-z0-9]{1,8}$/i', $doNome) ? strtolower($doNome) : 'bin';
    }

    // O WhatsApp so mostra onda e play para OGG/Opus. Qualquer outro formato
    // chega como arquivo anexado — funciona, mas nao parece nota de voz.
    public function converterParaVoz(string $path): string
    {
        $disco = Storage::disk('local');

        if (! $this->temFfmpeg()) {
            return $path;
        }

        $origem = $disco->path($path);
        $destinoRel = preg_replace('/\.[^.]+$/', '', $path).'-voz.ogg';
        $destino = $disco->path($destinoRel);

        @mkdir(dirname($destino), 0775, true);

        $cmd = sprintf(
            'ffmpeg -y -i %s -vn -c:a libopus -b:a 32k -ar 48000 -ac 1 %s 2>&1',
            escapeshellarg($origem),
            escapeshellarg($destino)
        );

        exec($cmd, $saida, $codigo);

        if ($codigo !== 0 || ! is_file($destino) || filesize($destino) === 0) {
            return $path; // conversao falhou: manda o original em vez de nao mandar
        }

        return $destinoRel;
    }

    public function temFfmpeg(): bool
    {
        exec('which ffmpeg', $s, $c);

        return $c === 0;
    }
}
