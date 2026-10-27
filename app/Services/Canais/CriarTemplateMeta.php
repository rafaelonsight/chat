<?php
namespace App\Services\Canais;

use App\Models\{Channel, MetaTemplate};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CriarTemplateMeta
{
    public function preparar(array $dados): array
    {
        $canal = Channel::find($dados['channel_id'] ?? null);
        if (!$canal || $canal->tipo !== Channel::META_CLOUD || !$canal->meta_waba_id || $canal->tenant_id !== TenantContext::get()) {
            throw ValidationException::withMessages(['channel_id' => __('Selecione um canal oficial desta conta.')]);
        }
        $nome = trim($dados['nome'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{0,511}$/', $nome)) {
            throw ValidationException::withMessages(['nome' => __('Use letras minúsculas, números e sublinhado.')]);
        }
        $idioma = $dados['idioma'] ?? '';
        $categoria = $dados['categoria'] ?? '';
        if (!in_array($idioma, ['en_GB', 'pt_BR'], true) || !in_array($categoria, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
            throw ValidationException::withMessages(['categoria' => __('Categoria ou idioma inválido.')]);
        }
        $componentes = [];
        if ($categoria === 'AUTHENTICATION') {
            $componentes = [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => $idioma === 'en_GB' ? 'Copy code' : 'Copiar código']]],
            ];
            $analise = ['corpo' => $idioma === 'en_GB' ? '{{1}} is your verification code.' : '{{1}} é seu código de verificação.', 'cabecalho' => null, 'rodape' => null, 'variaveis' => 1, 'suportado' => false, 'motivo_nao_suportado' => 'Código de autenticação: envio pela integração de verificação'];
        } else {
            $corpo = trim($dados['corpo'] ?? '');
            if (!$corpo || mb_strlen($corpo) > 1024) {
                throw ValidationException::withMessages(['corpo' => __('Digite uma mensagem com até 1024 caracteres.')]);
            }
            if (trim($dados['cabecalho'] ?? '') !== '') {
                if (mb_strlen($dados['cabecalho']) > 60 || str_contains($dados['cabecalho'], '{{')) {
                    throw ValidationException::withMessages(['cabecalho' => __('O cabeçalho deve ter até 60 caracteres e não usar variáveis.')]);
                }
                $componentes[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => trim($dados['cabecalho'])];
            }
            $body = ['type' => 'BODY', 'text' => $corpo];
            $analise = MetaTemplate::analisar(['components' => [$body]]);
            if (!$analise['suportado']) {
                throw ValidationException::withMessages(['corpo' => $analise['motivo_nao_suportado']]);
            }
            $exemplos = array_values(array_filter(array_map('trim', explode("\n", $dados['exemplos'] ?? '')), fn($v) => $v !== ''));
            if ($analise['variaveis'] !== count($exemplos)) {
                throw ValidationException::withMessages(['exemplos' => __('Preencha um exemplo por variável, na ordem da mensagem.')]);
            }
            if ($exemplos) {
                $modelo = new MetaTemplate($analise);
                $nomes = $modelo->nomesParametros();
                $body['example'] = $nomes ? ['body_text_named_params' => array_map(fn($nome, $valor) => ['param_name' => $nome, 'example' => $valor], $nomes, $exemplos)] : ['body_text' => [$exemplos]];
            }
            $componentes[] = $body;
            if (trim($dados['rodape'] ?? '') !== '') {
                if (mb_strlen($dados['rodape']) > 60 || str_contains($dados['rodape'], '{{')) {
                    throw ValidationException::withMessages(['rodape' => __('O rodapé deve ter até 60 caracteres e não usar variáveis.')]);
                }
                $componentes[] = ['type' => 'FOOTER', 'text' => trim($dados['rodape'])];
            }
            $botoes = [];
            foreach (($dados['botoes'] ?? []) as $botao) {
                $tipo = $botao['tipo'] ?? ''; $texto = trim($botao['texto'] ?? '');
                if (!in_array($tipo, ['QUICK_REPLY','URL','PHONE_NUMBER'], true) || !$texto || mb_strlen($texto) > 25) {
                    throw ValidationException::withMessages(['botoes' => __('Verifique o tipo e o texto dos botões.')]);
                }
                $item = ['type' => $tipo, 'text' => $texto];
                if ($tipo === 'URL') {
                    $url = trim($botao['valor'] ?? '');
                    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//', $url) || str_contains($url, '{{')) {
                        throw ValidationException::withMessages(['botoes' => __('Use um endereço http ou https fixo.')]);
                    }
                    $item['url'] = $url;
                }
                if ($tipo === 'PHONE_NUMBER') {
                    if (!preg_match('/^\+[1-9]\d{6,14}$/', $botao['valor'] ?? '')) throw ValidationException::withMessages(['botoes' => __('Use o telefone com + e código do país.')]);
                    $item['phone_number'] = $botao['valor'];
                }
                $botoes[] = $item;
            }
            if (count($botoes) > 3) throw ValidationException::withMessages(['botoes' => __('Use até três botões.')]);
            if ($botoes) $componentes[] = ['type' => 'BUTTONS', 'buttons' => $botoes];
            $analise = MetaTemplate::analisar(['components' => $componentes]);
        }
        return array_merge($analise, ['tenant_id' => $canal->tenant_id, 'meta_waba_id' => $canal->meta_waba_id, 'nome' => $nome, 'idioma' => $idioma, 'categoria' => $categoria, 'status' => 'DRAFT', 'componentes' => $componentes]);
    }

    public function solicitar(MetaTemplate $modelo): array
    {
        abort_unless(auth()->user()?->admin && $modelo->tenant_id === TenantContext::get(), 403);
        return Cache::lock('meta-template-submeter-'.$modelo->id, 120)->block(2, function () use ($modelo) {
            $modelo->refresh();
            if ($modelo->status !== 'DRAFT') return ['ok' => false, 'erro' => __('Este template já foi solicitado. Sincronize o estado antes de continuar.')];
            $canal = Channel::where('tipo', Channel::META_CLOUD)->where('meta_waba_id', $modelo->meta_waba_id)->first();
            if (!$canal) return ['ok' => false, 'erro' => __('O canal oficial não está disponível.')];
            // Conferir antes do POST também recupera uma resposta perdida de uma tentativa anterior.
            $meta = app(MetaCloudEnviador::class);
            $lista = $meta->templates($canal);
            if (!$lista['ok']) return $lista;
            foreach ($lista['templates'] as $item) {
                if (($item['name'] ?? '') === $modelo->nome && ($item['language'] ?? '') === $modelo->idioma) {
                    app(SincronizarTemplatesMeta::class)->paraCanal($canal);
                    return ['ok' => true, 'recuperado' => true];
                }
            }
            $r = $meta->criarTemplate($canal, $modelo);
            if ($r['ok']) $modelo->update(['meta_id' => $r['id'], 'status' => $r['status'], 'categoria' => $r['categoria'] ?? $modelo->categoria, 'sincronizado_em' => now()]);
            return $r;
        });
    }
}
