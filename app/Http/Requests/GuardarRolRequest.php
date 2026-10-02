<?php

namespace App\Http\Requests;

use App\Domain\Usuarios\CatalogoPermisos;
use App\Models\Rol;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * M04 · Alta y edición de un rol a medida del establecimiento (editor de roles).
 *
 * Además de la forma, aplica la CONTENCIÓN DE PRIVILEGIOS: nadie puede otorgar a un rol
 * un permiso que él mismo no tiene. Sin esta regla el editor sería una vía de escalada —
 * bastaría crear un rol con `usuarios.gestionar_admins` y asignárselo— y dejaría sin
 * efecto la salvaguarda anti-escalada de UsuarioPolicy.
 *
 * La autorización de "quién puede usar el editor" (`roles.gestionar`, solo admin) vive
 * en RolPolicy; esto es la segunda capa, sobre el CONTENIDO de lo que se otorga.
 */
class GuardarRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        $rolId = $this->route('id');

        return [
            'etiqueta' => [
                'required', 'string', 'max:60',
                // La etiqueta es lo que el personal ve al elegir rol: dos "Cajero" en el
                // mismo bar hacen imposible saber cuál asignar.
                Rule::unique('roles', 'etiqueta')
                    ->where(fn ($q) => $q->where('id_establecimiento', $tenant))
                    ->ignore($rolId),
            ],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'permisos' => ['required', 'array', 'min:1'],
            'permisos.*' => ['string', Rule::in(CatalogoPermisos::asignables())],
            // Solo en el alta: rol de origen a clonar (típicamente un preset).
            'clonar_de' => [
                'nullable', 'integer',
                Rule::exists('roles', 'id')->where(fn ($q) => $q->where('id_establecimiento', $tenant)),
            ],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador) {
            $this->validarContencionDePrivilegios($validador);
            $this->validarEtiquetaNoChocaConPreset($validador);
        });
    }

    /**
     * No puedes otorgar lo que no tienes. Regla estándar de RBAC y el candado que impide
     * que el editor se use para fabricarse autoridad.
     */
    private function validarContencionDePrivilegios(Validator $validador): void
    {
        $actor = $this->user();
        $solicitados = (array) $this->input('permisos', []);

        $excedidos = array_values(array_filter(
            $solicitados,
            fn ($permiso) => is_string($permiso) && ! $actor->can($permiso),
        ));

        if ($excedidos === []) {
            return;
        }

        $etiquetas = array_map(
            fn (string $permiso) => CatalogoPermisos::PERMISOS[$permiso][1] ?? $permiso,
            $excedidos,
        );

        $validador->errors()->add(
            'permisos',
            'No puedes otorgar permisos que tú no tienes: '.implode(', ', $etiquetas).'.',
        );
    }

    /**
     * Impide que un rol a medida se haga pasar por uno del sistema ("Administrador"),
     * lo que confundiría a quien asigna roles sobre quién tiene autoridad real.
     */
    private function validarEtiquetaNoChocaConPreset(Validator $validador): void
    {
        $etiqueta = mb_strtolower(trim((string) $this->input('etiqueta')));

        $reservadas = Rol::withoutGlobalScopes()
            ->whereNull('id_establecimiento')
            ->pluck('name')
            ->map(fn ($nombre) => mb_strtolower((string) $nombre))
            ->all();

        // También las etiquetas en español con las que la UI nombra a los presets.
        $reservadas = array_merge($reservadas, ['administrador', 'gerente', 'operador', 'mesero', 'super administrador']);

        if (in_array($etiqueta, $reservadas, true)) {
            $validador->errors()->add('etiqueta', 'Ese nombre está reservado para un rol del sistema. Elige otro.');
        }
    }

    public function messages(): array
    {
        return [
            'etiqueta.required' => 'Ponle un nombre al rol.',
            'etiqueta.unique' => 'Ya existe un rol con ese nombre.',
            'permisos.required' => 'Selecciona al menos un permiso.',
            'permisos.min' => 'Selecciona al menos un permiso.',
            'permisos.*.in' => 'Uno de los permisos seleccionados no es válido.',
        ];
    }
}
