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

    public const ADMIN_SESSION_ID = 'cnb_admin_id';

    public const ADMIN_SESSION_USERNAME = 'cnb_admin_username';

    public const ADMIN_SESSION_NEXT = 'cnb_admin_next';

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
     * @return array{id: int, username: string}|string
     */
    public function authenticateAdmin(string $username, string $password): array|string
    {
        if (trim($username) === '' || $password === '') {
            return 'Usuario o contraseña invalidos.';
        }

        if (!$this->schemaReady()) {
            return 'Falta la tabla de usuarios. Ejecutar sql/016_usuarios_auth.sql';
        }

        $row = $this->findByUsername($username);
        if (
            !$row
            || ($row['rol'] ?? '') !== 'admin'
            || !$this->verifyPassword($password, (string) ($row['password_hash'] ?? ''))
        ) {
            return 'Usuario o contraseña invalidos.';
        }

        if (!$this->isTruthy($row['activo'] ?? false)) {
            return 'Usuario inactivo';
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
        ];
    }

    /**
     * @return array{id: int, username: string}|null
     */
    public function findActiveAdminById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, username, rol, activo FROM cnb_app.usuarios WHERE id = ?',
                [$id]
            );
        } catch (DbalException $exception) {
            if ($this->isMissingRelation($exception)) {
                return null;
            }

            throw $exception;
        }

        if (!$row || ($row['rol'] ?? '') !== 'admin' || !$this->isTruthy($row['activo'] ?? false)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
        ];
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
