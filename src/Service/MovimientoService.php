<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Libro de ingreso y egreso de embarcaciones.
 * La firma es el usuario que valida el movimiento.
 */
final class MovimientoService
{
    private const TZ = 'America/Argentina/Buenos_Aires';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function barcosDeSocio(int $numeroSocio): array
    {
        if ($numeroSocio < 1) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT e.id, e.nombre, e.matricula, e.modelo, e.eslora_m
             FROM cnb_app.embarcaciones e
             INNER JOIN cnb_app.socio_embarcacion se ON se.embarcacion_id = e.id
             WHERE se.numero_socio = ?
             ORDER BY e.nombre ASC",
            [$numeroSocio]
        );
        if ($rows === []) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, nombre, matricula, modelo, eslora_m
                 FROM cnb_app.embarcaciones
                 WHERE numero_socio = ?
                 ORDER BY nombre ASC',
                [$numeroSocio]
            );
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'nombre' => (string) $row['nombre'],
                'matricula' => $row['matricula'] !== null ? (string) $row['matricula'] : null,
                'modelo' => $row['modelo'] !== null ? (string) $row['modelo'] : null,
                'eslora_m' => $row['eslora_m'] !== null && $row['eslora_m'] !== '' ? (float) $row['eslora_m'] : null,
            ];
        }, $rows);
    }

    /**
     * @return array{ok: true, codigo: string}|array{ok: false, error: string}
     */
    public function codigoQr(int $numeroSocio, int $embarcacionId): array
    {
        if (!$this->esDelSocio($numeroSocio, $embarcacionId)) {
            return ['ok' => false, 'error' => 'Esa embarcación no está vinculada a tu número de socio.'];
        }

        $token = $this->connection->fetchOne(
            'SELECT qr_token FROM cnb_app.embarcaciones WHERE id = ?',
            [$embarcacionId]
        );
        if ($token === false) {
            return ['ok' => false, 'error' => 'No se encontró la embarcación.'];
        }
        if ($token === null || $token === '') {
            $token = bin2hex(random_bytes(16));
            $this->connection->update('cnb_app.embarcaciones', [
                'qr_token' => $token,
            ], ['id' => $embarcacionId]);
        }

        return [
            'ok' => true,
            'codigo' => sprintf('CNB1|%d|%d|%s', $numeroSocio, $embarcacionId, $token),
        ];
    }

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public function registrar(
        int $numeroSocio,
        int $embarcacionId,
        string $tipo,
        string $contexto,
        ?string $destino,
        ?string $ocurridoEn,
        ?int $usuarioId,
        string $username,
        ?string $foto,
        string $origen,
        ?int $reservaId,
        string $observaciones,
        ?string $qrToken,
    ): array {
        if (!in_array($tipo, ['ingreso', 'egreso'], true)) {
            return ['ok' => false, 'error' => 'El movimiento es ingreso o egreso.'];
        }
        if (!in_array($contexto, ['club', 'varadero'], true)) {
            return ['ok' => false, 'error' => 'El lugar es club o varadero.'];
        }
        if ($tipo === 'egreso' && !in_array($destino, ['amarra', 'socio'], true)) {
            return ['ok' => false, 'error' => 'El egreso va a la amarra o se lo lleva el socio.'];
        }
        if ($tipo === 'ingreso') {
            $destino = null;
        }
        if ($numeroSocio < 1 || $embarcacionId < 1) {
            return ['ok' => false, 'error' => 'Indicá el socio y la embarcación.'];
        }
        if (!$this->esDelSocio($numeroSocio, $embarcacionId)) {
            return ['ok' => false, 'error' => 'Esa embarcación no está vinculada a ese número de socio.'];
        }
        if ($origen === 'qr') {
            $guardado = $this->connection->fetchOne(
                'SELECT qr_token FROM cnb_app.embarcaciones WHERE id = ?',
                [$embarcacionId]
            );
            if ($guardado === false || $guardado === null || $guardado === '' || !hash_equals((string) $guardado, (string) $qrToken)) {
                return ['ok' => false, 'error' => 'El código QR no coincide con la embarcación.'];
            }
        }

        $cuando = $this->cuando($ocurridoEn);
        if (isset($cuando['error'])) {
            return ['ok' => false, 'error' => $cuando['error']];
        }
        $fotoLimpia = $this->foto($foto);
        if (isset($fotoLimpia['error'])) {
            return ['ok' => false, 'error' => $fotoLimpia['error']];
        }

        $username = strtolower(trim($username));
        if ($username === '') {
            return ['ok' => false, 'error' => 'Falta el usuario que valida el movimiento.'];
        }

        $id = $this->connection->fetchOne(
            'INSERT INTO cnb_app.movimientos_embarcacion
                (numero_socio, embarcacion_id, reserva_varadero_id, tipo, contexto, destino,
                 ocurrido_en, validado_por, validado_username, foto, origen, observaciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id',
            [
                $numeroSocio,
                $embarcacionId,
                $reservaId,
                $tipo,
                $contexto,
                $destino,
                $cuando['valor'],
                $usuarioId,
                $username,
                $fotoLimpia['foto'],
                $origen,
                $observaciones !== '' ? $observaciones : null,
            ]
        );

        $fila = $this->fila((int) $id);

        return ['ok' => true, 'data' => $fila ?? ['id' => (int) $id]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function delDia(?string $fecha): array
    {
        $tz = new \DateTimeZone(self::TZ);
        try {
            $inicio = new \DateTimeImmutable(($fecha !== null && $fecha !== '' ? $fecha : 'today').' 00:00:00', $tz);
        } catch (\Exception) {
            $inicio = new \DateTimeImmutable('today', $tz);
        }
        $fin = $inicio->modify('+1 day');
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.id, m.numero_socio, m.embarcacion_id, m.reserva_varadero_id,
                    m.tipo, m.contexto, m.destino, m.ocurrido_en, m.validado_username,
                    m.origen, m.observaciones, (m.foto IS NOT NULL AND m.foto <> '') AS tiene_foto,
                    e.nombre AS embarcacion, e.matricula,
                    trim(s.apellido || ', ' || s.nombre) AS socio
             FROM cnb_app.movimientos_embarcacion m
             INNER JOIN cnb_app.embarcaciones e ON e.id = m.embarcacion_id
             INNER JOIN cnb_app.socios s ON s.numero_socio = m.numero_socio
             WHERE m.ocurrido_en >= ? AND m.ocurrido_en < ?
             ORDER BY m.ocurrido_en DESC, m.id DESC",
            [$inicio->format('c'), $fin->format('c')]
        );

        foreach ($rows as &$row) {
            $row['tiene_foto'] = filter_var($row['tiene_foto'], FILTER_VALIDATE_BOOLEAN);
            $row['ocurrido_en'] = (new \DateTimeImmutable((string) $row['ocurrido_en']))
                ->setTimezone($tz)
                ->format('Y-m-d H:i');
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array{numero_socio: int, embarcacion_id: int, qr_token: string}|array{error: string}
     */
    public function leerCodigo(string $codigo): array
    {
        $partes = explode('|', trim($codigo));
        if (count($partes) !== 4 || $partes[0] !== 'CNB1' || !ctype_digit($partes[1]) || !ctype_digit($partes[2]) || $partes[3] === '') {
            return ['error' => 'El código QR no es del club.'];
        }

        return [
            'numero_socio' => (int) $partes[1],
            'embarcacion_id' => (int) $partes[2],
            'qr_token' => $partes[3],
        ];
    }

    private function esDelSocio(int $numeroSocio, int $embarcacionId): bool
    {
        $propia = $this->connection->fetchOne(
            'SELECT 1 FROM cnb_app.socio_embarcacion WHERE numero_socio = ? AND embarcacion_id = ?
             UNION
             SELECT 1 FROM cnb_app.embarcaciones WHERE id = ? AND numero_socio = ?',
            [$numeroSocio, $embarcacionId, $embarcacionId, $numeroSocio]
        );

        return (bool) $propia;
    }

    /**
     * @return array{valor: string}|array{error: string}
     */
    private function cuando(?string $raw): array
    {
        $tz = new \DateTimeZone(self::TZ);
        try {
            $cuando = $raw === null || trim($raw) === ''
                ? new \DateTimeImmutable('now', $tz)
                : new \DateTimeImmutable(trim($raw), $tz);
        } catch (\Exception) {
            return ['error' => 'La fecha y hora no son válidas.'];
        }
        $ahora = new \DateTimeImmutable('now', $tz);
        if ($cuando > $ahora->modify('+1 day')) {
            return ['error' => 'La fecha no puede quedar en el futuro.'];
        }
        if ($cuando < $ahora->modify('-30 days')) {
            return ['error' => 'La fecha queda demasiado atrás.'];
        }

        return ['valor' => $cuando->format('c')];
    }

    /**
     * @return array{foto: string|null}|array{error: string}
     */
    private function foto(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['foto' => null];
        }
        $limpio = trim($raw);
        if (str_contains($limpio, ',')) {
            $limpio = substr($limpio, (int) strrpos($limpio, ',') + 1);
        }
        $limpio = preg_replace('/\s+/', '', $limpio) ?? '';
        $bin = base64_decode($limpio, true);
        if ($bin === false || strlen($bin) < 32 || strlen($bin) > 1500000) {
            return ['error' => 'La foto no es válida o supera el tamaño permitido.'];
        }

        return ['foto' => base64_encode($bin)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fila(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT m.id, m.numero_socio, m.embarcacion_id, m.tipo, m.contexto, m.destino,
                    m.ocurrido_en, m.validado_username, m.origen, m.observaciones,
                    (m.foto IS NOT NULL AND m.foto <> '') AS tiene_foto,
                    e.nombre AS embarcacion
             FROM cnb_app.movimientos_embarcacion m
             INNER JOIN cnb_app.embarcaciones e ON e.id = m.embarcacion_id
             WHERE m.id = ?",
            [$id]
        );

        return $row === false ? null : $row;
    }
}
