<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Establecimientos\Services\ActualizarEstablecimientoService;
use App\Domain\Establecimientos\Services\AsignarAdminService;
use App\Domain\Establecimientos\Services\CambiarEstadoEstablecimientoService;
use App\Domain\Establecimientos\Services\CrearEstablecimientoService;
use App\Domain\Establecimientos\Services\RestablecerAccesoAdminService;
use App\Http\Requests\ActualizarEstablecimientoRequest;
use App\Http\Requests\AsignarAdminRequest;
use App\Http\Requests\CrearEstablecimientoRequest;
use App\Http\Requests\RestablecerAccesoRequest;
use App\Http\Resources\EstablecimientoResource;
use App\Http\Resources\UsuarioResource;
use App\Models\Establecimiento;
use App\Models\Usuario;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * M02 · Administración de plataforma (solo SUPER_ADMIN, vía EstablecimientoPolicy).
 */
class EstablecimientoController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Establecimiento::class);

        $paginador = Establecimiento::query()->with('configuracion')->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, EstablecimientoResource::class);
    }

    public function store(CrearEstablecimientoRequest $request, CrearEstablecimientoService $service): JsonResponse
    {
        $this->authorize('create', Establecimiento::class);

        $resultado = $service->crear($request->validated());
        $establecimiento = $resultado['establecimiento']->load('configuracion');

        return ApiResponse::creado(
            EstablecimientoResource::make($establecimiento),
            'Establecimiento creado correctamente.'
        );
    }

    public function show(int $id): JsonResponse
    {
        $establecimiento = Establecimiento::with('configuracion')->findOrFail($id);
        $this->authorize('view', $establecimiento);

        return ApiResponse::exito(EstablecimientoResource::make($establecimiento));
    }

    public function update(ActualizarEstablecimientoRequest $request, int $id, ActualizarEstablecimientoService $service): JsonResponse
    {
        $establecimiento = Establecimiento::findOrFail($id);
        $this->authorize('update', $establecimiento);

        $service->actualizar($establecimiento, $request->validated());

        return ApiResponse::exito(
            EstablecimientoResource::make($establecimiento->load('configuracion')),
            'Establecimiento actualizado.'
        );
    }

    public function activar(Request $request, int $id, CambiarEstadoEstablecimientoService $service): JsonResponse
    {
        $establecimiento = Establecimiento::findOrFail($id);
        $this->authorize('toggle', $establecimiento);

        $activo = $request->has('activo') ? $request->boolean('activo') : ! $establecimiento->activo;
        $service->cambiar($establecimiento, $activo);

        return ApiResponse::exito(
            EstablecimientoResource::make($establecimiento),
            $activo ? 'Establecimiento activado.' : 'Establecimiento desactivado.'
        );
    }

    public function asignarAdmin(AsignarAdminRequest $request, int $id, AsignarAdminService $service): JsonResponse
    {
        $establecimiento = Establecimiento::findOrFail($id);
        $this->authorize('asignarAdmin', $establecimiento);

        $usuario = Usuario::where('id_establecimiento', $establecimiento->id)
            ->findOrFail($request->integer('id_usuario'));

        $service->asignar($establecimiento, $usuario);

        return ApiResponse::exito(UsuarioResource::make($usuario), 'Administrador asignado.');
    }

    /**
     * Lista los usuarios con rol admin de un establecimiento. Alimenta el selector
     * del rescate de acceso (super_admin): elegir de una lista, nunca un id a mano.
     */
    public function administradores(int $id): JsonResponse
    {
        $establecimiento = Establecimiento::findOrFail($id);
        $this->authorize('view', $establecimiento);

        $idRolAdmin = $this->idRolAdmin($establecimiento->id);

        $admins = $idRolAdmin === null
            ? collect()
            : Usuario::where('id_establecimiento', $establecimiento->id)
                ->where('id_rol', $idRolAdmin)
                ->get();

        return ApiResponse::exito(UsuarioResource::collection($admins)->resolve());
    }

    /**
     * Rescate de plataforma (M02): genera una contraseña temporal para un admin
     * bloqueado y la devuelve una sola vez. El service valida que el objetivo sea
     * admin de este establecimiento y audita la acción.
     */
    public function restablecerAcceso(
        RestablecerAccesoRequest $request,
        int $id,
        RestablecerAccesoAdminService $service,
    ): JsonResponse {
        $establecimiento = Establecimiento::findOrFail($id);
        $this->authorize('restablecerAcceso', $establecimiento);

        $usuario = Usuario::where('id_establecimiento', $establecimiento->id)
            ->findOrFail($request->integer('id_usuario'));

        $temporal = $service->restablecer($establecimiento, $usuario);

        return ApiResponse::exito(
            [
                'password_temporal' => $temporal,
                'usuario' => UsuarioResource::make($usuario)->resolve(),
            ],
            'Acceso restablecido. Comparte la contraseña temporal con el administrador.',
        );
    }

    /** id del rol Spatie `admin` del team (establecimiento), o null si aún no existe. */
    private function idRolAdmin(int $idEstablecimiento): ?int
    {
        return Role::query()
            ->where('name', 'admin')
            ->where('guard_name', Guard::getDefaultName(Usuario::class))
            ->where('id_establecimiento', $idEstablecimiento)
            ->value('id');
    }
}
