<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  DB::statement("UPDATE encerramentos_atendimento f SET pesquisa_message_id=(SELECT m.id FROM messages m JOIN tenants t ON t.id=m.tenant_id WHERE m.conversation_id=f.conversation_id AND m.tenant_id=f.tenant_id AND m.direcao='out' AND m.automatica=true AND m.created_at BETWEEN f.pesquisa_enviada_em - INTERVAL '5 seconds' AND f.pesquisa_enviada_em + INTERVAL '5 seconds' AND (m.corpo=? OR m.corpo=t.pesquisa_texto) ORDER BY m.id DESC LIMIT 1) WHERE f.pesquisa_message_id IS NULL AND f.pesquisa_enviada_em IS NOT NULL",[\App\Services\PesquisaDeSatisfacao::TEXTO_PADRAO]);
 }
 public function down(): void {}
};
