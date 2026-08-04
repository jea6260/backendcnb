<?php

namespace App\Service;

/**
 * Dispara relays de hardware (timbre / portones) via webhook HTTP opcional.
 * Configurar RELAY_TIMBRE_URL y RELAY_PORTON_URL en el entorno.
 */
final class RelayService
{
    /**
     * @param array<string, mixed> $payload
     * @return array{triggered: bool, target: string|null, detail: string}
     */
    public function trigger(string $tipo, array $payload = []): array
    {
        $url = match ($tipo) {
            'timbre_marineros' => $this->env('RELAY_TIMBRE_URL'),
            'porton' => $this->env('RELAY_PORTON_URL'),
            default => null,
        };

        if (!$url) {
            return [
                'triggered' => false,
                'target' => null,
                'detail' => 'Relay simulado (sin URL configurada). Evento registrado.',
            ];
        }

        $body = json_encode(array_merge(['tipo' => $tipo, 'ts' => date(DATE_ATOM)], $payload), JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'triggered' => false,
                'target' => $url,
                'detail' => 'No se pudo inicializar cURL',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return [
                'triggered' => false,
                'target' => $url,
                'detail' => 'Error al disparar relay: ' . ($error !== '' ? $error : 'sin respuesta'),
            ];
        }

        return [
            'triggered' => $status >= 200 && $status < 300,
            'target' => $url,
            'detail' => 'Relay HTTP status ' . $status,
        ];
    }

    private function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
