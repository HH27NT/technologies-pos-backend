<?php

namespace App\Http\Requests;

use App\Domain\Usuarios\CatalogoRoles;
use App\Support\Validation\PoliticaPassword;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M02 · Alta de establecimiento + su ADMIN inicial + personal adicional opcional
 * (`personal`: gerente/operador/mesero). La autorización (super_admin) la resuelve
 * la policy en el controller.
 */
class CrearEstablecimientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Roles que se pueden dar de alta como "personal" en este mismo formulario:
        // todos los de tenant MENOS admin (el admin ya tiene su propio bloque, y solo
        // puede haber un admin inicial).
        $rolesPersonal = array_values(array_diff(CatalogoRoles::nombresTenant(), [CatalogoRoles::ROL_ADMIN]));

        return [
            'nombre' => ['required', 'string', 'max:150'],
            'razon_social' => ['nullable', 'string', 'max:150'],
            'rfc' => ['nullable', 'string', 'min:12', 'max:13'],
            'direccion' => ['nullable', 'string'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'zona_horaria' => ['nullable', 'string', 'max:50'],
            'moneda' => ['nullable', 'string', 'size:3'],
            'activo' => ['nullable', 'boolean'],

            'admin' => ['required', 'array'],
            'admin.nombre' => ['required', 'string', 'max:100'],
            'admin.email' => ['nullable', 'required_without:admin.username', 'email', 'max:150'],
            'admin.username' => ['nullable', 'required_without:admin.email', 'string', 'max:50'],
            'admin.password' => ['required', 'string', PoliticaPassword::regla()],

            // Personal adicional (opcional): el resto del equipo de una, sin tener que
            // pasar por "Usuarios" uno por uno después de crear el establecimiento.
            'personal' => ['sometimes', 'array'],
            'personal.*.rol' => ['required', Rule::in($rolesPersonal)],
            'personal.*.nombre' => ['required', 'string', 'max:100'],
            'personal.*.email' => ['nullable', 'required_without:personal.*.username', 'email', 'max:150'],
            'personal.*.username' => ['nullable', 'required_without:personal.*.email', 'string', 'max:50'],
            'personal.*.password' => ['required', 'string', PoliticaPassword::regla()],
        ];
    }

    /**
     * El establecimiento aún no existe al validar, así que `Rule::unique` no puede
     * revisar duplicados aquí (no hay `id_establecimiento` contra el cual comparar).
     * Se revisa a mano que el admin y el personal del mismo payload no se repitan
     * entre sí: si no, el segundo `Usuario::save()` del servicio truena contra el
     * índice único de la BD como 500, no como un 422 legible.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $vistos = ['email' => [], 'username' => []];

            $personas = array_merge([$this->input('admin', [])], $this->input('personal', []));

            foreach ($personas as $indice => $persona) {
                foreach (['email', 'username'] as $campo) {
                    $valor = $persona[$campo] ?? null;
                    if ($valor === null || $valor === '') {
                        continue;
                    }

                    $clave = mb_strtolower($valor);
                    if (in_array($clave, $vistos[$campo], true)) {
                        $ruta = $indice === 0 ? "admin.$campo" : 'personal.'.($indice - 1).".$campo";
                        $validator->errors()->add($ruta, "Ese $campo ya se usó para otra persona de esta alta.");
                    }
                    $vistos[$campo][] = $clave;
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'rfc.min' => 'RFC inválido.',
            'rfc.max' => 'RFC inválido.',
            'email.email' => 'Correo inválido.',
            'admin.required' => 'Asigna un administrador.',
            'admin.email.required_without' => 'Asigna un administrador.',
            'admin.username.required_without' => 'Asigna un administrador.',
            'admin.password.required' => 'La contraseña no cumple los requisitos.',
            'personal.*.rol.required' => 'Selecciona el rol de cada colaborador.',
            'personal.*.rol.in' => 'Rol inválido.',
            'personal.*.nombre.required' => 'El nombre de cada colaborador es obligatorio.',
            'personal.*.email.required_without' => 'Cada colaborador necesita correo o usuario.',
            'personal.*.username.required_without' => 'Cada colaborador necesita correo o usuario.',
            'personal.*.password.required' => 'La contraseña de cada colaborador no cumple los requisitos.',
        ];
    }
}
