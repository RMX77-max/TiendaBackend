<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControladorCajas extends Controller
{
    public function obtenerFormulario(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        return response()->json([
            'monedas' => Caja::obtenerMonedasDisponibles(),
            'metodos' => Caja::obtenerMetodosDisponibles(),
            'sucursales' => $this->obtenerSucursalesSegunRol($usuario),
            'puede_gestionar' => $this->puedeGestionarCajas($usuario),
        ]);
    }

    public function listar(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! $this->puedeVerCajas($usuario)) {
            return response()->json([
                'message' => 'Tu rol no tiene permiso para ver cajas.',
            ], 403);
        }

        $consulta = Caja::query()
            ->with('sucursal')
            ->withSum([
                'movimientos as total_ingresos' => fn ($query) => $query
                    ->where('tipo_movimiento', MovimientoCaja::TIPO_INGRESO),
            ], 'monto')
            ->withSum([
                'movimientos as total_egresos' => fn ($query) => $query
                    ->where('tipo_movimiento', MovimientoCaja::TIPO_EGRESO),
            ], 'monto')
            ->latest();

        if ($usuario->rol === User::ROL_SUPERVISOR_SUCURSAL) {
            $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

            if ($sucursalUsuario) {
                $consulta->where('sucursal_id', $sucursalUsuario->id);
            } else {
                $consulta->whereRaw('1 = 0');
            }
        }

        $cajas = $consulta->get()->map(
            fn (Caja $caja) => $this->formatearCaja($caja)
        )->values();

        $movimientos = MovimientoCaja::query()
            ->with(['caja', 'sucursal', 'usuario'])
            ->when(
                $usuario->rol === User::ROL_SUPERVISOR_SUCURSAL,
                function ($query) use ($usuario) {
                    $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);
                    if ($sucursalUsuario) {
                        $query->where('sucursal_id', $sucursalUsuario->id);
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                }
            )
            ->latest('fecha_movimiento')
            ->limit(25)
            ->get()
            ->map(fn (MovimientoCaja $movimiento) => $this->formatearMovimiento($movimiento))
            ->values();

        return response()->json([
            'cajas' => $cajas,
        ]);
    }

    public function listarMovimientos(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! $this->puedeVerCajas($usuario)) {
            return response()->json([
                'message' => 'Tu rol no tiene permiso para ver movimientos de caja.',
            ], 403);
        }

        $datos = $solicitud->validate([
            'caja_id' => ['nullable', 'integer'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'concepto' => ['nullable', 'string', 'max:50'],
        ]);

        $consulta = MovimientoCaja::query()
            ->with(['caja', 'sucursal', 'usuario'])
            ->latest('fecha_movimiento');

        if ($usuario->rol === User::ROL_SUPERVISOR_SUCURSAL) {
            $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);
            if ($sucursalUsuario) {
                $consulta->where('sucursal_id', $sucursalUsuario->id);
            } else {
                $consulta->whereRaw('1 = 0');
            }
        }

        if (filled($datos['caja_id'] ?? null)) {
            $consulta->where('caja_id', (int) $datos['caja_id']);
        }

        if (filled($datos['fecha_desde'] ?? null)) {
            $consulta->whereDate('fecha_movimiento', '>=', $datos['fecha_desde']);
        }

        if (filled($datos['fecha_hasta'] ?? null)) {
            $consulta->whereDate('fecha_movimiento', '<=', $datos['fecha_hasta']);
        }

        if (filled($datos['concepto'] ?? null)) {
            $consulta->where('concepto', trim((string) $datos['concepto']));
        }

        return response()->json([
            'movimientos' => $consulta
                ->limit(100)
                ->get()
                ->map(fn (MovimientoCaja $movimiento) => $this->formatearMovimiento($movimiento))
                ->values(),
        ]);
    }

    public function registrar(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! $this->puedeGestionarCajas($usuario)) {
            return response()->json([
                'message' => 'Solo los supervisores pueden registrar cajas.',
            ], 403);
        }

        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        if (! $sucursalUsuario) {
            return response()->json([
                'message' => 'Tu usuario no tiene una sucursal valida asignada.',
            ], 422);
        }

        $datos = $solicitud->validate([
            'nombre' => [
                'required',
                'string',
                'max:120',
                Rule::unique('cajas', 'nombre')->where(
                    fn ($query) => $query->where('sucursal_id', $sucursalUsuario->id)
                ),
            ],
            'codigo' => ['nullable', 'string', 'max:80'],
            'tipo_moneda' => ['required', 'string', Rule::in(array_column(Caja::obtenerMonedasDisponibles(), 'value'))],
            'metodo_base' => ['required', 'string', Rule::in(array_column(Caja::obtenerMetodosDisponibles(), 'value'))],
            'observaciones' => ['nullable', 'string', 'max:255'],
        ]);

        $caja = Caja::query()->create([
            'sucursal_id' => $sucursalUsuario->id,
            'nombre' => trim($datos['nombre']),
            'codigo' => filled($datos['codigo'] ?? null) ? trim((string) $datos['codigo']) : null,
            'tipo_moneda' => $datos['tipo_moneda'],
            'metodo_base' => $datos['metodo_base'],
            'activa' => true,
            'observaciones' => filled($datos['observaciones'] ?? null) ? trim((string) $datos['observaciones']) : null,
        ])->load('sucursal');

        return response()->json([
            'message' => 'Caja registrada correctamente.',
            'caja' => $this->formatearCaja($caja),
        ], 201);
    }

    public function actualizar(Request $solicitud, Caja $caja): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! $this->puedeGestionarCajas($usuario)) {
            return response()->json([
                'message' => 'Solo los supervisores pueden actualizar cajas.',
            ], 403);
        }

        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        if (! $sucursalUsuario || (int) $caja->sucursal_id !== (int) $sucursalUsuario->id) {
            return response()->json([
                'message' => 'No puedes modificar cajas de otra sucursal.',
            ], 403);
        }

        $datos = $solicitud->validate([
            'nombre' => [
                'required',
                'string',
                'max:120',
                Rule::unique('cajas', 'nombre')
                    ->ignore($caja->id)
                    ->where(fn ($query) => $query->where('sucursal_id', $caja->sucursal_id)),
            ],
            'codigo' => ['nullable', 'string', 'max:80'],
            'tipo_moneda' => ['required', 'string', Rule::in(array_column(Caja::obtenerMonedasDisponibles(), 'value'))],
            'metodo_base' => ['required', 'string', Rule::in(array_column(Caja::obtenerMetodosDisponibles(), 'value'))],
            'activa' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:255'],
        ]);

        $caja->forceFill([
            'nombre' => trim($datos['nombre']),
            'codigo' => filled($datos['codigo'] ?? null) ? trim((string) $datos['codigo']) : null,
            'tipo_moneda' => $datos['tipo_moneda'],
            'metodo_base' => $datos['metodo_base'],
            'activa' => (bool) $datos['activa'],
            'observaciones' => filled($datos['observaciones'] ?? null) ? trim((string) $datos['observaciones']) : null,
        ])->save();

        return response()->json([
            'message' => 'Caja actualizada correctamente.',
            'caja' => $this->formatearCaja($caja->fresh('sucursal')),
        ]);
    }

    public function generarBase(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! $this->puedeGestionarCajas($usuario)) {
            return response()->json([
                'message' => 'Solo los supervisores pueden generar cajas base.',
            ], 403);
        }

        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        if (! $sucursalUsuario) {
            return response()->json([
                'message' => 'Tu usuario no tiene una sucursal valida asignada.',
            ], 422);
        }

        $definiciones = [
            ['nombre' => 'Caja 1', 'codigo' => 'CAJA-1', 'tipo_moneda' => Caja::MONEDA_BS, 'metodo_base' => Caja::METODO_EFECTIVO],
            ['nombre' => 'Caja 2', 'codigo' => 'CAJA-2', 'tipo_moneda' => Caja::MONEDA_BS, 'metodo_base' => Caja::METODO_QR],
            ['nombre' => 'Caja 3', 'codigo' => 'CAJA-3', 'tipo_moneda' => Caja::MONEDA_USD, 'metodo_base' => Caja::METODO_EFECTIVO],
        ];

        $creadas = collect();

        foreach ($definiciones as $definicion) {
            $caja = Caja::query()->firstOrCreate(
                [
                    'sucursal_id' => $sucursalUsuario->id,
                    'nombre' => $definicion['nombre'],
                ],
                [
                    'codigo' => $definicion['codigo'],
                    'tipo_moneda' => $definicion['tipo_moneda'],
                    'metodo_base' => $definicion['metodo_base'],
                    'activa' => true,
                ]
            );

            $creadas->push($this->formatearCaja($caja->load('sucursal')));
        }

        return response()->json([
            'message' => 'Cajas base generadas correctamente.',
            'cajas' => $creadas->values(),
        ]);
    }

    protected function puedeVerCajas(User $usuario): bool
    {
        return in_array($usuario->rol, [
            User::ROL_GERENTE,
            User::ROL_SUPERVISOR_SUCURSAL,
        ], true);
    }

    protected function puedeGestionarCajas(User $usuario): bool
    {
        return $usuario->rol === User::ROL_SUPERVISOR_SUCURSAL;
    }

    protected function obtenerSucursalDelUsuario(User $usuario): ?Sucursal
    {
        if (blank($usuario->sucursal)) {
            return null;
        }

        return Sucursal::query()
            ->where('activa', true)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim((string) $usuario->sucursal))])
            ->first();
    }

    protected function obtenerSucursalesSegunRol(User $usuario): array
    {
        $consulta = Sucursal::query()->where('activa', true)->orderBy('nombre');

        if ($usuario->rol === User::ROL_SUPERVISOR_SUCURSAL) {
            $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

            if ($sucursalUsuario) {
                $consulta->where('id', $sucursalUsuario->id);
            } else {
                $consulta->whereRaw('1 = 0');
            }
        }

        return $consulta->get()->map(fn (Sucursal $sucursal) => [
            'id' => $sucursal->id,
            'value' => $sucursal->id,
            'label' => $sucursal->nombre,
        ])->values()->all();
    }

    protected function formatearCaja(Caja $caja): array
    {
        $ingresos = (float) ($caja->total_ingresos ?? 0);
        $egresos = (float) ($caja->total_egresos ?? 0);

        return [
            'id' => $caja->id,
            'sucursal_id' => $caja->sucursal_id,
            'sucursal' => $caja->sucursal?->nombre,
            'nombre' => $caja->nombre,
            'codigo' => $caja->codigo,
            'tipo_moneda' => $caja->tipo_moneda,
            'tipo_moneda_label' => $caja->tipo_moneda === Caja::MONEDA_USD ? 'Dolares' : 'Bolivianos',
            'metodo_base' => $caja->metodo_base,
            'metodo_base_label' => collect(Caja::obtenerMetodosDisponibles())->firstWhere('value', $caja->metodo_base)['label'] ?? $caja->metodo_base,
            'activa' => $caja->activa,
            'observaciones' => $caja->observaciones,
            'total_ingresos' => round($ingresos, 2),
            'total_egresos' => round($egresos, 2),
            'saldo_actual' => round($ingresos - $egresos, 2),
        ];
    }

    protected function formatearMovimiento(MovimientoCaja $movimiento): array
    {
        return [
            'id' => $movimiento->id,
            'caja' => $movimiento->caja?->nombre,
            'sucursal' => $movimiento->sucursal?->nombre,
            'tipo_movimiento' => $movimiento->tipo_movimiento,
            'tipo_movimiento_label' => ucfirst($movimiento->tipo_movimiento),
            'monto' => round((float) $movimiento->monto, 2),
            'moneda' => $movimiento->moneda,
            'concepto' => $movimiento->concepto,
            'detalle' => $movimiento->detalle,
            'fecha_movimiento' => optional($movimiento->fecha_movimiento)?->format('d/m/Y H:i'),
            'saldo_resultante' => round((float) $movimiento->saldo_resultante, 2),
            'usuario' => $movimiento->usuario
                ? trim($movimiento->usuario->nombre.' '.$movimiento->usuario->apellido)
                : null,
        ];
    }
}
