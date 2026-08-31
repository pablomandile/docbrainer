<?php

namespace App\Enums;

enum SummaryStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Running => 'Generando',
            self::Completed => 'Completado',
            self::Failed => 'Falló',
        };
    }
}
