<?php

namespace App\Controller;

use App\Service\UsuarioAuthService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alta y login de empleados (marineros) con el mismo esquema user/pass + token que ovmovil v3.
 * La biometria no llega al servidor: solo se emite el token de sesion.
 */
#[Route('/api/empleado', priority: 10)]
final class EmpleadoAuthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UsuarioAuthService $usuarios,
    ) {
    }

    #[Route('/register', name: 'api_empleado_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $user = strtolower(trim((string) ($data['user'] ?? $data['username'] ?? $data['email'] ?? '')));
        $pass = (string) ($data['pass'] ?? $data['password'] ?? '');

        if ($user === '' || $pass === '') {
            return $this->json(['error' => 'user y pass son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($user, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'El usuario debe ser el email del marinero'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($pass) < 8) {
            return $this->json(['error' => 'La clave debe tener al menos 8 caracteres'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $marinero = $this->connection->fetchAssociative(
                'SELECT id, nombre, apellido, email, estado
                 FROM cnb_app.marineros
                 WHERE LOWER(email) = LOWER(?)
                 ORDER BY id
                 LIMIT 1',
                [$user]
            );

            if (!$marinero) {
                return $this->json(
                    ['error' => 'No hay un marinero activo con ese email. Carguelo en el padron antes de crear el usuario.'],
                    Response::HTTP_NOT_FOUND
                );
            }

            if (($marinero['estado'] ?? '') !== 'activo') {
                return $this->json(['error' => 'Marinero no activo'], Response::HTTP_FORBIDDEN);
            }

            $existing = $this->usuarios->findByUsername($user);
            if ($existing && (int) $existing['id'] > 0) {
                return $this->json(['error' => 'El nombre de usuario ya esta en uso.'], Response::HTTP_CONFLICT);
            }

            $usuarioId = $this->usuarios->ensureUser(
                $user,
                $this->usuarios->hashPassword($pass),
                'empleado',
                null,
                (int) $marinero['id'],
            );

            if ($usuarioId === null) {
                return $this->json(
                    ['error' => 'Falta la tabla de usuarios. Ejecutar sql/016_usuarios_auth.sql'],
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }

            $session = $this->usuarios->issueToken($usuarioId);
            if ($session === null) {
                return $this->json(
                    ['error' => 'No se pudo emitir el token'],
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }

            return $this->json([
                'status' => 'OK',
                'data' => [
                    'token' => $session['token'],
                    'expires_at' => $session['expires_at'],
                    'cambiar_clave' => false,
                    'usuario' => $this->usuarioPayload($usuarioId, $user, $marinero),
                ],
            ], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/login', name: 'api_empleado_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $user = strtolower(trim((string) ($data['user'] ?? $data['username'] ?? $data['email'] ?? '')));
        $pass = (string) ($data['pass'] ?? $data['password'] ?? '');

        if ($user === '' || $pass === '') {
            return $this->json(['error' => 'user y pass son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->usuarios->ensureSuperuser();
            $row = $this->cuentaParaIngreso($user, $pass);
            if (is_string($row)) {
                $status = $row === 'Socio no activo' ? Response::HTTP_FORBIDDEN : Response::HTTP_UNAUTHORIZED;

                return $this->json(['error' => $row], $status);
            }

            if (!$this->isTruthy($row['activo'] ?? false)) {
                return $this->json(['error' => 'Usuario inactivo'], Response::HTTP_FORBIDDEN);
            }

            $marinero = null;
            if (!empty($row['marinero_id'])) {
                $marinero = $this->connection->fetchAssociative(
                    'SELECT id, nombre, apellido, email, estado FROM cnb_app.marineros WHERE id = ?',
                    [(int) $row['marinero_id']]
                );
                if ($marinero && ($marinero['estado'] ?? '') !== 'activo') {
                    return $this->json(['error' => 'Marinero no activo'], Response::HTTP_FORBIDDEN);
                }
            }

            $session = $this->usuarios->issueToken((int) $row['id']);
            if ($session === null) {
                return $this->json(['error' => 'No se pudo emitir el token'], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return $this->json([
                'status' => 'OK',
                'data' => [
                    'token' => $session['token'],
                    'expires_at' => $session['expires_at'],
                    'cambiar_clave' => $this->isTruthy($row['cambiar_clave'] ?? false),
                    'usuario' => $this->usuarioPayload(
                        (int) $row['id'],
                        $user,
                        $marinero,
                        (string) $row['rol'],
                        isset($row['numero_socio']) ? (int) $row['numero_socio'] : null,
                    ),
                ],
            ]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/usuarios', name: 'api_empleado_usuarios', methods: ['POST'])]
    public function altaUsuario(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }
        if (!in_array($actor['perfil'] ?? '', ['administrador', 'superuser', 'operador'], true)) {
            return $this->json(['error' => 'Solo administración u operaciones pueden dar de alta personal.'], Response::HTTP_FORBIDDEN);
        }

        $data = $this->jsonBody($request);
        $email = (string) ($data['user'] ?? $data['email'] ?? '');
        $pass = (string) ($data['pass'] ?? $data['password'] ?? '');
        $perfil = (string) ($data['perfil'] ?? '');

        try {
            $error = $this->usuarios->crearPersonal($email, $pass, $perfil, (string) $actor['perfil']);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
        if ($error !== null) {
            $status = str_contains($error, 'ya tiene usuario') ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST;
            if (str_contains($error, 'padron')) {
                $status = Response::HTTP_NOT_FOUND;
            }

            return $this->json(['error' => $error], $status);
        }

        return $this->json(['status' => 'OK', 'data' => ['email' => strtolower(trim($email)), 'perfil' => $perfil]], Response::HTTP_CREATED);
    }

    #[Route('/me', name: 'api_empleado_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->authenticate($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->json(['data' => $user]);
    }

    #[Route('/password', name: 'api_empleado_password', methods: ['POST', 'PATCH', 'PUT'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->authenticate($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $data = $this->jsonBody($request);
        $current = (string) ($data['pass'] ?? $data['password_actual'] ?? '');
        $next = (string) ($data['nuevo'] ?? $data['password_nueva'] ?? '');

        if ($current === '' || strlen($next) < 8) {
            return $this->json(['error' => 'pass y nuevo (min 8) son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        $row = $this->usuarios->findByUsername((string) $user['username']);
        if (!$row || !$this->usuarios->verifyPassword($current, (string) $row['password_hash'])) {
            return $this->json(['error' => 'Clave actual incorrecta'], Response::HTTP_UNAUTHORIZED);
        }

        $this->usuarios->updatePassword((int) $user['id'], $next);

        return $this->json(['status' => 'OK', 'data' => ['ok' => true]]);
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
        $user = $this->usuarios->userFromToken($token);
        if (!$user || !in_array($user['rol'] ?? '', ['admin', 'empleado', 'socio'], true)) {
            return $this->json(['error' => 'Sesion expirada o invalida'], Response::HTTP_UNAUTHORIZED);
        }

        $marinero = null;
        if (!empty($user['marinero_id'])) {
            $marinero = $this->connection->fetchAssociative(
                'SELECT id, nombre, apellido, email, estado FROM cnb_app.marineros WHERE id = ?',
                [(int) $user['marinero_id']]
            );
        }

        return $this->usuarioPayload(
            (int) $user['id'],
            (string) $user['username'],
            $marinero,
            (string) $user['rol'],
            isset($user['numero_socio']) ? (int) $user['numero_socio'] : null,
        ) + [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'cambiar_clave' => (bool) $user['cambiar_clave'],
        ];
    }

    /**
     * @return array<string, mixed>|string
     */
    private function cuentaParaIngreso(string $user, string $pass): array|string
    {
        $row = $this->usuarios->findByUsername($user);
        if ($row && in_array($row['rol'] ?? '', ['admin', 'empleado', 'socio'], true)
            && $this->usuarios->verifyPassword($pass, (string) $row['password_hash'])) {
            return $row;
        }

        $acceso = $this->connection->fetchAssociative(
            'SELECT sa.numero_socio, sa.email, sa.password_hash, s.estado
             FROM cnb_app.socio_acceso sa
             INNER JOIN cnb_app.socios s ON s.numero_socio = sa.numero_socio
             WHERE LOWER(sa.email) = LOWER(?)',
            [$user]
        );
        if (!$acceso || !password_verify($pass, (string) $acceso['password_hash'])) {
            return 'Usuario o contraseña invalidos.';
        }
        if (!in_array($acceso['estado'] ?? '', ['activo', 'AO', 'MR', 'AA', 'VO', 'AE', 'HO'], true)) {
            return 'Socio no activo';
        }
        if ($row && ($row['rol'] ?? '') !== 'socio') {
            return 'Usuario o contraseña invalidos.';
        }
        if (!$row) {
            $id = $this->usuarios->ensureUser(
                $user,
                (string) $acceso['password_hash'],
                'socio',
                (int) $acceso['numero_socio'],
                null,
            );
            $row = $id === null ? null : $this->usuarios->findByUsername($user);
        }
        if (!$row) {
            return 'Usuario o contraseña invalidos.';
        }

        return $row;
    }

    /**
     * @param array<string, mixed>|null $marinero
     * @return array<string, mixed>
     */
    private function usuarioPayload(
        int $id,
        string $username,
        ?array $marinero,
        string $rol = 'empleado',
        ?int $numeroSocio = null,
    ): array {
        return [
            'id' => $id,
            'username' => $username,
            'rol' => $rol,
            'numero_socio' => $numeroSocio,
            'marinero_id' => isset($marinero['id']) ? (int) $marinero['id'] : null,
            'nombre' => $marinero['nombre'] ?? null,
            'apellido' => $marinero['apellido'] ?? null,
            'email' => $marinero['email'] ?? $username,
            'perfil' => $this->usuarios->perfil([
                'username' => $username,
                'rol' => $rol,
                'marinero_id' => $marinero['id'] ?? null,
            ]),
        ];
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

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function dbalError(DbalException $exception): JsonResponse
    {
        return $this->json([
            'error' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
