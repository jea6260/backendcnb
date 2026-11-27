<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Quien puede pedir amarra, varadero o figurar en una embarcación.
 * Activo, vitalicio y cónyuge. Baja, menor y ausente no.
 */
final class SocioElegibilidad
{
    /** @var list<string> */
    public const ESTADOS = ['AO', 'AA', 'VO', 'activo'];

    public static function permite(mixed $estado): bool
    {
        return in_array((string) $estado, self::ESTADOS, true);
    }

    public static function sqlEstados(): string
    {
        return "'AO', 'AA', 'VO', 'activo'";
    }

    public static function rechazo(Connection $connection, int $numero): ?string
    {
        if ($numero < 1) {
            return 'Socio no encontrado.';
        }

        $estado = $connection->fetchOne(
            'SELECT estado FROM cnb_app.socios WHERE numero_socio = ?',
            [$numero]
        );
        if ($estado === false || $estado === null) {
            return 'Socio no encontrado.';
        }
        if (!self::permite($estado)) {
            return 'Solo un socio activo, vitalicio o cónyuge puede pedir amarra, varadero o figurar en una embarcación.';
        }

        return null;
    }
}
