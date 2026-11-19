<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Alta y recuperacion de la cuenta web del socio.
 * La identidad inicial es el numero de socio mas el PIN del timbre.
 */
final class SocioCuentaService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UsuarioAuthService $usuarios,
    ) {
    }

    /**
     * @return array{id: int, username: string}|string
     */
    public function registrar(string $numero, string $pin, string $email, string $password): array|string
    {
        $email = strtolower(trim($email));
        $clave = $this->validarClave($password);
        if ($clave !== null) {
            return $clave;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'El mail no es válido.';
        }

        $socio = $this->socioPorNumeroYPin($numero, $pin);
        if (is_string($socio)) {
            return $socio;
        }

        if ($this->usuarios->findByUsername($email) !== null) {
            return 'Ese mail ya tiene cuenta. Entrá o recuperá la clave.';
        }

        try {
            $ya = $this->connection->fetchOne(
                'SELECT id FROM cnb_app.socio_acceso WHERE numero_socio = ? OR LOWER(email) = ?',
                [$socio['numero_socio'], $email]
            );
        } catch (DbalException $exception) {
            return $this->dbError($exception);
        }

        if ($ya) {
            return 'Ese socio o ese mail ya está registrado. Entrá o recuperá la clave.';
        }

        $hash = $this->usuarios->hashPassword($password);
        $this->connection->beginTransaction();
        try {
            $this->connection->insert('cnb_app.socio_acceso', [
                'numero_socio' => $socio['numero_socio'],
                'email' => $email,
                'password_hash' => $hash,
                'biometric_habilitado' => 'false',
                'tipo' => 'socio',
            ]);
            $id = $this->usuarios->ensureUser($email, $hash, 'socio', $socio['numero_socio'], null);
            if ($id === null) {
                throw new \RuntimeException('sin usuarios');
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            if ($exception instanceof DbalException) {
                return $this->dbError($exception);
            }

            return 'No se pudo crear la cuenta.';
        }

        return ['id' => $id, 'username' => $email];
    }

    /**
     * @return array{id: int, username: string}|string
     */
    public function recuperar(string $numero, string $pin, string $password, string $email = ''): array|string
    {
        $clave = $this->validarClave($password);
        if ($clave !== null) {
            return $clave;
        }

        $socio = $this->socioPorNumeroYPin($numero, $pin);
        if (is_string($socio)) {
            return $socio;
        }

        try {
            $cuenta = $this->connection->fetchAssociative(
                'SELECT sa.email, u.id
                 FROM cnb_app.socio_acceso sa
                 INNER JOIN cnb_app.usuarios u ON LOWER(u.username) = LOWER(sa.email)
                 WHERE sa.numero_socio = ? AND sa.tipo = ?',
                [$socio['numero_socio'], 'socio']
            );
        } catch (DbalException $exception) {
            return $this->dbError($exception);
        }

        if (!$cuenta) {
            return 'Ese socio todavía no tiene cuenta. Creala con el número y el PIN.';
        }

        $email = strtolower(trim($email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'El mail no es válido.';
        }

        $nuevoMail = $email !== '' ? $email : strtolower((string) $cuenta['email']);
        if ($nuevoMail !== strtolower((string) $cuenta['email'])) {
            $ocupado = $this->usuarios->findByUsername($nuevoMail);
            if ($ocupado && (int) $ocupado['id'] !== (int) $cuenta['id']) {
                return 'Ese mail ya está usado por otra cuenta.';
            }
        }

        $hash = $this->usuarios->hashPassword($password);
        $this->connection->beginTransaction();
        try {
            $this->connection->update('cnb_app.socio_acceso', [
                'email' => $nuevoMail,
                'password_hash' => $hash,
                'updated_at' => date('c'),
            ], ['numero_socio' => $socio['numero_socio']]);
            $this->connection->update('cnb_app.usuarios', [
                'username' => $nuevoMail,
                'password_hash' => $hash,
                'cambiar_clave' => 'false',
                'updated_at' => date('c'),
            ], ['id' => $cuenta['id']]);
            $this->connection->commit();
        } catch (DbalException $exception) {
            $this->connection->rollBack();

            return $this->dbError($exception);
        }

        return ['id' => (int) $cuenta['id'], 'username' => $nuevoMail];
    }

    public function cambiarCuenta(int $usuarioId, string $actual, string $nuevoMail, string $nuevaClave): ?string
    {
        $row = $this->usuarios->findById($usuarioId);
        if ($row === null || !$this->usuarios->verifyPassword($actual, (string) $row['password_hash'])) {
            return 'La contraseña actual no coincide.';
        }

        $nuevoMail = strtolower(trim($nuevoMail));
        if ($nuevoMail === '') {
            $nuevoMail = (string) $row['username'];
        } elseif (filter_var($nuevoMail, FILTER_VALIDATE_EMAIL) === false) {
            return 'El mail no es válido.';
        }

        if ($nuevaClave !== '') {
            $clave = $this->validarClave($nuevaClave);
            if ($clave !== null) {
                return $clave;
            }
        }

        if ($nuevoMail !== (string) $row['username']) {
            $ocupado = $this->usuarios->findByUsername($nuevoMail);
            if ($ocupado && (int) $ocupado['id'] !== $usuarioId) {
                return 'Ese mail ya está usado.';
            }
        }

        $hash = $nuevaClave !== '' ? $this->usuarios->hashPassword($nuevaClave) : (string) $row['password_hash'];
        $this->connection->beginTransaction();
        try {
            $this->connection->update('cnb_app.usuarios', [
                'username' => $nuevoMail,
                'password_hash' => $hash,
                'cambiar_clave' => 'false',
                'updated_at' => date('c'),
            ], ['id' => $usuarioId]);
            if (($row['rol'] ?? '') === 'socio' && $row['numero_socio'] !== null) {
                $this->connection->update('cnb_app.socio_acceso', [
                    'email' => $nuevoMail,
                    'password_hash' => $hash,
                    'updated_at' => date('c'),
                ], ['numero_socio' => $row['numero_socio']]);
            }
            $this->connection->commit();
        } catch (DbalException $exception) {
            $this->connection->rollBack();

            return $this->dbError($exception);
        }

        return null;
    }

    /**
     * @return array{numero_socio: int, nombre: string}|string
     */
    private function socioPorNumeroYPin(string $numero, string $pin): array|string
    {
        if (preg_match('/^[1-9][0-9]{0,4}$/', $numero) !== 1) {
            return 'El número de socio no es válido.';
        }
        if (preg_match('/^[0-9]{1,6}$/', $pin) !== 1) {
            return 'El PIN debe tener de 1 a 6 dígitos.';
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT numero_socio, nombre, apellido, estado, COALESCE(pin, 1234) AS pin
                 FROM cnb_app.socios WHERE numero_socio = ?',
                [(int) $numero]
            );
        } catch (DbalException $exception) {
            return $this->dbError($exception);
        }

        if ($row === false || (string) $row['estado'] !== 'activo' || (int) $row['pin'] !== (int) $pin) {
            return 'No coincide el número de socio con el PIN.';
        }

        return [
            'numero_socio' => (int) $row['numero_socio'],
            'nombre' => trim((string) $row['nombre'].' '.(string) $row['apellido']),
        ];
    }

    private function validarClave(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'La contraseña debe tener al menos 8 caracteres.';
        }
        if ($password === '1234') {
            return 'Esa contraseña no sirve.';
        }

        return null;
    }

    private function dbError(DbalException $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'socio_acceso_tipo') || str_contains($message, 'column "tipo"')) {
            return 'Falta actualizar la base. Ejecutar sql/020_socio_acceso_tipo.sql';
        }

        return 'No se pudo guardar la cuenta.';
    }
}
