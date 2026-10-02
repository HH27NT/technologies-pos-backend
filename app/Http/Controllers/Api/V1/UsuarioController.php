<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Usuarios\Services\ActualizarUsuarioService;
use App\Domain\Usuarios\Services\AsignarRolService;
use App\Domain\Usuarios\Services\CambiarEstadoUsuarioService;
use App\Domain\Usuarios\Services\CrearUsuarioService;
use App\Http\Requests\ActualizarUsuarioRequest;
use App\Http\Requests\AsignarRolRequest;
use App\Http\Requests\CrearUsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Usuario;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M04 · Usuarios del establecimiento (ADMIN). El aislamiento por tenant lo garantiza
 * el TenantScope: cualquier id de otro tenant responde 404.
 */
class UsuarioController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Usuario::class);

        $paginador = Usuario::query()->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, UsuarioResource::class);
    }

    public function store(CrearUsuarioRequest $request, CrearUsuarioService $service): JsonResponse
    {
        $this->authorize('create', Usuario::class);

        $usuario = $service->crear($request->validated());

        return ApiResponse::creado(UsuarioResource::make($usuario), 'Usuario creado correctamente.');
    }

    public function show(int $id): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);
        $this->authorize('view', $usuario);

        return ApiResponse::exito(UsuarioResource::make($usuario));
    }

    public function update(ActualizarUsuarioRequest $request, int $id, ActualizarUsuarioService $service): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);
        $this->authorize('update', $usuario);

        $service->actualizar($usuario, $request->validated());

        return ApiResponse::exito(UsuarioResource::make($usuario), 'Usuario actualizado.');
    }

    public function activar(Request $request, int $id, CambiarEstadoUsuarioService $service): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);
        $this->authorize('cambiarEstado', $usuario);

        $activo = $request->has('activo') ? $request->boolean('activo') : ! $usuario->activo;
        $service->cambiar($usuario, $activo);

        return ApiResponse::exito(
            UsuarioResource::make($usuario),
            $activo ? 'Usuario activado.' : 'Usuario desactivado.'
        );
    }

    public function rol(AsignarRolRequest $request, int $id, AsignarRolService $service): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);
        $this->authorize('asignarRol', $usuario);

        $service->asignar($usuario, (string) $request->input('rol'));

        return ApiResponse::exito(UsuarioResource::make($usuario), 'Rol actualizado.');
    }
}
