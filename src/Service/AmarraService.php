<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Solicitudes de amarra. Hasta 23 pies puede ir a tierra; más eslora va a agua.
 * La prioridad sale de los m2 disponibles y de socios.fecha_ingreso.
 */
final class AmarraService
{
    public const PIES_TIERRA = 23.0;
    private const METROS_POR_PIE = 0.3048;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function destinoPorEslora(?float $eslora): ?string
    {
        if ($eslora === null || $eslora <= 0) {
            return null;
        }

        return ($eslora / self::METROS_POR_PIE) <= self::PIES_TIERRA ? 'tierra' : 'agua';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tipos(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, codigo, nombre, eslora_m, eslora_max_m, manga_m, puntal_m, calado_m, calado_max_m, fuente
             FROM cnb_app.tipos_embarcacion
             ORDER BY nombre ASC'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lista(?int $soloSocio = null): array
    {
        $sql = "SELECT s.id, s.numero_socio, s.modalidad, s.fecha_solicitud,
                       so.fecha_ingreso AS fecha_ingreso_socio,
                       s.destino_solicitado, s.destino_disponible, s.m2_necesarios, s.m2_disponibles,
                       s.estado, s.observaciones, s.embarcacion_id, s.tipo_embarcacion_id,
                       s.metros_comprados, s.cambio_embarcacion,
                       trim(so.nombre || ' ' || so.apellido) AS socio,
                       t.nombre AS tipo_nombre, t.eslora_m, t.eslora_max_m, t.manga_m,
                       e.nombre AS embarcacion
                FROM cnb_app.solicitudes_amarra s
                INNER JOIN cnb_app.socios so ON so.numero_socio = s.numero_socio
                LEFT JOIN cnb_app.tipos_embarcacion t ON t.id = s.tipo_embarcacion_id
                LEFT JOIN cnb_app.embarcaciones e ON e.id = s.embarcacion_id";
        $sql .= " ORDER BY
                    CASE
                        WHEN s.estado NOT IN ('en_lista', 'ofrecida') THEN 3
                        WHEN s.m2_disponibles IS NOT NULL
                             AND s.m2_necesarios IS NOT NULL
                             AND s.m2_disponibles >= s.m2_necesarios THEN 0
                        WHEN s.destino_disponible IS NOT NULL
                             AND s.destino_disponible = s.destino_solicitado THEN 1
                        ELSE 2
                    END,
                    so.fecha_ingreso NULLS LAST,
                    s.fecha_solicitud,
                    s.id";

        $rows = $this->connection->fetchAllAssociative($sql);
        $prioridad = 0;
        foreach ($rows as &$row) {
            $eslora = $row['eslora_max_m'] ?? $row['eslora_m'];
            $row['destino_por_eslora'] = $this->destinoPorEslora($eslora !== null && $eslora !== '' ? (float) $eslora : null);
            $row['cambio_embarcacion'] = filter_var($row['cambio_embarcacion'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (in_array($row['estado'], ['en_lista', 'ofrecida'], true)) {
                $row['prioridad'] = ++$prioridad;
            } else {
                $row['prioridad'] = null;
            }
        }
        unset($row);

        if ($soloSocio !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['numero_socio'] === $soloSocio
            ));
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ficha(int $id): ?array
    {
        foreach ($this->lista() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{ok: true, id: int}|array{ok: false, error: string}
     */
    public function solicitar(
        int $numeroSocio,
        string $modalidad,
        string $destinoSolicitado,
        ?int $embarcacionId,
        ?int $tipoId,
        string $observaciones,
        bool $cambioEmbarcacion = false,
    ): array {
        if (!in_array($modalidad, ['compra', 'alquiler', 'ampliacion'], true)) {
            return ['ok' => false, 'error' => 'La modalidad es compra, alquiler o ampliación.'];
        }
        if (!in_array($destinoSolicitado, ['agua', 'tierra'], true)) {
            return ['ok' => false, 'error' => 'El destino es agua o tierra.'];
        }

        $metrosComprados = null;
        if ($modalidad === 'ampliacion') {
            if ($embarcacionId === null) {
                return ['ok' => false, 'error' => 'La ampliación usa una embarcación del padrón.'];
            }
            $propia = $this->embarcacionPropia($numeroSocio, $embarcacionId);
            if (isset($propia['error'])) {
                return ['ok' => false, 'error' => $propia['error']];
            }
            $metrosComprados = $propia['metros_comprados'];
            if ($cambioEmbarcacion && $tipoId === null) {
                return ['ok' => false, 'error' => 'Elegí el tipo nuevo de la embarcación.'];
            }
            $tipo = $tipoId !== null
                ? $this->tipoPorId($tipoId)
                : $this->tipoDePedido($numeroSocio, $embarcacionId, null);
        } else {
            $cambioEmbarcacion = false;
            if ($embarcacionId === null && $tipoId === null) {
                return ['ok' => false, 'error' => 'Elegí una embarcación o un tipo.'];
            }
            $tipo = $this->tipoDePedido($numeroSocio, $embarcacionId, $tipoId);
        }
        if (isset($tipo['error'])) {
            return ['ok' => false, 'error' => $tipo['error']];
        }

        $eslora = $tipo['eslora_max_m'] ?? $tipo['eslora_m'];
        $destino = $this->destinoPorEslora($eslora !== null ? (float) $eslora : null);
        if ($destino === 'agua' && $destinoSolicitado === 'tierra') {
            return ['ok' => false, 'error' => 'Con más de 23 pies el destino es agua.'];
        }
        $m2 = null;
        if ($tipo['eslora_m'] !== null && $tipo['manga_m'] !== null) {
            $m2 = round((float) $tipo['eslora_m'] * (float) $tipo['manga_m'], 2);
        }

        $ingreso = $this->connection->fetchOne(
            'SELECT fecha_ingreso FROM cnb_app.socios WHERE numero_socio = ?',
            [$numeroSocio]
        );

        $id = $this->connection->fetchOne(
            'INSERT INTO cnb_app.solicitudes_amarra
                (numero_socio, embarcacion_id, tipo_embarcacion_id, modalidad, fecha_ingreso_socio,
                 destino_solicitado, m2_necesarios, metros_comprados, cambio_embarcacion, observaciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id',
            [
                $numeroSocio,
                $embarcacionId,
                $tipo['id'],
                $modalidad,
                $ingreso ?: null,
                $destinoSolicitado,
                $m2,
                $metrosComprados,
                $cambioEmbarcacion,
                $observaciones !== '' ? $observaciones : null,
            ]
        );

        return ['ok' => true, 'id' => (int) $id];
    }

    public function actualizar(int $id, ?string $m2Disponibles, ?string $destinoDisponible, string $estado): ?string
    {
        if (!in_array($estado, ['en_lista', 'ofrecida', 'asignada', 'rechazada', 'cancelada'], true)) {
            return 'Estado inválido.';
        }
        $m2 = null;
        if ($m2Disponibles !== null && trim($m2Disponibles) !== '') {
            if (!is_numeric($m2Disponibles) || (float) $m2Disponibles < 0) {
                return 'Los m2 disponibles no son válidos.';
            }
            $m2 = round((float) $m2Disponibles, 2);
        }
        $destino = null;
        if ($destinoDisponible !== null && trim($destinoDisponible) !== '') {
            if (!in_array($destinoDisponible, ['agua', 'tierra'], true)) {
                return 'El destino disponible es agua o tierra.';
            }
            $destino = $destinoDisponible;
        }

        $updated = $this->connection->update('cnb_app.solicitudes_amarra', [
            'm2_disponibles' => $m2,
            'destino_disponible' => $destino,
            'estado' => $estado,
            'updated_at' => date('c'),
        ], ['id' => $id]);

        return $updated === 1 ? null : 'No se encontró la solicitud.';
    }

    /**
     * @return array{metros_comprados: float|null}|array{error: string}
     */
    private function embarcacionPropia(int $numeroSocio, int $embarcacionId): array
    {
        $propia = $this->connection->fetchOne(
            'SELECT 1 FROM cnb_app.socio_embarcacion WHERE numero_socio = ? AND embarcacion_id = ?
             UNION
             SELECT 1 FROM cnb_app.embarcaciones WHERE id = ? AND numero_socio = ?',
            [$numeroSocio, $embarcacionId, $embarcacionId, $numeroSocio]
        );
        if (!$propia) {
            return ['error' => 'Esa embarcación no está vinculada a tu número de socio.'];
        }

        $metros = $this->connection->fetchOne(
            'SELECT metros_comprados FROM cnb_app.embarcaciones WHERE id = ?',
            [$embarcacionId]
        );
        if ($metros === false) {
            return ['error' => 'No se encontró la embarcación.'];
        }

        return [
            'metros_comprados' => $metros !== null && $metros !== '' ? (float) $metros : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tipoPorId(int $tipoId): array
    {
        $tipo = $this->connection->fetchAssociative(
            'SELECT id, COALESCE(eslora_max_m, eslora_m) AS eslora_max_m, eslora_m, manga_m
             FROM cnb_app.tipos_embarcacion WHERE id = ?',
            [$tipoId]
        );

        return $tipo === false ? ['error' => 'No se encontró el tipo de embarcación.'] : $tipo;
    }

    /**
     * @return array<string, mixed>
     */
    private function tipoDePedido(int $numeroSocio, ?int $embarcacionId, ?int $tipoId): array
    {
        if ($embarcacionId !== null) {
            $propia = $this->connection->fetchOne(
                'SELECT 1 FROM cnb_app.socio_embarcacion WHERE numero_socio = ? AND embarcacion_id = ?
                 UNION
                 SELECT 1 FROM cnb_app.embarcaciones WHERE id = ? AND numero_socio = ?',
                [$numeroSocio, $embarcacionId, $embarcacionId, $numeroSocio]
            );
            if (!$propia) {
                return ['error' => 'Esa embarcación no está vinculada a tu número de socio.'];
            }
            $row = $this->connection->fetchAssociative(
                'SELECT t.id, COALESCE(t.eslora_max_m, t.eslora_m, e.eslora_m) AS eslora_max_m,
                        COALESCE(t.eslora_m, e.eslora_m) AS eslora_m,
                        COALESCE(t.manga_m, e.manga_m) AS manga_m
                 FROM cnb_app.embarcaciones e
                 LEFT JOIN cnb_app.tipos_embarcacion t ON t.id = e.tipo_embarcacion_id
                 WHERE e.id = ?',
                [$embarcacionId]
            );
            if ($row === false) {
                return ['error' => 'No se encontró la embarcación.'];
            }
            if ($row['id'] === null && $tipoId !== null) {
                $row['id'] = $tipoId;
            }
            if ($row['id'] === null) {
                return ['error' => 'Esa embarcación no tiene tipo. Elegí uno de la lista.'];
            }

            return $row;
        }

        return $this->tipoPorId((int) $tipoId);
    }
}
