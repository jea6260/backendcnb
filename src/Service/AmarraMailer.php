<?php

namespace App\Service;

/**
 * Avisa a administración cada solicitud de amarra.
 * Requiere AMARRA_SMTP_HOST, AMARRA_SMTP_USER y AMARRA_SMTP_PASSWORD.
 */
final class AmarraMailer
{
    private const DESTINO = 'administracion@clubnauticobariloche.com.ar';

    /**
     * @param array<string, mixed> $fila
     */
    public function enviar(array $fila): ?string
    {
        $host = $this->env('AMARRA_SMTP_HOST');
        $user = $this->env('AMARRA_SMTP_USER');
        $password = $this->env('AMARRA_SMTP_PASSWORD');
        if ($host === null || $user === null || $password === null) {
            return 'Falta configurar el correo del servidor.';
        }

        $to = $this->env('AMARRA_MAIL_TO') ?? self::DESTINO;
        $from = $this->env('AMARRA_MAIL_FROM') ?? $user;
        $port = (int) ($this->env('AMARRA_SMTP_PORT') ?? '587');
        $subject = sprintf(
            'Solicitud de amarra · socio %s · %s',
            $fila['numero_socio'] ?? '',
            $fila['socio'] ?? ''
        );

        try {
            $this->smtp($host, $port, $user, $password, $from, $to, $subject, $this->cuerpo($fila));
        } catch (\Throwable $exception) {
            return 'No se pudo enviar el mail a administración.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fila
     */
    private function cuerpo(array $fila): string
    {
        $lineas = [
            'Solicitud de amarra',
            '',
            'Socio: '.trim((string) ($fila['socio'] ?? '')).' ('.$fila['numero_socio'].')',
            'Fecha de solicitud: '.($fila['fecha_solicitud'] ?? ''),
            'Fecha de ingreso: '.($fila['fecha_ingreso_socio'] ?? ''),
            'Modalidad: '.($fila['modalidad'] ?? ''),
            'Embarcación: '.($fila['embarcacion'] ?? ''),
            'Metros comprados: '.($fila['metros_comprados'] ?? ''),
            'Compra o cambio de embarcación: '.(!empty($fila['cambio_embarcacion']) ? 'sí' : 'no'),
            'Tipo: '.($fila['tipo_nombre'] ?? ''),
            'Destino solicitado: '.($fila['destino_solicitado'] ?? ''),
            'Según eslora: '.($fila['destino_por_eslora'] ?? 'sin medida'),
            'Destino disponible: '.($fila['destino_disponible'] ?? ''),
            'm2 necesarios: '.($fila['m2_necesarios'] ?? ''),
            'm2 disponibles: '.($fila['m2_disponibles'] ?? ''),
            'Prioridad: '.($fila['prioridad'] ?? ''),
            'Estado: '.($fila['estado'] ?? ''),
            'Notas: '.($fila['observaciones'] ?? ''),
        ];

        return implode("\r\n", $lineas);
    }

    private function smtp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $from,
        string $to,
        string $subject,
        string $body,
    ): void {
        $remote = ($port === 465 ? 'ssl://' : 'tcp://').$host.':'.$port;
        $fp = stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if ($fp === false) {
            throw new \RuntimeException($errstr !== '' ? $errstr : 'sin conexión SMTP');
        }
        stream_set_timeout($fp, 15);

        try {
            $this->expect($fp, [220]);
            $this->cmd($fp, 'EHLO clubnauticobariloche.com.ar', [250]);
            if ($port !== 465) {
                $this->cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('TLS');
                }
                $this->cmd($fp, 'EHLO clubnauticobariloche.com.ar', [250]);
            }
            $this->cmd($fp, 'AUTH LOGIN', [334]);
            $this->cmd($fp, base64_encode($user), [334]);
            $this->cmd($fp, base64_encode($password), [235]);
            $this->cmd($fp, 'MAIL FROM:<'.$from.'>', [250]);
            $this->cmd($fp, 'RCPT TO:<'.$to.'>', [250, 251]);
            $this->cmd($fp, 'DATA', [354]);
            $encodedSubject = '=?UTF-8?B?'.base64_encode($subject).'?=';
            $message = "From: <$from>\r\nTo: <$to>\r\nSubject: $encodedSubject\r\n";
            $message .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
            $message .= preg_replace('/^\./m', '..', $body)."\r\n.\r\n";
            fwrite($fp, $message);
            $this->expect($fp, [250]);
            $this->cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param resource $fp
     * @param list<int> $ok
     */
    private function cmd($fp, string $command, array $ok): void
    {
        fwrite($fp, $command."\r\n");
        $this->expect($fp, $ok);
    }

    /**
     * @param resource $fp
     * @param list<int> $ok
     */
    private function expect($fp, array $ok): void
    {
        $line = '';
        while (($row = fgets($fp, 515)) !== false) {
            $line = $row;
            if (isset($row[3]) && $row[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException(trim($line) !== '' ? trim($line) : 'SMTP sin respuesta');
        }
    }

    private function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
