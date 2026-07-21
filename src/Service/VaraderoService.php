<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class VaraderoService
{
    public const DIAS_MAX_RESERVA = 15;
    public const DIAS_MAX_ANIO = 15;
    public const ESTADOS_ACTIVOS = ['pendiente', 'confirmada', 'en_curso'];
    public const ESTADOS_CUPO = ['pendiente', 'confirmada', 'en_curso', 'finalizada'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
    ) {
    }

    /**
     * @return list<array{id: int|string, label: string, eslora_m: float|null, estado: string}>
     */
    public function embarcacionesDeSocio(int $numeroSocio, bool $soloActivas = true): array
    {
        $sql = "SELECT id, nombre, matricula, eslora_m, estado
                FROM cnb_app.embarcaciones
                WHERE numero_socio = ?";
        $params = [$numeroSocio];

        if ($soloActivas) {
            $sql .= " AND estado = 'activa'";
        }

        $sql .= ' ORDER BY nombre ASC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static function (array $row): array {
                $label = (string) $row['nombre'];
                if (!empty($row['matricula'])) {
                    $label .= ' - ' . $row['matricula'];
                }
                $label .= sprintf(' (%.2fm)', (float) $row['eslora_m']);

                return [
                    'id' => $row['id'],
                    'label' => $label,
                    'eslora_m' => isset($row['eslora_m']) ? (float) $row['eslora_m'] : null,
                    'estado' => (string) $row['estado'],
                ];
            },
            $rows
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function timeline(string $desde, string $hasta): array
    {
        $espacios = $this->connection->fetchAllAssociative(
            'SELECT id, codigo, descripcion, eslora_max_m, manga_max_m, activo
             FROM cnb_app.espacios_varadero
             ORDER BY codigo ASC'
        );

        $reservas = $this->connection->fetchAllAssociative(
            "SELECT r.id, r.espacio_id, r.numero_socio, r.embarcacion_id,
                    r.fecha_inicio, r.fecha_fin, r.estado, r.motivo,
                    s.apellido AS socio_apellido, s.nombre AS socio_nombre,
                    e.nombre AS embarcacion_nombre
             FROM cnb_app.reservas_varadero r
             LEFT JOIN cnb_app.socios s ON s.numero_socio = r.numero_socio
             LEFT JOIN cnb_app.embarcaciones e ON e.id = r.embarcacion_id
             WHERE r.estado IN ('pendiente', 'confirmada', 'en_curso')
               AND r.fecha_inicio <= ?
               AND r.fecha_fin >= ?
             ORDER BY r.fecha_inicio ASC",
            [$hasta, $desde]
        );

        $byEspacio = [];
        foreach ($reservas as $reserva) {
            $espacioId = (int) $reserva['espacio_id'];
            $byEspacio[$espacioId][] = [
                'id' => (int) $reserva['id'],
                'fecha_inicio' => substr((string) $reserva['fecha_inicio'], 0, 10),
                'fecha_fin' => substr((string) $reserva['fecha_fin'], 0, 10),
                'estado' => $reserva['estado'],
                'motivo' => $reserva['motivo'],
                'numero_socio' => (int) $reserva['numero_socio'],
                'socio' => trim(($reserva['socio_apellido'] ?? '') . ', ' . ($reserva['socio_nombre'] ?? ''), ' ,'),
                'embarcacion_id' => (int) $reserva['embarcacion_id'],
                'embarcacion' => $reserva['embarcacion_nombre'] ?? ('#' . $reserva['embarcacion_id']),
            ];
        }

        $espaciosOut = [];
        foreach ($espacios as $espacio) {
            $id = (int) $espacio['id'];
            $espaciosOut[] = [
                'id' => $id,
                'codigo' => $espacio['codigo'],
                'descripcion' => $espacio['descripcion'],
                'eslora_max_m' => isset($espacio['eslora_max_m']) ? (float) $espacio['eslora_max_m'] : null,
                'manga_max_m' => isset($espacio['manga_max_m']) ? (float) $espacio['manga_max_m'] : null,
                'activo' => $this->isTruthy($espacio['activo'] ?? false),
                'reservas' => $byEspacio[$id] ?? [],
            ];
        }

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'espacios' => $espaciosOut,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function disponibilidad(?int $espacioId, string $desde, string $hasta, ?int $numeroSocio = null): array
    {
        $ocupaciones = [];
        $espacio = null;

        if ($espacioId !== null && $espacioId > 0) {
            $espacio = $this->connection->fetchAssociative(
                'SELECT id, codigo, eslora_max_m, manga_max_m, activo
                 FROM cnb_app.espacios_varadero
                 WHERE id = ?',
                [$espacioId]
            );

            if ($espacio) {
                $rows = $this->connection->fetchAllAssociative(
                    "SELECT id, fecha_inicio, fecha_fin, estado, numero_socio
                     FROM cnb_app.reservas_varadero
                     WHERE espacio_id = ?
                       AND estado IN ('pendiente', 'confirmada', 'en_curso')
                       AND fecha_inicio <= ?
                       AND fecha_fin >= ?
                     ORDER BY fecha_inicio ASC",
                    [$espacioId, $hasta, $desde]
                );

                $ocupaciones = array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'fecha_inicio' => substr((string) $row['fecha_inicio'], 0, 10),
                        'fecha_fin' => substr((string) $row['fecha_fin'], 0, 10),
                        'estado' => $row['estado'],
                        'numero_socio' => (int) $row['numero_socio'],
                    ],
                    $rows
                );
            }
        }

        $diasUsados = 0;
        if ($numeroSocio !== null && $numeroSocio > 0) {
            $anio = (int) (new \DateTimeImmutable($desde))->format('Y');
            $diasUsados = (int) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(cantidad_dias), 0)
                 FROM cnb_app.reservas_varadero
                 WHERE numero_socio = ?
                   AND estado IN ('pendiente', 'confirmada', 'en_curso', 'finalizada')
                   AND EXTRACT(YEAR FROM fecha_inicio) = ?",
                [$numeroSocio, $anio]
            );
        }

        return [
            'espacio' => $espacio ? $this->registry->normalizeRow($espacio) : null,
            'desde' => $desde,
            'hasta' => $hasta,
            'ocupaciones' => $ocupaciones,
            'dias_usados_anio' => $diasUsados,
            'dias_maximos_anio' => self::DIAS_MAX_ANIO,
            'dias_restantes_anio' => max(0, self::DIAS_MAX_ANIO - $diasUsados),
            'dias_maximos_reserva' => self::DIAS_MAX_RESERVA,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, error: string, status: int}
     */
    public function validarYPrepararReserva(array $data, ?int $excludeReservaId = null): array
    {
        $numeroSocio = (int) ($data['numero_socio'] ?? 0);
        $embarcacionId = (int) ($data['embarcacion_id'] ?? 0);
        $espacioId = (int) ($data['espacio_id'] ?? 0);
        $fechaInicio = trim((string) ($data['fecha_inicio'] ?? ''));
        $fechaFin = trim((string) ($data['fecha_fin'] ?? ''));

        if ($numeroSocio < 1 || $embarcacionId < 1 || $espacioId < 1 || $fechaInicio === '' || $fechaFin === '') {
            return ['ok' => false, 'error' => 'Socio, embarcacion, espacio y fechas son obligatorios', 'status' => 400];
        }

        $embarcacion = $this->connection->fetchAssociative(
            'SELECT id, numero_socio, nombre, eslora_m, estado
             FROM cnb_app.embarcaciones
             WHERE id = ?',
            [$embarcacionId]
        );

        if (!$embarcacion) {
            return ['ok' => false, 'error' => 'Embarcacion no encontrada', 'status' => 404];
        }

        if ((int) ($embarcacion['numero_socio'] ?? 0) !== $numeroSocio) {
            return [
                'ok' => false,
                'error' => 'La embarcacion no pertenece al socio seleccionado',
                'status' => 400,
            ];
        }

        if (($embarcacion['estado'] ?? '') !== 'activa') {
            return [
                'ok' => false,
                'error' => 'La embarcacion no esta activa para reservar varadero',
                'status' => 400,
            ];
        }

        $espacio = $this->connection->fetchAssociative(
            'SELECT id, codigo, eslora_max_m, activo FROM cnb_app.espacios_varadero WHERE id = ?',
            [$espacioId]
        );

        if (!$espacio || !$this->isTruthy($espacio['activo'] ?? false)) {
            return ['ok' => false, 'error' => 'Espacio de varadero no disponible', 'status' => 400];
        }

        try {
            $inicio = new \DateTimeImmutable($fechaInicio);
            $fin = new \DateTimeImmutable($fechaFin);
        } catch (\Exception) {
            return ['ok' => false, 'error' => 'Fechas invalidas', 'status' => 400];
        }

        if ($fin < $inicio) {
            return ['ok' => false, 'error' => 'La fecha hasta debe ser posterior o igual a desde', 'status' => 400];
        }

        $dias = (int) $inicio->diff($fin)->days + 1;
        if ($dias > self::DIAS_MAX_RESERVA) {
            return [
                'ok' => false,
                'error' => sprintf('La reserva no puede superar %d dias', self::DIAS_MAX_RESERVA),
                'status' => 400,
            ];
        }

        if ((float) $embarcacion['eslora_m'] > (float) $espacio['eslora_max_m']) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'La eslora de la embarcacion (%.2fm) supera el maximo del espacio %s (%.2fm)',
                    (float) $embarcacion['eslora_m'],
                    $espacio['codigo'],
                    (float) $espacio['eslora_max_m']
                ),
                'status' => 400,
            ];
        }

        $sql = "SELECT id FROM cnb_app.reservas_varadero
                WHERE espacio_id = ?
                  AND estado IN ('pendiente', 'confirmada', 'en_curso')
                  AND fecha_inicio <= ?
                  AND fecha_fin >= ?";
        $params = [$espacioId, $fechaFin, $fechaInicio];

        if ($excludeReservaId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeReservaId;
        }

        $sql .= ' LIMIT 1';
        $solape = $this->connection->fetchOne($sql, $params);

        if ($solape) {
            return [
                'ok' => false,
                'error' => 'El espacio de varadero no esta disponible en ese rango',
                'status' => 409,
            ];
        }

        $disponibilidad = $this->disponibilidad($espacioId, $fechaInicio, $fechaFin, $numeroSocio);
        if ($dias > (int) $disponibilidad['dias_restantes_anio']) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Cupo anual insuficiente: quedan %d de %d dias',
                    $disponibilidad['dias_restantes_anio'],
                    self::DIAS_MAX_ANIO
                ),
                'status' => 400,
            ];
        }

        return [
            'ok' => true,
            'payload' => [
                'numero_socio' => $numeroSocio,
                'embarcacion_id' => $embarcacionId,
                'espacio_id' => $espacioId,
                'fecha_inicio' => $inicio->format('Y-m-d'),
                'fecha_fin' => $fin->format('Y-m-d'),
                'estado' => $data['estado'] ?? 'pendiente',
                'motivo' => $data['motivo'] ?? 'limpieza_mantenimiento',
                'observaciones' => ($data['observaciones'] ?? '') !== '' ? $data['observaciones'] : null,
            ],
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 't', 'true', 'yes', 'on'], true);
    }
}
