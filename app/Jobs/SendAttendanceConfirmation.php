<?php

namespace App\Jobs;

use App\Mail\AttendanceConfirmationMail;
use App\Models\AttendanceConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAttendanceConfirmation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public function __construct(public int $confirmationId) {}

    public function uniqueId(): string
    {
        return (string) $this->confirmationId;
    }

    public function handle(): void
    {
        $confirmation = AttendanceConfirmation::with('attendanceEvent.employee.user')->find($this->confirmationId);
        if (! $confirmation || $confirmation->status === 'sent') {
            return;
        }

        $user = $confirmation->attendanceEvent->employee->user;
        if (! $user || ! $user->email_verified_at || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            $confirmation->update([
                'status' => 'failed',
                'last_error' => 'No hay un correo verificado disponible.',
                'last_attempt_at' => now()->utc(),
            ]);

            return;
        }

        $attempt = $confirmation->attempt_count + 1;
        $confirmation->update([
            'attempt_count' => $attempt,
            'last_attempt_at' => now()->utc(),
            'last_error' => null,
        ]);

        $event = $confirmation->attendanceEvent;
        $eventLabels = [
            'ENTRY' => 'Entrada',
            'BREAK_START' => 'Inicio de colación',
            'BREAK_END' => 'Fin de colación',
            'EXIT' => 'Salida',
        ];
        $timezone = (string) config('attendance.presentation_timezone', 'America/Santiago');

        try {
            Mail::to($user->email)->send(new AttendanceConfirmationMail(
                $eventLabels[$event->event_type] ?? 'Marcación',
                $event->occurred_at_device->timezone($timezone)->format('d/m/Y H:i:s T'),
                $event->offline,
            ));
        } catch (Throwable) {
            $confirmation->update([
                'status' => $attempt >= $this->tries ? 'failed' : 'pending',
                'last_error' => 'No se pudo enviar el correo de confirmación.',
            ]);

            if ($attempt < $this->tries) {
                $this->release($this->backoff[$attempt - 1] ?? 300);
            }

            return;
        }

        DB::transaction(function () use ($confirmation): void {
            $locked = AttendanceConfirmation::whereKey($confirmation->id)->lockForUpdate()->first();
            if ($locked && $locked->status !== 'sent') {
                $locked->update([
                    'status' => 'sent',
                    'sent_at' => now()->utc(),
                    'last_error' => null,
                ]);
            }
        });
    }
}
