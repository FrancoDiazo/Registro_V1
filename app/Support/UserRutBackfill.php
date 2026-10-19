<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class UserRutBackfill
{
    public function normalizedRuts(): array
    {
        $identities = [];
        $hasRutColumn = Schema::hasColumn('usuarios', 'rut');
        if ($hasRutColumn) {
            foreach (DB::table('usuarios')->whereNotNull('rut')->get(['id', 'rut']) as $user) {
                if (! ChileanRut::isValid($user->rut)) {
                    throw new RuntimeException("El usuario {$user->id} tiene un RUT inválido; resuélvelo antes de continuar.");
                }

                $identities[(int) $user->id] = ChileanRut::normalize($user->rut);
            }
        }

        $administratorsWithoutRut = DB::table('usuarios')
            ->where('role', 'administrator')
            ->when($hasRutColumn, fn ($query) => $query->whereNull('rut'))
            ->orderBy('id')
            ->pluck('id');
        if ($administratorsWithoutRut->isNotEmpty()) {
            throw new RuntimeException(
                'Las cuentas administradoras con ID '.implode(', ', $administratorsWithoutRut->all())
                .' deben tener un RUT asociado antes de continuar.',
            );
        }

        $workerIds = DB::table('usuarios')->where('role', 'worker')->orderBy('id')->pluck('id');
        $employees = DB::table('trabajadores')->orderBy('id')->get(['user_id', 'employee_number']);
        $employeeUserIds = $employees->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $workersWithoutProfile = $workerIds->filter(
            fn ($userId) => ! in_array((int) $userId, $employeeUserIds, true),
        );
        if ($workersWithoutProfile->isNotEmpty()) {
            throw new RuntimeException(
                'Las cuentas de trabajador con ID '.implode(', ', $workersWithoutProfile->all())
                .' no tienen un perfil laboral asociado.',
            );
        }

        foreach ($employees as $employee) {
            if (! ChileanRut::isValid($employee->employee_number)) {
                throw new RuntimeException("El perfil laboral del usuario {$employee->user_id} no contiene un RUT válido; resuélvelo antes de continuar.");
            }

            $rut = ChileanRut::normalize($employee->employee_number);
            $userId = (int) $employee->user_id;
            if (isset($identities[$userId]) && $identities[$userId] !== $rut) {
                throw new RuntimeException("El RUT del usuario {$userId} no coincide con el de su perfil laboral; resuélvelo antes de continuar.");
            }

            $identities[$userId] = $rut;
        }

        $workersWithoutRut = $workerIds->filter(fn ($userId) => ! isset($identities[(int) $userId]));
        if ($workersWithoutRut->isNotEmpty()) {
            throw new RuntimeException(
                'Las cuentas de trabajador con ID '.implode(', ', $workersWithoutRut->all())
                .' necesitan un perfil laboral con RUT válido antes de continuar.',
            );
        }

        $owners = [];
        foreach ($identities as $userId => $rut) {
            if (isset($owners[$rut]) && $owners[$rut] !== $userId) {
                throw new RuntimeException('Hay RUT duplicados entre cuentas; resuélvelos antes de continuar.');
            }

            $owners[$rut] = $userId;
        }

        return $identities;
    }

    public function apply(): int
    {
        $identities = $this->normalizedRuts();
        foreach ($identities as $userId => $rut) {
            DB::table('usuarios')->where('id', $userId)->update(['rut' => $rut]);
        }

        return count($identities);
    }

    public function rutBelongsToAnotherEmployee(string $rut, ?int $exceptUserId = null): bool
    {
        foreach (DB::table('trabajadores')
            ->join('usuarios', 'usuarios.id', '=', 'trabajadores.user_id')
            ->when($exceptUserId !== null, fn ($query) => $query->where('usuarios.id', '!=', $exceptUserId))
            ->get(['trabajadores.employee_number']) as $employee) {
            if (ChileanRut::compact($employee->employee_number) === ChileanRut::compact($rut)) {
                return true;
            }
        }

        return false;
    }
}
