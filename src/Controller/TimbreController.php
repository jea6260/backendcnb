<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Misma pantalla que el ESP8266, publicada en la web.
 * No dispara el relay: encola el pedido para que el equipo lo tome.
 */
#[Route('/timbre')]
final class TimbreController extends AbstractController
{
    private const SESSION_SOCIO = 'timbre_numero_socio';
    private const SESSION_NOMBRE = 'timbre_nombre';
    private const SESSION_PIN = 'timbre_pin';
    private const SESSION_MUST = 'timbre_must_change';
    private const SESSION_TOKEN = 'timbre_token';

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('', name: 'timbre_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->loggedIn($session->get(self::SESSION_SOCIO))) {
            return $this->page('login');
        }
        if ($session->get(self::SESSION_MUST) === true) {
            return $this->redirectToRoute('timbre_pin');
        }

        return $this->page('timbre', [
            'nombre' => (string) $session->get(self::SESSION_NOMBRE, ''),
            'aviso' => $request->query->get('ok'),
        ]);
    }

    #[Route('/login', name: 'timbre_login', methods: ['POST'])]
    public function login(Request $request): Response
    {
        if (!$this->tokenOk($request)) {
            return $this->page('login', ['error' => 'La sesión venció. Volvé a intentar.'], Response::HTTP_BAD_REQUEST);
        }

        $numero = trim((string) $request->request->get('numeroSocio', ''));
        $pin = trim((string) $request->request->get('pin', ''));
        if (!$this->validSocio($numero) || !$this->validPin($pin)) {
            return $this->page('login', [
                'error' => 'Revisá el número de socio y el PIN.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT nombre, apellido, estado, COALESCE(pin, 1234) AS pin
                 FROM cnb_app.socios WHERE numero_socio = ?',
                [(int) $numero]
            );
        } catch (DbalException) {
            return $this->page('login', [
                'error' => 'No se pudo consultar la base de socios.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($row === false || (string) $row['estado'] !== 'activo' || (int) $row['pin'] !== (int) $pin) {
            return $this->page('login', [
                'error' => 'Credenciales inválidas. Revisá socio y PIN.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $nombre = trim((string) $row['nombre'].' '.(string) $row['apellido']);
        $session = $request->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_SOCIO, (int) $numero);
        $session->set(self::SESSION_NOMBRE, $nombre);
        $session->set(self::SESSION_PIN, (int) $pin);
        $session->set(self::SESSION_MUST, (int) $pin === 1234);

        if ((int) $pin === 1234) {
            return $this->redirectToRoute('timbre_pin');
        }

        return $this->redirectToRoute('timbre_home');
    }

    #[Route('/pin', name: 'timbre_pin', methods: ['GET', 'POST'])]
    public function changePin(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->loggedIn($session->get(self::SESSION_SOCIO))) {
            return $this->redirectToRoute('timbre_home');
        }

        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                $error = 'La sesión venció. Volvé a intentar.';
                $status = Response::HTTP_BAD_REQUEST;
            } else {
                $nuevo = trim((string) $request->request->get('nuevoPin', ''));
                $confirmar = trim((string) $request->request->get('confirmarPin', ''));
                if (!$this->validPin($nuevo) || !$this->validPin($confirmar)) {
                    $error = 'PIN inválido (1 a 6 dígitos numéricos).';
                } elseif ($nuevo !== $confirmar) {
                    $error = 'Los PINs no coinciden.';
                } elseif ($nuevo === '1234') {
                    $error = 'El nuevo PIN no puede ser 1234.';
                } else {
                    try {
                        $updated = $this->connection->executeStatement(
                            'UPDATE cnb_app.socios SET pin = ?
                             WHERE numero_socio = ? AND COALESCE(pin, 1234) = ?',
                            [(int) $nuevo, (int) $session->get(self::SESSION_SOCIO), (int) $session->get(self::SESSION_PIN)]
                        );
                    } catch (DbalException) {
                        $updated = 0;
                        $error = 'No se pudo actualizar el PIN.';
                        $status = Response::HTTP_SERVICE_UNAVAILABLE;
                    }
                    if ($updated === 1) {
                        $session->set(self::SESSION_PIN, (int) $nuevo);
                        $session->set(self::SESSION_MUST, false);

                        return $this->redirectToRoute('timbre_home');
                    }
                    if ($error === null) {
                        $error = 'No se actualizó el PIN. Entrá de nuevo.';
                        $status = Response::HTTP_CONFLICT;
                    }
                }
            }
        }

        return $this->page('pin', [
            'error' => $error,
            'obligatorio' => $session->get(self::SESSION_MUST) === true,
        ], $status);
    }

    #[Route('/tocar', name: 'timbre_tocar', methods: ['POST'])]
    public function tocar(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->loggedIn($session->get(self::SESSION_SOCIO))) {
            return $this->redirectToRoute('timbre_home');
        }
        if ($session->get(self::SESSION_MUST) === true) {
            return $this->redirectToRoute('timbre_pin');
        }
        if (!$this->tokenOk($request)) {
            return $this->redirectToRoute('timbre_home', ['ok' => 'sesion']);
        }

        $numero = (int) $session->get(self::SESSION_SOCIO);
        try {
            $inserted = $this->connection->executeStatement(
                "INSERT INTO cnb_app.timbre_cola (numero_socio, relay, tiempo_seg)
                 SELECT ?, 'Timbre', 3
                 WHERE NOT EXISTS (
                   SELECT 1 FROM cnb_app.timbre_cola
                   WHERE numero_socio = ? AND estado = 'pendiente' AND expira_at > NOW()
                 )",
                [$numero, $numero]
            );
        } catch (DbalException) {
            return $this->redirectToRoute('timbre_home', ['ok' => 'error']);
        }

        return $this->redirectToRoute('timbre_home', ['ok' => $inserted === 1 ? 'enviado' : 'espera']);
    }

    #[Route('/salir', name: 'timbre_salir', methods: ['POST'])]
    public function salir(Request $request): Response
    {
        $request->getSession()->invalidate();

        return $this->redirectToRoute('timbre_home');
    }

    private function loggedIn(mixed $numero): bool
    {
        return is_int($numero) && $numero >= 1 && $numero <= 99999;
    }

    private function validSocio(string $numero): bool
    {
        return preg_match('/^[1-9][0-9]{0,4}$/', $numero) === 1;
    }

    private function validPin(string $pin): bool
    {
        return preg_match('/^[0-9]{1,6}$/', $pin) === 1;
    }

    private function tokenOk(Request $request): bool
    {
        $sent = (string) $request->request->get('_token', '');
        $saved = (string) $request->getSession()->get(self::SESSION_TOKEN, '');

        return $sent !== '' && $saved !== '' && hash_equals($saved, $sent);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $mode, array $data = [], int $status = Response::HTTP_OK): Response
    {
        $session = $this->container->get('request_stack')->getSession();
        $token = $session->get(self::SESSION_TOKEN);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $session->set(self::SESSION_TOKEN, $token);
        }

        return $this->render('timbre/page.html.twig', array_merge($data, [
            'mode' => $mode,
            'token' => $token,
        ]), new Response('', $status));
    }
}
