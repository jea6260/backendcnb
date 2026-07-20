<?php

namespace App\Controller;

use App\Service\ResourceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/socio', priority: 10)]
final class SocioPortalController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
    ) {
    }

    #[Route('/register', name: 'api_socio_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $numeroSocioRaw = trim((string) ($data['numero_socio'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($numeroSocioRaw === '' || $email === '' || $password === '') {
            return $this->json(['error' => 'numero_socio, email y password son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        if (!ctype_digit($numeroSocioRaw) || strlen($numeroSocioRaw) > 5) {
            return $this->json(
                ['error' => 'numero_socio debe ser numerico de hasta 5 digitos'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $numeroSocio = (int) $numeroSocioRaw;
        if ($numeroSocio < 1 || $numeroSocio > 99999) {
            return $this->json(
                ['error' => 'numero_socio debe estar entre 1 y 99999'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (strlen($password) < 8) {
            return $this->json(['error' => 'La clave debe tener al menos 8 caracteres'], Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Email invalido'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $socio = $this->connection->fetchAssociative(
                'SELECT numero_socio, nombre, apellido, email, telefono, documento, estado
                 FROM cnb_app.socios
                 WHERE numero_socio = ?',
                [$numeroSocio]
            );

            if (!$socio) {
                return $this->json(['error' => 'Numero de socio no encontrado'], Response::HTTP_NOT_FOUND);
            }

            if (($socio['estado'] ?? '') !== 'activo') {
                return $this->json(['error' => 'Socio no activo'], Response::HTTP_FORBIDDEN);
            }

            $socioEmail = trim((string) ($socio['email'] ?? ''));
            if ($socioEmail === '') {
                return $this->json(
                    ['error' => 'El socio no tiene email registrado. Contacte a secretaria.'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (strcasecmp($socioEmail, $email) !== 0) {
                return $this->json(
                    ['error' => 'El email no coincide con el registrado para ese socio'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $existingAccess = $this->connection->fetchOne(
                'SELECT id FROM cnb_app.socio_acceso WHERE numero_socio = ? OR LOWER(email) = LOWER(?)',
                [$socio['numero_socio'], $email]
            );

            if ($existingAccess) {
                return $this->json(['error' => 'Ya existe un usuario para este socio o email'], Response::HTTP_CONFLICT);
            }

            $this->connection->executeStatement(
                'INSERT INTO cnb_app.socio_acceso (numero_socio, email, password_hash)
                 VALUES (?, ?, ?)',
                [
                    $socio['numero_socio'],
                    strtolower($email),
                    password_hash($password, PASSWORD_DEFAULT),
                ]
            );

            $token = bin2hex(random_bytes(32));
            $expiresAt = (new \DateTimeImmutable('+30 days'))->format('c');

            $this->connection->insert('cnb_app.socio_sesiones', [
                'numero_socio' => $socio['numero_socio'],
                'token' => $token,
                'expires_at' => $expiresAt,
            ]);

            $row = $this->connection->fetchAssociative(
                'SELECT sa.*, s.numero_socio, s.nombre, s.apellido, s.telefono, s.documento, s.estado AS socio_estado
                 FROM cnb_app.socio_acceso sa
                 INNER JOIN cnb_app.socios s ON s.numero_socio = sa.numero_socio
                 WHERE sa.numero_socio = ?',
                [$socio['numero_socio']]
            );

            return $this->json([
                'data' => [
                    'token' => $token,
                    'expires_at' => $expiresAt,
                    'socio' => $this->socioPayload($row ?: []),
                ],
            ], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/login', name: 'api_socio_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json(['error' => 'email y password son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT sa.*, s.numero_socio, s.nombre, s.apellido, s.telefono, s.documento, s.estado AS socio_estado
                 FROM cnb_app.socio_acceso sa
                 INNER JOIN cnb_app.socios s ON s.numero_socio = sa.numero_socio
                 WHERE LOWER(sa.email) = LOWER(?)',
                [$email]
            );

            if (!$row || !password_verify($password, (string) $row['password_hash'])) {
                return $this->json(['error' => 'Credenciales invalidas'], Response::HTTP_UNAUTHORIZED);
            }

            if (($row['socio_estado'] ?? '') !== 'activo') {
                return $this->json(['error' => 'Socio no activo'], Response::HTTP_FORBIDDEN);
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = (new \DateTimeImmutable('+30 days'))->format('c');

            $this->connection->insert('cnb_app.socio_sesiones', [
                'numero_socio' => $row['numero_socio'],
                'token' => $token,
                'expires_at' => $expiresAt,
            ]);

            return $this->json([
                'data' => [
                    'token' => $token,
                    'expires_at' => $expiresAt,
                    'socio' => $this->socioPayload($row),
                ],
            ]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/me', name: 'api_socio_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        return $this->json(['data' => $socio]);
    }

    #[Route('/password', name: 'api_socio_password', methods: ['PATCH', 'PUT'])]
    public function changePassword(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $data = $this->jsonBody($request);
        $current = (string) ($data['password_actual'] ?? '');
        $next = (string) ($data['password_nueva'] ?? '');

        if ($current === '' || strlen($next) < 8) {
            return $this->json(['error' => 'password_actual y password_nueva (min 8) son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $hash = $this->connection->fetchOne(
                'SELECT password_hash FROM cnb_app.socio_acceso WHERE numero_socio = ?',
                [$socio['numero_socio']]
            );

            if (!$hash || !password_verify($current, (string) $hash)) {
                return $this->json(['error' => 'Clave actual incorrecta'], Response::HTTP_UNAUTHORIZED);
            }

            $this->connection->update('cnb_app.socio_acceso', [
                'password_hash' => password_hash($next, PASSWORD_DEFAULT),
                'updated_at' => date('c'),
            ], ['numero_socio' => $socio['numero_socio']]);

            return $this->json(['data' => ['ok' => true]]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/biometric', name: 'api_socio_biometric', methods: ['PATCH', 'PUT'])]
    public function biometric(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $enabled = (bool) ($this->jsonBody($request)['habilitado'] ?? false);

        try {
            $this->connection->update('cnb_app.socio_acceso', [
                'biometric_habilitado' => $enabled,
                'updated_at' => date('c'),
            ], ['numero_socio' => $socio['numero_socio']]);

            return $this->json(['data' => ['biometric_habilitado' => $enabled]]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/novedades', name: 'api_socio_novedades', methods: ['GET'])]
    public function novedades(Request $request): JsonResponse
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cnb_app.novedades WHERE activo = TRUE ORDER BY publicado_at DESC LIMIT 100'
        );

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/camaras', name: 'api_socio_camaras', methods: ['GET'])]
    public function camaras(Request $request): JsonResponse
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cnb_app.camaras WHERE activa = TRUE ORDER BY orden ASC, id ASC'
        );

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/mediciones-nivel', name: 'api_socio_mediciones_nivel', methods: ['GET'])]
    public function medicionesNivel(Request $request): JsonResponse
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $limit = $request->query->getInt('limit', 200);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $desde = trim((string) $request->query->get('desde', ''));
        $hasta = trim((string) $request->query->get('hasta', ''));

        $sql = 'SELECT * FROM cnb_app.mediciones_nivel WHERE 1=1';
        $params = [];

        if ($desde !== '') {
            $sql .= ' AND fecha >= ?';
            $params[] = $desde;
        }
        if ($hasta !== '') {
            $sql .= ' AND fecha <= ?';
            $params[] = $hasta;
        }

        $sql .= ' ORDER BY fecha DESC LIMIT ?';
        $params[] = $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        // Ascendente para graficar evolucion temporal
        $rows = array_reverse($rows);

        $ultima = $rows === [] ? null : $this->registry->normalizeRow($rows[array_key_last($rows)]);

        return $this->json([
            'data' => [
                'ultima' => $ultima,
                'lecturas' => array_map($this->registry->normalizeRow(...), $rows),
            ],
        ]);
    }

    #[Route('/embarcaciones', name: 'api_socio_embarcaciones', methods: ['GET'])]
    public function embarcaciones(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cnb_app.embarcaciones WHERE numero_socio = ? ORDER BY nombre ASC',
            [$socio['numero_socio']]
        );

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/espacios-varadero', name: 'api_socio_espacios_varadero', methods: ['GET'])]
    public function espaciosVaradero(Request $request): JsonResponse
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cnb_app.espacios_varadero WHERE activo = TRUE ORDER BY codigo ASC'
        );

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/reservas-varadero', name: 'api_socio_reservas_index', methods: ['GET'])]
    public function reservasVaradero(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cnb_app.reservas_varadero WHERE numero_socio = ? ORDER BY fecha_inicio DESC',
            [$socio['numero_socio']]
        );

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/reservas-varadero', name: 'api_socio_reservas_create', methods: ['POST'])]
    public function crearReservaVaradero(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $data = $this->jsonBody($request);
        $required = ['embarcacion_id', 'espacio_id', 'fecha_inicio', 'fecha_fin'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return $this->json(['error' => $field . ' es obligatorio'], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $payload = [
                'numero_socio' => $socio['numero_socio'],
                'embarcacion_id' => (int) $data['embarcacion_id'],
                'espacio_id' => (int) $data['espacio_id'],
                'fecha_inicio' => $data['fecha_inicio'],
                'fecha_fin' => $data['fecha_fin'],
                'motivo' => $data['motivo'] ?? 'limpieza_mantenimiento',
                'observaciones' => $data['observaciones'] ?? null,
                'estado' => 'pendiente',
            ];

            $sql = 'INSERT INTO cnb_app.reservas_varadero (numero_socio, embarcacion_id, espacio_id, fecha_inicio, fecha_fin, motivo, observaciones, estado)
                    VALUES (:numero_socio, :embarcacion_id, :espacio_id, :fecha_inicio, :fecha_fin, :motivo, :observaciones, :estado) RETURNING *';
            $row = $this->connection->fetchAssociative($sql, $payload) ?: [];

            return $this->json(['data' => $this->registry->normalizeRow($row)], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/solicitudes-reunion-cd', name: 'api_socio_reunion_cd', methods: ['POST'])]
    public function solicitudReunionCd(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $data = $this->jsonBody($request);
        if (empty($data['asunto']) || empty($data['mensaje'])) {
            return $this->json(['error' => 'asunto y mensaje son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $payload = [
                'numero_socio' => $socio['numero_socio'],
                'asunto' => $data['asunto'],
                'mensaje' => $data['mensaje'],
                'fecha_preferida' => $data['fecha_preferida'] ?? null,
                'estado' => 'pendiente',
            ];
            $row = $this->connection->fetchAssociative(
                'INSERT INTO cnb_app.solicitudes_reunion_cd (numero_socio, asunto, mensaje, fecha_preferida, estado)
                 VALUES (:numero_socio, :asunto, :mensaje, :fecha_preferida, :estado) RETURNING *',
                $payload
            ) ?: [];

            return $this->json(['data' => $this->registry->normalizeRow($row)], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/notas-cd', name: 'api_socio_notas_cd_create', methods: ['POST'])]
    public function notaCd(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $data = $this->jsonBody($request);
        if (empty($data['asunto']) || empty($data['mensaje'])) {
            return $this->json(['error' => 'asunto y mensaje son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $payload = [
                'numero_socio' => $socio['numero_socio'],
                'asunto' => $data['asunto'],
                'mensaje' => $data['mensaje'],
                'estado' => 'recibida',
            ];
            $row = $this->connection->fetchAssociative(
                'INSERT INTO cnb_app.notas_cd (numero_socio, asunto, mensaje, estado)
                 VALUES (:numero_socio, :asunto, :mensaje, :estado) RETURNING *',
                $payload
            ) ?: [];

            return $this->json(['data' => $this->registry->normalizeRow($row)], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/notas-cd', name: 'api_socio_notas_cd_index', methods: ['GET'])]
    public function notasCdIndex(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cnb_app.notas_cd WHERE numero_socio = ? ORDER BY created_at DESC',
            [$socio['numero_socio']]
        );

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/facial/enroll', name: 'api_socio_facial_enroll', methods: ['POST'])]
    public function facialEnroll(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $image = (string) ($this->jsonBody($request)['imagen_base64'] ?? '');
        if ($image === '') {
            return $this->json(['error' => 'imagen_base64 es obligatoria'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->connection->update('cnb_app.socio_acceso', [
                'facial_reference' => $image,
                'updated_at' => date('c'),
            ], ['numero_socio' => $socio['numero_socio']]);

            return $this->json(['data' => ['enrolled' => true]]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/portones/apertura', name: 'api_socio_porton_apertura', methods: ['POST'])]
    public function aperturaPorton(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $data = $this->jsonBody($request);
        $porton = trim((string) ($data['porton'] ?? 'principal'));
        $image = (string) ($data['imagen_base64'] ?? '');

        if ($image === '') {
            return $this->json(['error' => 'imagen_base64 es obligatoria para verificacion facial'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $reference = $this->connection->fetchOne(
                'SELECT facial_reference FROM cnb_app.socio_acceso WHERE numero_socio = ?',
                [$socio['numero_socio']]
            );

            $score = $this->compareFaces((string) $reference, $image);
            $approved = $reference && $score >= 0.72;
            $resultado = $approved ? 'aprobado' : ($reference ? 'rechazado' : 'error');
            $observaciones = $reference
                ? ($approved ? 'Apertura autorizada por reconocimiento facial.' : 'Rostro no coincide con el registro.')
                : 'Debe registrar su rostro antes de usar portones.';

            $this->connection->insert('cnb_app.accesos_porton', [
                'numero_socio' => $socio['numero_socio'],
                'porton' => $porton,
                'resultado' => $resultado,
                'puntaje_facial' => $score,
                'observaciones' => $observaciones,
            ]);

            if (!$reference) {
                return $this->json(['error' => $observaciones], Response::HTTP_PRECONDITION_FAILED);
            }

            if (!$approved) {
                return $this->json([
                    'error' => $observaciones,
                    'data' => ['puntaje_facial' => $score, 'resultado' => $resultado],
                ], Response::HTTP_FORBIDDEN);
            }

            return $this->json([
                'data' => [
                    'resultado' => $resultado,
                    'puntaje_facial' => $score,
                    'porton' => $porton,
                    'mensaje' => 'Porton ' . $porton . ' abierto correctamente.',
                ],
            ]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function socioPayload(array $row): array
    {
        return [
            'id' => (int) ($row['numero_socio'] ?? 0),
            'numero_socio' => isset($row['numero_socio']) ? (int) $row['numero_socio'] : null,
            'nombre' => $row['nombre'] ?? null,
            'apellido' => $row['apellido'] ?? null,
            'email' => $row['email'] ?? null,
            'telefono' => $row['telefono'] ?? null,
            'documento' => $row['documento'] ?? null,
            'estado' => $row['socio_estado'] ?? 'activo',
            'biometric_habilitado' => (bool) ($row['biometric_habilitado'] ?? false),
            'facial_registrado' => !empty($row['facial_reference']),
        ];
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function authenticate(Request $request): array|JsonResponse
    {
        $header = $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->json(['error' => 'Token requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return $this->json(['error' => 'Token invalido'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT sa.*, s.numero_socio, s.nombre, s.apellido, s.telefono, s.documento, s.estado AS socio_estado
                 FROM cnb_app.socio_sesiones ss
                 INNER JOIN cnb_app.socio_acceso sa ON sa.numero_socio = ss.numero_socio
                 INNER JOIN cnb_app.socios s ON s.numero_socio = ss.numero_socio
                 WHERE ss.token = ? AND ss.expires_at > NOW()',
                [$token]
            );

            if (!$row) {
                return $this->json(['error' => 'Sesion expirada o invalida'], Response::HTTP_UNAUTHORIZED);
            }

            return $this->socioPayload($row);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    private function compareFaces(string $reference, string $probe): float
    {
        if ($reference === '' || $probe === '') {
            return 0.0;
        }

        $ref = $this->imageFingerprint($reference);
        $prb = $this->imageFingerprint($probe);

        if ($ref === [] || $prb === []) {
            return 0.0;
        }

        $matches = 0;
        $total = min(count($ref), count($prb));
        for ($i = 0; $i < $total; ++$i) {
            if (abs($ref[$i] - $prb[$i]) <= 12) {
                ++$matches;
            }
        }

        return round($matches / max($total, 1), 2);
    }

    /**
     * @return list<int>
     */
    private function imageFingerprint(string $base64): array
    {
        $raw = $base64;
        if (str_contains($base64, ',')) {
            $raw = (string) substr($base64, strpos($base64, ',') + 1);
        }

        $binary = base64_decode($raw, true);
        if ($binary === false || $binary === '') {
            return [];
        }

        $hash = hash('sha256', $binary);
        $fingerprint = [];
        for ($i = 0; $i < 32; ++$i) {
            $fingerprint[] = hexdec(substr($hash, $i * 2, 2));
        }

        return $fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function dbalError(DbalException $exception): JsonResponse
    {
        return $this->json([
            'error' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
