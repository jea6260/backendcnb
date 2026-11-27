<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class VaraderoService
{
    public const DIAS_MAX_RESERVA = 15;
    public const DIAS_MAX_ANIO = 15;
    public const ESTADOS_ACTIVOS = ['pendiente', 'confirmada', 'en_curso'];
    public const ESTADOS_BLOQUEAN_NUEVA = ['pendiente', 'confirmada', 'en_curso'];
    public const ESTADOS_CUPO = ['pendiente', 'confirmada', 'en_curso', 'finalizada'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
        private readonly MovimientoService $movimientos,
    ) {
    }

    /**
     * Embarcaciones del socio aptas para varadero (activas; trata estado vacio como activa).
     *
     * @return list<array{id: int|string, label: string, eslora_m: float|null, estado: string, nombre: string, matricula: string|null}>
     */
    public function embarcacionesDeSocio(int $numeroSocio, bool $soloActivas = true): array
    {
        $sql = "SELECT DISTINCT e.id, e.nombre, e.matricula, e.eslora_m, e.metros_comprados, e.estado, e.ambito, e.tipo, e.modelo,
                       e.ubicacion_id, u.nombre AS ubicacion, e.estado_padron_id, ep.nombre AS estado_padron,
                       se.rol AS vinculo_rol
                FROM cnb_app.embarcaciones e
                INNER JOIN cnb_app.socio_embarcacion se ON se.embarcacion_id = e.id
                LEFT JOIN cnb_app.ubicaciones u ON u.id = e.ubicacion_id
                LEFT JOIN cnb_app.estados_padron ep ON ep.id = e.estado_padron_id
                WHERE se.numero_socio = ?";
        $params = [$numeroSocio];

        if ($soloActivas) {
            // Estado vacio/null se considera activa (datos legacy / formularios incompletos).
            $sql .= " AND COALESCE(NULLIF(TRIM(e.estado), ''), 'activa') = 'activa'";
        }

        $sql .= ' ORDER BY e.nombre ASC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        // Fallback legacy: si la tabla M2M no tiene filas aun, usar embarcaciones.numero_socio
        if ($rows === []) {
            $legacySql = "SELECT e.id, e.nombre, e.matricula, e.eslora_m, e.metros_comprados, e.estado, e.ambito, e.tipo, e.modelo,
                                 e.ubicacion_id, u.nombre AS ubicacion, e.estado_padron_id, ep.nombre AS estado_padron,
                                 'titular' AS vinculo_rol
                          FROM cnb_app.embarcaciones e
                          LEFT JOIN cnb_app.ubicaciones u ON u.id = e.ubicacion_id
                          LEFT JOIN cnb_app.estados_padron ep ON ep.id = e.estado_padron_id
                          WHERE e.numero_socio = ?";
            $legacyParams = [$numeroSocio];
            if ($soloActivas) {
                $legacySql .= " AND COALESCE(NULLIF(TRIM(e.estado), ''), 'activa') = 'activa'";
            }
            $legacySql .= ' ORDER BY e.nombre ASC';
            $rows = $this->connection->fetchAllAssociative($legacySql, $legacyParams);
        }

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
                    'metros_comprados' => isset($row['metros_comprados']) && $row['metros_comprados'] !== null && $row['metros_comprados'] !== ''
                        ? (float) $row['metros_comprados']
                        : null,
                    'estado' => $estado,
                    'ambito' => $row['ambito'] !== null ? (string) $row['ambito'] : null,
                    'tipo' => $row['tipo'] !== null ? (string) $row['tipo'] : null,
                    'modelo' => $row['modelo'] !== null ? (string) $row['modelo'] : null,
                    'ubicacion_id' => isset($row['ubicacion_id']) ? (int) $row['ubicacion_id'] : null,
                    'ubicacion' => $row['ubicacion'] !== null ? (string) $row['ubicacion'] : null,
                    'estado_padron_id' => isset($row['estado_padron_id']) ? (int) $row['estado_padron_id'] : null,
                    'estado_padron' => $row['estado_padron'] !== null ? (string) $row['estado_padron'] : null,
                    'vinculo_rol' => $row['vinculo_rol'] !== null ? (string) $row['vinculo_rol'] : 'titular',
                ];
            },
            $rows
        );
    }

    public function socioPoseeEmbarcacion(int $numeroSocio, int $embarcacionId): bool
    {
        $linked = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cnb_app.socio_embarcacion
             WHERE numero_socio = ? AND embarcacion_id = ?',
            [$numeroSocio, $embarcacionId]
        );
        if ($linked > 0) {
            return true;
        }

        // Compatibilidad con datos previos a la migracion M2M.
        $legacy = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cnb_app.embarcaciones
             WHERE id = ? AND numero_socio = ?',
            [$embarcacionId, $numeroSocio]
        );

        return $legacy > 0;
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
               AND r.estado IN ('pendiente', 'confirmada', 'en_curso')
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
                'error' => 'Solo se pueden agregar observaciones a una reserva abierta',
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
                    r.extension_estado, r.extension_hasta,
                    s.apellido AS socio_apellido, s.nombre AS socio_nombre,
                    e.nombre AS embarcacion_nombre, e.matricula AS embarcacion_matricula
             FROM cnb_app.reservas_varadero r
             LEFT JOIN cnb_app.socios s ON s.numero_socio = r.numero_socio
             LEFT JOIN cnb_app.embarcaciones e ON e.id = r.embarcacion_id
             WHERE r.estado IN ('pendiente', 'confirmada', 'en_curso')
               AND r.fecha_inicio <= ?
               AND (r.fecha_fin >= ? OR r.estado = 'en_curso')
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

            $fin = substr((string) $reserva['fecha_fin'], 0, 10);
            $semaforo = $this->semaforo($reserva);
            $hoy = (new \DateTimeImmutable('today', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
            $byEspacio[$espacioId][] = [
                'id' => (int) $reserva['id'],
                'fecha_inicio' => substr((string) $reserva['fecha_inicio'], 0, 10),
                'fecha_fin' => $fin,
                'ocupa_hasta' => $semaforo['color'] !== null && $fin < $hoy ? $hoy : $fin,
                'estado' => $reserva['estado'],
                'semaforo' => $semaforo['color'],
                'semaforo_etiqueta' => $semaforo['etiqueta'],
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

        $habilitacion = SocioElegibilidad::rechazo($this->connection, $numeroSocio);
        if ($habilitacion !== null) {
            return ['ok' => false, 'error' => $habilitacion, 'status' => 403];
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

        if (!$this->socioPoseeEmbarcacion($numeroSocio, $embarcacionId)) {
            return [
                'ok' => false,
                'error' => 'La embarcacion no pertenece al socio seleccionado (ni como cotitular)',
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
                'estado' => $excludeReservaId === null ? 'pendiente' : (string) ($data['estado'] ?? 'pendiente'),
                'motivo' => $data['motivo'] ?? 'limpieza_mantenimiento',
                'observaciones' => ($data['observaciones'] ?? '') !== '' ? $data['observaciones'] : null,
            ],
        ];
    }

    /**
     * Pasos que este perfil puede dar. El socio no avanza estados.
     * Al salir del varadero hay dos destinos: la amarra o el socio se lo lleva.
     *
     * @return list<array{estado: string, etiqueta: string, destino: string|null}>
     */
    public function accionesDisponibles(string $perfil, string $estado): array
    {
        if ($estado === 'pendiente' && in_array($perfil, ['operador', 'administrador', 'superuser'], true)) {
            return [['estado' => 'confirmada', 'etiqueta' => 'Confirmar y habilitar', 'destino' => null]];
        }
        if ($estado === 'confirmada' && in_array($perfil, ['marinero', 'administrador', 'superuser'], true)) {
            return [['estado' => 'en_curso', 'etiqueta' => 'Barco entra a varadero', 'destino' => null]];
        }
        if ($estado === 'en_curso' && in_array($perfil, ['marinero', 'administrador', 'superuser'], true)) {
            return [
                ['estado' => 'finalizada', 'etiqueta' => 'Sale a su amarra', 'destino' => 'amarra'],
                ['estado' => 'finalizada', 'etiqueta' => 'Se lo lleva el socio', 'destino' => 'socio'],
            ];
        }

        return [];
    }

    /**
     * Verde: el turno iniciado sigue dentro de la fecha.
     * Naranja: se venció y hay una extensión pedida.
     * Rojo: se venció y nadie pidió extensión.
     *
     * @param array<string, mixed> $reserva
     * @return array{color: string|null, codigo: string, etiqueta: string, detalle: string}
     */
    public function semaforo(array $reserva): array
    {
        $estado = (string) ($reserva['estado'] ?? '');
        if ($estado !== 'en_curso') {
            return [
                'color' => null,
                'codigo' => $estado,
                'etiqueta' => $estado,
                'detalle' => '',
            ];
        }

        $hoy = (new \DateTimeImmutable('today', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
        $fin = substr((string) ($reserva['fecha_fin'] ?? ''), 0, 10);
        $extension = (string) ($reserva['extension_estado'] ?? '');
        $hasta = substr((string) ($reserva['extension_hasta'] ?? ''), 0, 10);
        if ($fin !== '' && $fin >= $hoy) {
            return [
                'color' => 'verde',
                'codigo' => 'en_plazo',
                'etiqueta' => 'En plazo',
                'detalle' => 'El turno sigue dentro de la fecha prevista, hasta '.$fin.'.',
            ];
        }
        if ($extension === 'pendiente') {
            return [
                'color' => 'naranja',
                'codigo' => 'extension',
                'etiqueta' => 'Extensión pedida',
                'detalle' => 'El plazo se venció. Hay una extensión pedida'.($hasta !== '' ? ' hasta '.$hasta : '').'. La decide operaciones o administración.',
            ];
        }

        return [
            'color' => 'rojo',
            'codigo' => 'vencido',
            'etiqueta' => 'Vencido',
            'detalle' => 'El plazo se venció el '.$fin.'. Pedí una extensión. Si no, el turno queda en rojo.',
        ];
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string, status: int}
     */
    public function solicitarExtension(int $id, int $numeroSocio, string $fechaHasta): array
    {
        $reserva = $this->connection->fetchAssociative(
            'SELECT id, numero_socio, espacio_id, estado, fecha_inicio, fecha_fin, extension_estado, observaciones
             FROM cnb_app.reservas_varadero WHERE id = ?',
            [$id]
        );
        if (!$reserva || (int) $reserva['numero_socio'] !== $numeroSocio) {
            return ['ok' => false, 'error' => 'No se encontró el turno.', 'status' => 404];
        }
        if ((string) $reserva['estado'] !== 'en_curso') {
            return ['ok' => false, 'error' => 'La extensión es para un turno que ya empezó.', 'status' => 400];
        }
        if ((string) ($reserva['extension_estado'] ?? '') === 'pendiente') {
            return ['ok' => false, 'error' => 'Ya hay una extensión esperando que la decida operaciones.', 'status' => 409];
        }
        $semaforo = $this->semaforo($reserva);
        if ($semaforo['codigo'] !== 'vencido') {
            return ['ok' => false, 'error' => 'El turno todavía está en fecha. La extensión se pide cuando se vence.', 'status' => 400];
        }

        $chequeo = $this->validarNuevaFecha($reserva, $fechaHasta);
        if (!$chequeo['ok']) {
            return $chequeo;
        }

        $this->connection->update('cnb_app.reservas_varadero', [
            'extension_estado' => 'pendiente',
            'extension_hasta' => $chequeo['fecha'],
            'observaciones' => $this->anotar($reserva, 'El socio pidió extensión hasta '.$chequeo['fecha'].'.'),
        ], ['id' => $id]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string, status: int}
     */
    public function resolverExtension(int $id, bool $aprobar, string $perfil): array
    {
        if (!in_array($perfil, ['operador', 'administrador', 'superuser'], true)) {
            return ['ok' => false, 'error' => 'La extensión la decide operaciones o administración.', 'status' => 403];
        }
        $reserva = $this->connection->fetchAssociative(
            'SELECT id, numero_socio, espacio_id, estado, fecha_inicio, fecha_fin, extension_estado, extension_hasta, observaciones
             FROM cnb_app.reservas_varadero WHERE id = ?',
            [$id]
        );
        if (!$reserva) {
            return ['ok' => false, 'error' => 'No se encontró el turno.', 'status' => 404];
        }
        if ((string) ($reserva['extension_estado'] ?? '') !== 'pendiente') {
            return ['ok' => false, 'error' => 'No hay una extensión pendiente.', 'status' => 400];
        }

        if (!$aprobar) {
            $this->connection->update('cnb_app.reservas_varadero', [
                'extension_estado' => 'rechazada',
                'observaciones' => $this->anotar($reserva, 'Operaciones rechazó la extensión. El turno sigue vencido.'),
            ], ['id' => $id]);

            return ['ok' => true];
        }

        $chequeo = $this->validarNuevaFecha($reserva, (string) $reserva['extension_hasta']);
        if (!$chequeo['ok']) {
            return $chequeo;
        }
        $this->connection->update('cnb_app.reservas_varadero', [
            'fecha_fin' => $chequeo['fecha'],
            'extension_estado' => 'aprobada',
            'observaciones' => $this->anotar($reserva, 'Operaciones aprobó la extensión hasta '.$chequeo['fecha'].'.'),
        ], ['id' => $id]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string, status: int}
     */
    public function cancelarPorSocio(int $id, int $numeroSocio): array
    {
        $reserva = $this->connection->fetchAssociative(
            'SELECT id, numero_socio, estado, observaciones FROM cnb_app.reservas_varadero WHERE id = ?',
            [$id]
        );
        if (!$reserva || (int) $reserva['numero_socio'] !== $numeroSocio) {
            return ['ok' => false, 'error' => 'No se encontró el turno.', 'status' => 404];
        }
        if (!in_array((string) $reserva['estado'], ['pendiente', 'confirmada'], true)) {
            return ['ok' => false, 'error' => 'El turno ya empezó. Lo siguen operaciones, administración y los marineros.', 'status' => 403];
        }

        $this->connection->update('cnb_app.reservas_varadero', [
            'estado' => 'cancelada',
            'observaciones' => $this->anotar($reserva, 'El socio dio de baja el turno antes de que empezara.'),
        ], ['id' => $id]);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $reserva
     * @return array{ok: true, fecha: string}|array{ok: false, error: string, status: int}
     */
    private function validarNuevaFecha(array $reserva, string $fechaHasta): array
    {
        try {
            $inicio = new \DateTimeImmutable(substr((string) $reserva['fecha_inicio'], 0, 10));
            $actual = new \DateTimeImmutable(substr((string) $reserva['fecha_fin'], 0, 10));
            $nueva = new \DateTimeImmutable(substr($fechaHasta, 0, 10));
        } catch (\Exception) {
            return ['ok' => false, 'error' => 'La fecha de extensión no es válida.', 'status' => 400];
        }
        if ($nueva <= $actual) {
            return ['ok' => false, 'error' => 'La extensión tiene que pasar la fecha prevista.', 'status' => 400];
        }
        $dias = (int) $inicio->diff($nueva)->days + 1;
        if ($dias > self::DIAS_MAX_RESERVA) {
            return ['ok' => false, 'error' => sprintf('El turno no puede superar %d días.', self::DIAS_MAX_RESERVA), 'status' => 400];
        }
        $extra = (int) $actual->diff($nueva)->days;
        $anio = (int) $inicio->format('Y');
        $usados = (int) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(cantidad_dias), 0)
             FROM cnb_app.reservas_varadero
             WHERE numero_socio = ?
               AND estado IN ('pendiente', 'confirmada', 'en_curso', 'finalizada')
               AND EXTRACT(YEAR FROM fecha_inicio) = ?",
            [(int) $reserva['numero_socio'], $anio]
        );
        $restantes = self::DIAS_MAX_ANIO - $usados;
        if ($extra > $restantes) {
            return [
                'ok' => false,
                'error' => sprintf('Cupo anual insuficiente: quedan %d días.', max(0, $restantes)),
                'status' => 400,
            ];
        }
        $solape = $this->connection->fetchOne(
            "SELECT id FROM cnb_app.reservas_varadero
             WHERE espacio_id = ?
               AND id <> ?
               AND estado IN ('pendiente', 'confirmada', 'en_curso')
               AND fecha_inicio <= ?
               AND fecha_fin >= ?
             LIMIT 1",
            [(int) $reserva['espacio_id'], (int) $reserva['id'], $nueva->format('Y-m-d'), $actual->modify('+1 day')->format('Y-m-d')]
        );
        if ($solape) {
            return ['ok' => false, 'error' => 'El espacio no está libre en esas fechas.', 'status' => 409];
        }

        return ['ok' => true, 'fecha' => $nueva->format('Y-m-d')];
    }

    /**
     * @param array<string, mixed> $reserva
     */
    private function anotar(array $reserva, string $texto): string
    {
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i');
        $linea = sprintf('[%s] %s', $stamp, $texto);
        $previa = trim((string) ($reserva['observaciones'] ?? ''));

        return $previa === '' ? $linea : $previa."\n".$linea;
    }

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string, status: int}
     */
    public function transicionar(
        int $id,
        string $hacia,
        string $perfil,
        ?string $destino = null,
        ?int $usuarioId = null,
        ?string $username = null,
    ): array {
        $hacia = trim($hacia);
        $destino = $destino !== null && $destino !== '' ? $destino : null;
        $reserva = $this->connection->fetchAssociative(
            'SELECT id, estado, observaciones, numero_socio, embarcacion_id FROM cnb_app.reservas_varadero WHERE id = ?',
            [$id]
        );
        if (!$reserva) {
            return ['ok' => false, 'error' => 'Reserva no encontrada', 'status' => 404];
        }

        $desde = (string) $reserva['estado'];
        if (!$this->permiteTransicion($perfil, $desde, $hacia)) {
            return ['ok' => false, 'error' => 'Ese paso no corresponde a este usuario.', 'status' => 403];
        }
        if ($hacia === 'finalizada' && !in_array($destino, ['amarra', 'socio'], true)) {
            return ['ok' => false, 'error' => 'Indicá si vuelve a la amarra o se lo lleva el socio.', 'status' => 400];
        }

        $notas = [
            'confirmada' => 'El operador confirmo la reserva y habilito el lugar.',
            'rechazada' => 'El operador rechazo la solicitud. El socio puede pedir otra.',
            'cancelada' => 'Reserva cancelada. El lugar quedo libre.',
            'en_curso' => 'El marinero informo que el barco ya esta en varadero.',
            'finalizada' => $destino === 'socio'
                ? 'El marinero informo que el socio se llevo el barco. El lugar quedo libre.'
                : 'El marinero informo que el barco volvio a su amarra. El lugar quedo libre.',
        ];
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))
            ->format('Y-m-d H:i');
        $previa = trim((string) ($reserva['observaciones'] ?? ''));
        $linea = sprintf('[%s] %s', $stamp, $notas[$hacia] ?? $hacia);
        $nueva = $previa === '' ? $linea : $previa . "\n" . $linea;

        $this->connection->beginTransaction();
        try {
            $this->connection->update('cnb_app.reservas_varadero', [
                'estado' => $hacia,
                'destino_salida' => $hacia === 'finalizada' ? $destino : null,
                'observaciones' => $nueva,
            ], ['id' => $id]);

            if (in_array($hacia, ['en_curso', 'finalizada'], true) && $username !== null && $username !== '') {
                $movimiento = $this->movimientos->registrar(
                    (int) $reserva['numero_socio'],
                    (int) $reserva['embarcacion_id'],
                    $hacia === 'en_curso' ? 'ingreso' : 'egreso',
                    'varadero',
                    $hacia === 'finalizada' ? $destino : null,
                    null,
                    $usuarioId,
                    $username,
                    null,
                    'varadero',
                    $id,
                    '',
                    null,
                );
                if (!$movimiento['ok']) {
                    $this->connection->rollBack();

                    return ['ok' => false, 'error' => $movimiento['error'], 'status' => 400];
                }
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        $actualizada = $this->connection->fetchAssociative(
            "SELECT r.*, e.nombre AS embarcacion_nombre, e.matricula AS embarcacion_matricula,
                    sp.codigo AS espacio_codigo
             FROM cnb_app.reservas_varadero r
             LEFT JOIN cnb_app.embarcaciones e ON e.id = r.embarcacion_id
             LEFT JOIN cnb_app.espacios_varadero sp ON sp.id = r.espacio_id
             WHERE r.id = ?",
            [$id]
        );

        return ['ok' => true, 'data' => $this->normalizeReserva($actualizada ?: [])];
    }

    private function permiteTransicion(string $perfil, string $desde, string $hacia): bool
    {
        $permitidas = [
            'pendiente' => [
                'confirmada' => ['operador', 'administrador', 'superuser'],
                'rechazada' => ['operador', 'administrador', 'superuser'],
            ],
            'confirmada' => [
                'en_curso' => ['marinero', 'administrador', 'superuser'],
                'cancelada' => ['operador', 'administrador', 'superuser'],
            ],
            'en_curso' => [
                'finalizada' => ['marinero', 'administrador', 'superuser'],
            ],
        ];

        return in_array($perfil, $permitidas[$desde][$hacia] ?? [], true);
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
        if (isset($normalized['extension_hasta']) && $normalized['extension_hasta'] !== null && $normalized['extension_hasta'] !== '') {
            $normalized['extension_hasta'] = substr((string) $normalized['extension_hasta'], 0, 10);
        }
        $normalized['semaforo'] = $this->semaforo($normalized);

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
