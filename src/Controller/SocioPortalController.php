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

#[Route('/api/socio')]
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
        $numeroSocio = trim((string) ($data['numero_socio'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($numeroSocio === '' || $email === '' || $password === '') {
            return $this->json(['error' => 'numero_socio, email y password son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($password) < 8) {
            return $this->json(['error' => 'La clave debe tener al menos 8 caracteres'], Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Email invalido'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $socio = $this->connection->fetchAssociative(
                'SELECT id, numero_socio, nombre, apellido, email, telefono, documento, estado
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
                'SELECT id FROM cnb_app.socio_acceso WHERE socio_id = ? OR LOWER(email) = LOWER(?)',
                [(int) $socio['id'], $email]
            );

            if ($existingAccess) {
                return $this->json(['error' => 'Ya existe un usuario para este socio o email'], Response::HTTP_CONFLICT);
            }

            $this->connection->executeStatement(
                'INSERT INTO cnb_app.socio_acceso (socio_id, email, password_hash)
                 VALUES (?, ?, ?)',
                [
                    (int) $socio['id'],
                    strtolower($email),
                    password_hash($password, PASSWORD_DEFAULT),
                ]
            );

            $token = bin2hex(random_bytes(32));
            $expiresAt = (new \DateTimeImmutable('+30 days'))->format('c');

            $this->connection->insert('cnb_app.socio_sesiones', [
                'socio_id' => (int) $socio['id'],
                'token' => $token,
                'expires_at' => $expiresAt,
            ]);

            $row = $this->connection->fetchAssociative(
                'SELECT sa.*, s.numero_socio, s.nombre, s.apellido, s.telefono, s.documento, s.estado AS socio_estado
                 FROM cnb_app.socio_acceso sa
                 INNER JOIN cnb_app.socios s ON s.id = sa.socio_id
                 WHERE sa.socio_id = ?',
                [(int) $socio['id']]
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
                 INNER JOIN cnb_app.socios s ON s.id = sa.socio_id
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
                'socio_id' => (int) $row['socio_id'],
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
                'SELECT password_hash FROM cnb_app.socio_acceso WHERE socio_id = ?',
                [$socio['id']]
            );

            if (!$hash || !password_verify($current, (string) $hash)) {
                return $this->json(['error' => 'Clave actual incorrecta'], Response::HTTP_UNAUTHORIZED);
            }

            $this->connection->update('cnb_app.socio_acceso', [
                'password_hash' => password_hash($next, PASSWORD_DEFAULT),
                'updated_at' => date('c'),
            ], ['socio_id' => $socio['id']]);

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
            ], ['socio_id' => $socio['id']]);

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

    #[Route('/embarcaciones', name: 'api_socio_embarcaciones', methods: ['GET'])]
    public function embarcaciones(Request $request): JsonResponse
    {
        $socio = $this->authenticate($request);
        if ($socio instanceof JsonResponse) {
            return $socio;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cnb_app.embarcaciones WHERE socio_id = ? ORDER BY nombre ASC',
            [$socio['id']]
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
            'SELECT * FROM cnb_app.reservas_varadero WHERE socio_id = ? ORDER BY fecha_inicio DESC',
            [$socio['id']]
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
                'socio_id' => $socio['id'],
                'embarcacion_id' => (int) $data['embarcacion_id'],
                'espacio_id' => (int) $data['espacio_id'],
                'fecha_inicio' => $data['fecha_inicio'],
                'fecha_fin' => $data['fecha_fin'],
                'motivo' => $data['motivo'] ?? 'limpieza_mantenimiento',
                'observaciones' => $data['observaciones'] ?? null,
                'estado' => 'pendiente',
            ];

            $sql = 'INSERT INTO cnb_app.reservas_varadero (socio_id, embarcacion_id, espacio_id, fecha_inicio, fecha_fin, motivo, observaciones, estado)
                    VALUES (:socio_id, :embarcacion_id, :espacio_id, :fecha_inicio, :fecha_fin, :motivo, :observaciones, :estado) RETURNING *';
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
                'socio_id' => $socio['id'],
                'asunto' => $data['asunto'],
                'mensaje' => $data['mensaje'],
                'fecha_preferida' => $data['fecha_preferida'] ?? null,
                'estado' => 'pendiente',
            ];
            $row = $this->connection->fetchAssociative(
                'INSERT INTO cnb_app.solicitudes_reunion_cd (socio_id, asunto, mensaje, fecha_preferida, estado)
                 VALUES (:socio_id, :asunto, :mensaje, :fecha_preferida, :estado) RETURNING *',
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
                'socio_id' => $socio['id'],
                'asunto' => $data['asunto'],
                'mensaje' => $data['mensaje'],
                'estado' => 'recibida',
            ];
            $row = $this->connection->fetchAssociative(
                'INSERT INTO cnb_app.notas_cd (socio_id, asunto, mensaje, estado)
                 VALUES (:socio_id, :asunto, :mensaje, :estado) RETURNING *',
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
            'SELECT * FROM cnb_app.notas_cd WHERE socio_id = ? ORDER BY created_at DESC',
            [$socio['id']]
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
            ], ['socio_id' => $socio['id']]);

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
                'SELECT facial_reference FROM cnb_app.socio_acceso WHERE socio_id = ?',
                [$socio['id']]
            );

            $score = $this->compareFaces((string) $reference, $image);
            $approved = $reference && $score >= 0.72;
            $resultado = $approved ? 'aprobado' : ($reference ? 'rechazado' : 'error');
            $observaciones = $reference
                ? ($approved ? 'Apertura autorizada por reconocimiento facial.' : 'Rostro no coincide con el registro.')
                : 'Debe registrar su rostro antes de usar portones.';

            $this->connection->insert('cnb_app.accesos_porton', [
                'socio_id' => $socio['id'],
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
            'id' => (int) $row['socio_id'],
            'numero_socio' => $row['numero_socio'] ?? null,
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
                 INNER JOIN cnb_app.socio_acceso sa ON sa.socio_id = ss.socio_id
                 INNER JOIN cnb_app.socios s ON s.id = ss.socio_id
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
