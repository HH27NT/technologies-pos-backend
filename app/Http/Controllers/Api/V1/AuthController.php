<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Usuarios\Services\AutenticarUsuarioService;
use App\Domain\Usuarios\Services\RecuperarPasswordService;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RecuperarPasswordRequest;
use App\Http\Resources\UsuarioResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * M01 · Autenticación por Sanctum: login, logout, me.
 */
class AuthController extends ApiController
{
    public function __construct(private readonly PermissionRegistrar $permisos) {}

    public function login(LoginRequest $request, AutenticarUsuarioService $service): JsonResponse
    {
        $resultado = $service->login((string) $request->input('login'), (string) $request->input('password'));
        $usuario = $resultado['usuario'];

        // Fija el team para serializar los roles del usuario recién autenticado.
        $this->permisos->setPermissionsTeamId($usuario->id_establecimiento);

        return ApiResponse::exito([
            'token' => $resultado['token'],
            'token_type' => 'Bearer',
            'usuario' => UsuarioResource::make($usuario)->resolve($request),
        ], 'Sesión iniciada.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::exito(null, 'Sesión cerrada.');
    }

    /**
     * Solicita el envío del enlace de recuperación de contraseña por correo.
     * Responde SIEMPRE de forma genérica para no revelar si el correo existe
     * (anti-enumeración); el estado real del broker no se expone.
     */
    public function recuperar(RecuperarPasswordRequest $request, RecuperarPasswordService $service): JsonResponse
    {
        $service->enviarEnlace((string) $request->input('email'));

        return ApiResponse::exito(
            null,
            'Si el correo está registrado, te enviaremos un enlace para restablecer tu contraseña.'
        );
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return ApiResponse::exito([
            'usuario' => UsuarioResource::make($usuario)->resolve($request),
            'permisos' => $usuario->getAllPermissions()->pluck('name'),
            'es_super_admin' => $usuario->esSuperAdmin(),
        ]);
    }
}
