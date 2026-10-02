<?php

namespace App\Models;

use App\Notifications\RecuperarPasswordNotification;
use App\Support\Concerns\Auditable;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * DER V1.2 · Tabla 4. Usuarios del sistema.
 * id_establecimiento NULL = super_admin (exento del TenantScope, §7).
 * id_rol es cache del rol principal; la verdad de roles vive en Spatie.
 */
class Usuario extends Authenticatable
{
    use Auditable, BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $table = 'usuarios';

    protected $guarded = ['id'];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'bool',
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    /** La contraseña vive en password_hash, no en la columna por defecto de Laravel. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /** Envía el correo de recuperación con nuestra notificación en español (M01). */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RecuperarPasswordNotification($token));
    }

    /**
     * True si es super_admin de plataforma (no pertenece a ningún establecimiento).
     * Su autorización se concede vía Gate::before (Sprint 1), no por model_has_roles.
     */
    public function esSuperAdmin(): bool
    {
        return $this->id_establecimiento === null;
    }

    public function rol()
    {
        return $this->belongsTo(Role::class, 'id_rol');
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'id_usuario');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'id_usuario');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'id_usuario');
    }

    public function movimientosInventario()
    {
        return $this->hasMany(MovimientoInventario::class, 'id_usuario');
    }

    public function sesionesApertura()
    {
        return $this->hasMany(SesionCaja::class, 'id_usuario_apertura');
    }
}
