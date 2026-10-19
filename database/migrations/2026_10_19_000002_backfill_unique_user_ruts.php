<?php

use App\Support\UserRutBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(fn (): int => (new UserRutBackfill())->apply());
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $workerUserIds = DB::table('trabajadores')
                ->join('usuarios', 'usuarios.id', '=', 'trabajadores.user_id')
                ->where('usuarios.role', 'worker')
                ->pluck('usuarios.id');

            DB::table('usuarios')->whereIn('id', $workerUserIds)->update(['rut' => null]);
        });
    }
};
