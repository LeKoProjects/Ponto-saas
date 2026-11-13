<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biometria do celular (WebAuthn): uma credencial por aparelho liberado.
 * Guarda só a chave PÚBLICA; a digital/rosto nunca sai do celular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('webauthn_credential_id', 512)->nullable()->after('status'); // base64url
            $table->text('webauthn_public_key')->nullable()->after('webauthn_credential_id'); // PEM
            $table->unsignedBigInteger('webauthn_sign_count')->default(0)->after('webauthn_public_key');
            $table->timestampTz('webauthn_registered_at')->nullable()->after('webauthn_sign_count');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['webauthn_credential_id', 'webauthn_public_key', 'webauthn_sign_count', 'webauthn_registered_at']);
        });
    }
};
