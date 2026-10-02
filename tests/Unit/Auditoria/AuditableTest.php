<?php

namespace Tests\Unit\Auditoria;

use App\Models\Usuario;
use Tests\TestCase;

/**
 * S12 · El diff de auditoría (Auditable) nunca filtra secretos (§540/§15). Es el
 * mecanismo que usa el subscriber de usuarios para `datos_antes`/`datos_despues`.
 */
class AuditableTest extends TestCase
{
    public function test_filtra_los_campos_sensibles_del_diff(): void
    {
        $atributos = [
            'nombre' => 'Ada',
            'email' => 'ada@pos.test',
            'password_hash' => 'secreto',
            'remember_token' => 'token',
            'updated_at' => '2026-07-01 00:00:00',
        ];

        $filtrado = (new Usuario)->filtrarParaAuditoria($atributos);

        $this->assertArrayNotHasKey('password_hash', $filtrado);
        $this->assertArrayNotHasKey('remember_token', $filtrado);
        $this->assertArrayNotHasKey('updated_at', $filtrado);
        $this->assertSame('Ada', $filtrado['nombre']);
        $this->assertSame('ada@pos.test', $filtrado['email']);
    }
}
