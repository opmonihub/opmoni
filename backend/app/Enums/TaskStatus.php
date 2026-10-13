<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';
    case Dismissed = 'dismissed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'A fazer',
            self::Doing => 'Em progresso',
            self::Done => 'Concluída',
            self::Dismissed => 'Dispensada',
        };
    }
}
