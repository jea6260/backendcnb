<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class VaraderoService
{
    public const DIAS_MAX_RESERVA = 15;
    public const DIAS_MAX_ANIO = 15;
    public const ESTADOS_ACTIVOS = ['pendiente', 'confirmada', 'en_curso'];
    public const ESTADOS_BLOQUEAN_NUEVA = ['pendiente', 'en_curso'];
    public const ESTADOS_CUPO = ['pendiente', 'confirmada', 'en_curso', 'finalizada'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
    ) {
    }

    /**
     * Embarcaciones del socio aptas para varadero (activas; trata estado vacio como activa).
     *
     * @return list<array{id: int|string, label: string, eslora_m: float|null, estado: string, nombre: string, matricula: string|null}>
     */
    public function embarcacionesDeSocio(int $numeroSocio, bool $soloActivas = true): array
    {
        $sql = "SELECT e.id, e.nombre, e.matricula, e.eslora_m, e.estado, e.ambito, e.tipo, e.modelo,
                       e.ubicacion_id, u.nombre AS ubicacion, e.estado_padron_id, ep.nombre AS estado_padron
                FROM cnb_app.embarcaciones e
                LEFT JOIN cnb_app.ubicaciones u ON u.id = e.ubicacion_id
                LEFT JOIN cnb_app.estados_padron ep ON ep.id = e.estado_padron_id
                WHERE e.numero_socio = ?";
        $params = [$numeroSocio];

        if ($soloActivas) {
            // Estado vacio/null se considera activa (datos legacy / formularios incompletos).
            $sql .= " AND COALESCE(NULLIF(TRIM(e.estado), ''), 'activa') = 'activa'";
        }

        $sql .= ' ORDER BY e.nombre ASC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static function (array $row): array {
                $estado = trim((string) ($row['estado'] ?? ''));
                if ($estado === '') {
                    $estado = 'activa';
                }
                $label = (string) $row['nombre'];
                if (!empty($row['matricula'])) {
                    $label .= ' - ' . $row['matricula'];
                }
                if ($row['eslora_m'] !== null && $row['eslora_m'] !== '') {
                    $label .= sprintf(' (%.2fm)', (float) $row['eslora_m']);
                }
                if (!empty($row['ambito'])) {
                    $label .= ' [' . $row['ambito'] . ']';
                }

                return [
                    'id' => $row['id'],
                    'nombre' => (string) $row['nombre'],
                    'matricula' => $row['matricula'] !== null ? (string) $row['matricula'] : null,
                    'label' => $label,
                    'eslora_m' => isset($row['eslora_m']) && $row['eslora_m'] !== null && $row['eslora_m'] !== ''
                        ? (float) $row['eslora_m']
                        : null,
                    'estado' => $estado,
                    'ambito' => $row['ambito'] !== null ? (string) $row['ambito'] : null,
                    'tipo' => $row['tipo'] !== null ? (string) $row['tipo'] : null,
                    'modelo' => $row['modelo'] !== null ? (string) $row['modelo'] : null,
                    'ubicacion_id' => isset($row['ubicacion_id']) ? (int) $row['ubicacion_id'] : null,
                    'ubicacion' => $row['ubicacion'] !== null ? (string) $row['ubicacion'] : null,
                    'estado_padron_id' => isset($row['estado_padron_id']) ? (int) $row['estado_padron_id'] : null,
                    'estado_padron' => $row['estado_padron'] !== null ? (string) $row['estado_padron'] : null,
                ];
            },
            $rows
        );
    }

    /**
     * Reserva pendiente o en_curso del socio (bloquea una nueva).
     *
     * @return array<string, mixed>|null
     */
    public function reservaBloqueanteDeSocio(int $numeroSocio): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT r.*, e.nombre AS embarcacion_nombre, e.matricula AS embarcacion_matricula,
                    e.eslora_m AS embarcacion_eslora_m,
                    sp.codigo AS espacio_codigo
             FROM cnb_app.reservas_varadero r
             LEFT JOIN cnb_app.embarcaciones e ON e.id = r.embarcacion_id
             LEFT JOIN cnb_app.espacios_varadero sp ON sp.id = r.espacio_id
             WHERE r.numero_socio = ?
               AND r.estado IN ('pendiente', 'en_curso')
             ORDER BY
               CASE r.estado WHEN 'en_curso' THEN 0 ELSE 1 END,
               r.fecha_inicio DESC
             LIMIT 1",
            [$numeroSocio]
        );

        return $row ? $this->normalizeReserva($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reservasDeSocio(int $numeroSocio): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.*, e.nombre AS embarcacion_nombre, e.matricula AS embarcacion_matricula,
                    sp.codigo AS espacio_codigo
             FROM cnb_app.reservas_varadero r
             LEFT JOIN cnb_app.embarcaciones e ON e.id = r.embarcacion_id
             LEFT JOIN cnb_app.espacios_varadero sp ON sp.id = r.espacio_id
             WHERE r.numero_socio = ?
             ORDER BY r.fecha_inicio DESC",
            [$numeroSocio]
        );

        return array_map($this->normalizeReserva(...), $rows);
    }

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string, status: int}
     */
    public function agregarObservacion(int $reservaId, int $numeroSocio, string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return ['ok' => false, 'error' => 'La observacion no puede estar vacia', 'status' => 400];
        }

        $reserva = $this->connection->fetchAssociative(
            'SELECT id, numero_socio, estado, observaciones
             FROM cnb_app.reservas_varadero
             WHERE id = ?',
            [$reservaId]
        );

        if (!$reserva) {
            return ['ok' => false, 'error' => 'Reserva no encontrada', 'status' => 404];
        }

        if ((int) $reserva['numero_socio'] !== $numeroSocio) {
            return ['ok' => false, 'error' => 'La reserva no pertenece al socio', 'status' => 403];
        }

        if (!in_array((string) $reserva['estado'], self::ESTADOS_BLOQUEAN_NUEVA, true)) {
            return [
                'ok' => false,
                'error' => 'Solo se pueden agregar observaciones a reservas pendientes o en curso',
                'status' => 400,
            ];
        }

        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))
            ->format('Y-m-d H:i');
        $previa = trim((string) ($reserva['observaciones'] ?? ''));
        $nueva = $previa === ''
            ? sprintf('[%s] %s', $stamp, $texto)
            : $previa . "\n" . sprintf('[%s] %s', $stamp, $texto);

        $this->connection->update(
            'cnb_app.reservas_varadero',
            ['observaciones' => $nueva],
            ['id' => $reservaId]
        );

        $actualizada = $this->connection->fetchAssociative(
            "SELECT r.*, e.nombre AS embarcacion_nombre, e.matricula AS embarcacion_matricula,
                    sp.codigo AS espacio_codigo
             FROM cnb_app.reservas_varadero r
             LEFT JOIN cnb_app.embarcaciones e ON e.id = r.embarcacion_id
             LEFT JOIN cnb_app.espacios_varadero sp ON sp.id = r.espacio_id
             WHERE r.id = ?",
            [$reservaId]
        );

        return ['ok' => true, 'data' => $this->normalizeReserva($actualizada ?: [])];
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
                    r.fecha_inicio, r.fecha_fin, r.estado, r.motivo, r.observaciones,
                    s.apellido AS socio_apellido, s.nombre AS socio_nombre,
                    e.nombre AS embarcacion_nombre, e.matricula AS embarcacion_matricula
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
            $embarcacion = trim((string) ($reserva['embarcacion_nombre'] ?? ''));
            if ($embarcacion === '') {
                $embarcacion = 'Embarcacion #' . $reserva['embarcacion_id'];
            } elseif (!empty($reserva['embarcacion_matricula'])) {
                $embarcacion .= ' - ' . $reserva['embarcacion_matricula'];
            }

            $byEspacio[$espacioId][] = [
                'id' => (int) $reserva['id'],
                'fecha_inicio' => substr((string) $reserva['fecha_inicio'], 0, 10),
                'fecha_fin' => substr((string) $reserva['fecha_fin'], 0, 10),
                'estado' => $reserva['estado'],
                'motivo' => $reserva['motivo'],
                'observaciones' => $reserva['observaciones'],
                'numero_socio' => (int) $reserva['numero_socio'],
                'socio' => trim(($reserva['socio_apellido'] ?? '') . ', ' . ($reserva['socio_nombre'] ?? ''), ' ,'),
                'embarcacion_id' => (int) $reserva['embarcacion_id'],
                'embarcacion' => $embarcacion,
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

        $bloqueante = $this->reservaBloqueanteDeSocio($numeroSocio);
        if ($bloqueante !== null && ($excludeReservaId === null || (int) $bloqueante['id'] !== $excludeReservaId)) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'El socio ya tiene una reserva %s (#%s). No puede cargar otra: agregue observaciones a la existente.',
                    $bloqueante['estado'],
                    $bloqueante['id']
                ),
                'status' => 409,
            ];
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

        if (!$this->isEmbarcacionActiva($embarcacion['estado'] ?? null)) {
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

        if ($embarcacion['eslora_m'] === null || $embarcacion['eslora_m'] === '') {
            return [
                'ok' => false,
                'error' => 'La embarcacion no tiene eslora cargada; complete el padron antes de reservar varadero',
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeReserva(array $row): array
    {
        $normalized = $this->registry->normalizeRow($row);
        if (isset($normalized['fecha_inicio'])) {
            $normalized['fecha_inicio'] = substr((string) $normalized['fecha_inicio'], 0, 10);
        }
        if (isset($normalized['fecha_fin'])) {
            $normalized['fecha_fin'] = substr((string) $normalized['fecha_fin'], 0, 10);
        }

        $nombre = trim((string) ($normalized['embarcacion_nombre'] ?? ''));
        if ($nombre === '' && isset($normalized['embarcacion_id'])) {
            $nombre = 'Embarcacion #' . $normalized['embarcacion_id'];
        }
        $normalized['embarcacion'] = $nombre;
        if (!empty($normalized['embarcacion_matricula'])) {
            $normalized['embarcacion'] .= ' - ' . $normalized['embarcacion_matricula'];
        }

        return $normalized;
    }

    private function isEmbarcacionActiva(mixed $estado): bool
    {
        $normalized = strtolower(trim((string) ($estado ?? '')));

        return $normalized === '' || $normalized === 'activa';
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
