<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Usuarios y sesiones al estilo de ovmovil v3 (usuario + clave, token con vencimiento).
 *
 * La referencia (UsuarioOvV3) emite un JWT Lexik con token_ttl de 86400 segundos y
 * hashea claves nuevas con password_hash(..., PASSWORD_BCRYPT). No se copia el
 * cifrado reversible ENCRYPTBYPASSPHRASE de SQL Server: la clave queda solo como bcrypt.
 * El token opaco se guarda como SHA-256; el valor en claro se devuelve una sola vez.
 */
final class UsuarioAuthService
{
    /** Igual que lexik_jwt_authentication.token_ttl de ovmovil v3. */
    public const TOKEN_TTL_SECONDS = 86400;

    public const SUPERUSER_USERNAME = 'clubnauticobariloche';

    private const SUPERUSER_PASSWORD = 'ClubNautico@2026';

    public const ADMIN_SESSION_ID = 'cnb_admin_id';

    public const ADMIN_SESSION_USERNAME = 'cnb_admin_username';

    public const ADMIN_SESSION_NEXT = 'cnb_admin_next';

    public const ADMIN_SESSION_ROL = 'cnb_rol';

    public const ADMIN_SESSION_SOCIO = 'cnb_numero_socio';

    public const ADMIN_SESSION_NOMBRE = 'cnb_socio_nombre';

    public const ADMIN_SESSION_PERFIL = 'cnb_perfil';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    /**
     * Crea el usuario si no existe. Si ya existe el username, actualiza el hash y el vinculo.
     *
     * @return int|null id de usuarios, o null si la tabla todavia no existe
     */
    public function ensureUser(
        string $username,
        string $passwordHash,
        string $rol,
        ?int $numeroSocio,
        ?int $marineroId,
        bool $cambiarClave = false,
    ): ?int {
        $username = strtolower(trim($username));

        try {
            $existing = $this->connection->fetchAssociative(
                'SELECT id FROM cnb_app.usuarios WHERE username = ?',
                [$username]
            );

            if ($existing) {
                $this->connection->update('cnb_app.usuarios', [
                    'password_hash' => $passwordHash,
                    'rol' => $rol,
                    'numero_socio' => $numeroSocio,
                    'marinero_id' => $marineroId,
                    'activo' => 'true',
                    'cambiar_clave' => $cambiarClave ? 'true' : 'false',
                    'updated_at' => date('c'),
                ], ['id' => $existing['id']]);

                return (int) $existing['id'];
            }

            $this->connection->insert('cnb_app.usuarios', [
                'username' => $username,
                'password_hash' => $passwordHash,
                'rol' => $rol,
                'numero_socio' => $numeroSocio,
                'marinero_id' => $marineroId,
                'activo' => 'true',
                'cambiar_clave' => $cambiarClave ? 'true' : 'false',
            ]);

            return (int) $this->connection->fetchOne(
                'SELECT id FROM cnb_app.usuarios WHERE username = ?',
                [$username]
            );
        } catch (DbalException $exception) {
            if ($this->isMissingRelation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @return array{token: string, expires_at: string}|null
     */
    public function issueToken(int $usuarioId): ?array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('+' . self::TOKEN_TTL_SECONDS . ' seconds'))->format('c');

        try {
            $this->connection->insert('cnb_app.usuario_sesiones', [
                'usuario_id' => $usuarioId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
            ]);
        } catch (DbalException $exception) {
            if ($this->isMissingRelation($exception)) {
                return null;
            }

            throw $exception;
        }

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userFromToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT u.id, u.username, u.rol, u.numero_socio, u.marinero_id,
                        u.activo, u.cambiar_clave, u.password_hash
                 FROM cnb_app.usuario_sesiones s
                 INNER JOIN cnb_app.usuarios u ON u.id = s.usuario_id
                 WHERE s.token_hash = ? AND s.expires_at > NOW()',
                [hash('sha256', $token)]
            );
        } catch (DbalException $exception) {
            if ($this->isMissingRelation($exception)) {
                return null;
            }

            throw $exception;
        }

        if (!$row || !$this->isTruthy($row['activo'] ?? false)) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['numero_socio'] = $row['numero_socio'] !== null ? (int) $row['numero_socio'] : null;
        $row['marinero_id'] = $row['marinero_id'] !== null ? (int) $row['marinero_id'] : null;
        $row['cambiar_clave'] = $this->isTruthy($row['cambiar_clave'] ?? false);
        unset($row['password_hash']);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, username, password_hash, rol, numero_socio, marinero_id, activo, cambiar_clave
                 FROM cnb_app.usuarios WHERE id = ?',
                [$id]
            );
        } catch (DbalException $exception) {
            if ($this->isMissingRelation($exception)) {
                return null;
            }

            throw $exception;
        }

        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['numero_socio'] = $row['numero_socio'] !== null ? (int) $row['numero_socio'] : null;

        return $row;
    }

    public function findByUsername(string $username): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, username, password_hash, rol, numero_socio, marinero_id, activo, cambiar_clave
                 FROM cnb_app.usuarios WHERE username = ?',
                [strtolower(trim($username))]
            );
        } catch (DbalException $exception) {
            if ($this->isMissingRelation($exception)) {
                return null;
            }

            throw $exception;
        }

        return $row ?: null;
    }

    public function schemaReady(): bool
    {
        try {
            $value = $this->connection->fetchOne("SELECT to_regclass('cnb_app.usuarios')");

            return is_string($value) && $value !== '';
        } catch (DbalException) {
            return false;
        }
    }

    /**
     * Crea el administrador indicado por el entorno, o actualiza su clave si no coincide.
     * No modifica un usuario que ya existe con otro rol.
     *
     * @return string|null mensaje de error; null si no habia nada que hacer o quedo listo
     */
    public function ensureAdmin(string $username, string $plainPassword): ?string
    {
        $username = strtolower(trim($username));
        if ($username === '' || $plainPassword === '') {
            return null;
        }

        if (!$this->schemaReady()) {
            return 'Falta la tabla de usuarios. Ejecutar sql/016_usuarios_auth.sql';
        }

        $existing = $this->findByUsername($username);
        if ($existing && ($existing['rol'] ?? '') !== 'admin') {
            return 'ADMIN_USERNAME ya existe con otro rol';
        }

        if (
            $existing
            && $this->isTruthy($existing['activo'] ?? false)
            && $this->verifyPassword($plainPassword, (string) ($existing['password_hash'] ?? ''))
        ) {
            return null;
        }

        $id = $this->ensureUser($username, $this->hashPassword($plainPassword), 'admin', null, null);

        return $id === null ? 'No se pudo preparar el usuario administrador' : null;
    }

    /**
     * El superusuario queda siempre disponible con la clave fija del club.
     */
    public function ensureSuperuser(): ?string
    {
        return $this->ensureAdmin(self::SUPERUSER_USERNAME, self::SUPERUSER_PASSWORD);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function perfil(array $user): string
    {
        $username = strtolower(trim((string) ($user['username'] ?? '')));
        if ($username === self::SUPERUSER_USERNAME) {
            return 'superuser';
        }

        $rol = (string) ($user['rol'] ?? '');
        if ($rol === 'admin') {
            return 'administrador';
        }
        if ($rol === 'socio') {
            return 'socio';
        }

        $marineroId = $user['marinero_id'] ?? null;
        if ($marineroId !== null && $marineroId !== '' && (int) $marineroId > 0) {
            return 'marinero';
        }

        return 'operador';
    }

    /**
     * @return array{ver: bool, editar: bool, borrar: bool, crear: bool}
     */
    public function permisoRecurso(string $perfil, string $resource): array
    {
        $config = ['tipos-embarcacion', 'estados-padron', 'ubicaciones', 'espacios-varadero', 'marineros', 'vehiculos', 'camaras'];
        $tareas = ['tareas', 'tarea-asignaciones', 'avances-tarea'];
        $verMarinero = ['embarcaciones', 'reservas-varadero', 'novedades', 'tareas', 'tarea-asignaciones', 'avances-tarea'];

        if (in_array($perfil, ['administrador', 'superuser'], true)) {
            return ['ver' => true, 'editar' => true, 'borrar' => true, 'crear' => true];
        }
        if ($perfil === 'operador') {
            $ok = !in_array($resource, $config, true);

            return ['ver' => $ok, 'editar' => $ok, 'borrar' => $ok, 'crear' => $ok];
        }
        if ($perfil === 'marinero') {
            $esTarea = in_array($resource, $tareas, true);

            return [
                'ver' => in_array($resource, $verMarinero, true),
                'editar' => $esTarea,
                'borrar' => false,
                'crear' => false,
            ];
        }

        return ['ver' => false, 'editar' => false, 'borrar' => false, 'crear' => false];
    }

    /**
     * Alta de personal del club. El marinero tiene que existir ya en el padron, con el mismo mail.
     *
     * @return string|null mensaje de error
     */
    public function crearPersonal(string $email, string $password, string $perfil, string $actorPerfil): ?string
    {
        $email = strtolower(trim($email));
        $actor = $actorPerfil === 'superuser' ? 'administrador' : $actorPerfil;
        if (!in_array($actor, ['administrador', 'operador'], true)) {
            return 'Solo administración u operaciones pueden dar de alta personal.';
        }
        if ($perfil === 'operador' && $actor !== 'administrador') {
            return 'Solo administración puede dar de alta un operador.';
        }
        if (!in_array($perfil, ['operador', 'marinero'], true)) {
            return 'El perfil debe ser operador o marinero.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < 8) {
            return 'Mail invalido o clave de menos de 8 caracteres.';
        }
        if ($this->findByUsername($email) !== null) {
            return 'Ese mail ya tiene usuario.';
        }

        $rol = 'empleado';
        $marineroId = null;
        if ($perfil === 'marinero') {
            try {
                $marinero = $this->connection->fetchAssociative(
                    'SELECT id, estado FROM cnb_app.marineros WHERE LOWER(email) = LOWER(?) ORDER BY id LIMIT 1',
                    [$email]
                );
            } catch (DbalException $exception) {
                if ($this->isMissingRelation($exception)) {
                    return 'Falta la tabla de marineros.';
                }
                throw $exception;
            }
            if (!$marinero) {
                return 'No hay un marinero activo con ese email. Carguelo en el padron antes de crear el usuario.';
            }
            if (($marinero['estado'] ?? '') !== 'activo') {
                return 'Marinero no activo';
            }
            $marineroId = (int) $marinero['id'];
        }

        $id = $this->ensureUser($email, $this->hashPassword($password), $rol, null, $marineroId, true);

        return $id === null ? 'No se pudo crear el usuario.' : null;
    }

    /**
     * @return array{id: int, username: string}|string
     */
    public function authenticateAdmin(string $username, string $password): array|string
    {
        $result = $this->authenticatePanel($username, $password);
        if (is_string($result)) {
            return $result;
        }
        if (($result['rol'] ?? '') !== 'admin') {
            return 'Usuario o contraseña invalidos.';
        }

        return [
            'id' => $result['id'],
            'username' => $result['username'],
        ];
    }

    /**
     * @return array{id: int, username: string, rol: string, numero_socio: ?int, cambiar_clave: bool}|string
     */
    public function authenticatePanel(string $username, string $password): array|string
    {
        $this->ensureSuperuser();

        if (trim($username) === '' || $password === '') {
            return 'Usuario o contraseña inválidos.';
        }

        if (!$this->schemaReady()) {
            return 'Falta la tabla de usuarios. Ejecutar sql/016_usuarios_auth.sql';
        }

        $row = $this->findByUsername($username);
        if (!$row || !$this->verifyPassword($password, (string) ($row['password_hash'] ?? ''))) {
            return 'Usuario o contraseña inválidos.';
        }

        if (!$this->isTruthy($row['activo'] ?? false)) {
            return 'Usuario inactivo.';
        }

        $rol = (string) ($row['rol'] ?? '');
        if (!in_array($rol, ['admin', 'empleado', 'socio'], true)) {
            return 'Usuario o contraseña inválidos.';
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'rol' => $rol,
            'numero_socio' => $row['numero_socio'] !== null ? (int) $row['numero_socio'] : null,
            'cambiar_clave' => $this->isTruthy($row['cambiar_clave'] ?? false),
        ];
    }

    /**
     * @return array{id: int, username: string}|null
     */
    public function findActiveAdminById(int $id): ?array
    {
        $user = $this->findActivePanelUser($id);
        if ($user === null || $user['rol'] !== 'admin') {
            return null;
        }

        return [
            'id' => $user['id'],
            'username' => $user['username'],
        ];
    }

    /**
     * @return array{id: int, username: string, rol: string, numero_socio: ?int}|null
     */
    public function findActivePanelUser(int $id): ?array
    {
        $row = $this->findById($id);
        if ($row === null || !$this->isTruthy($row['activo'] ?? false)) {
            return null;
        }

        $rol = (string) ($row['rol'] ?? '');
        if (!in_array($rol, ['admin', 'empleado', 'socio'], true)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'rol' => $rol,
            'numero_socio' => $row['numero_socio'] !== null ? (int) $row['numero_socio'] : null,
            'marinero_id' => isset($row['marinero_id']) && $row['marinero_id'] !== null ? (int) $row['marinero_id'] : null,
        ];
    }

    public function nombreVisible(array $user): string
    {
        if (($user['rol'] ?? '') === 'socio') {
            return $this->nombreSocio(isset($user['numero_socio']) ? (int) $user['numero_socio'] : null)
                ?? (string) ($user['username'] ?? '');
        }

        $marineroId = $user['marinero_id'] ?? null;
        if ($marineroId !== null && $marineroId !== '' && (int) $marineroId > 0) {
            try {
                $row = $this->connection->fetchAssociative(
                    'SELECT nombre, apellido FROM cnb_app.marineros WHERE id = ?',
                    [(int) $marineroId]
                );
            } catch (DbalException) {
                $row = false;
            }
            if ($row) {
                $nombre = trim((string) ($row['nombre'] ?? '') . ' ' . (string) ($row['apellido'] ?? ''));
                if ($nombre !== '') {
                    return $nombre;
                }
            }
        }

        $username = (string) ($user['username'] ?? '');
        $corte = strpos($username, '@');

        return $corte === false ? $username : substr($username, 0, $corte);
    }

    public function nombreSocio(?int $numero): ?string
    {
        if ($numero === null || $numero < 1) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT nombre, apellido FROM cnb_app.socios WHERE numero_socio = ?',
                [$numero]
            );
        } catch (DbalException) {
            return null;
        }

        if ($row === false) {
            return null;
        }

        $nombre = trim((string) ($row['nombre'] ?? '').' '.(string) ($row['apellido'] ?? ''));

        return $nombre !== '' ? $nombre : null;
    }

    public function updatePassword(int $usuarioId, string $plain): void
    {
        $this->connection->update('cnb_app.usuarios', [
            'password_hash' => $this->hashPassword($plain),
            'cambiar_clave' => 'false',
            'updated_at' => date('c'),
        ], ['id' => $usuarioId]);
    }

    private function isMissingRelation(DbalException $exception): bool
    {
        $message = $exception->getMessage() . ' ' . ($exception->getPrevious()?->getMessage() ?? '');

        return str_contains($message, '42P01')
            || str_contains($message, 'does not exist')
            || str_contains($message, 'no existe');
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 't', 'true', 'yes', 'on'], true);
    }
}
