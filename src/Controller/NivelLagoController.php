<?php

namespace App\Controller;

use App\Service\ResourceRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pantalla publica del nivel. La clave vive en el codigo: no consulta usuarios.
 */
final class NivelLagoController extends AbstractController
{
    private const SESSION = 'cnb_nivel_lago';

    private const USERNAME = 'que_nivel';

    private const PASSWORD = 'Nahuel!Huapi';

    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
    ) {
    }

    #[Route('/nivel-lago', name: 'nivel_lago_publico', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                return $this->login($session, 'La sesión venció. Volvé a intentar.', Response::HTTP_BAD_REQUEST);
            }

            if ($request->request->get('accion') === 'salir') {
                $session->remove(self::SESSION);

                return $this->redirectToRoute('nivel_lago_publico');
            }

            $username = strtolower(trim((string) $request->request->get('username', '')));
            $password = (string) $request->request->get('password', '');
            if (!$this->claveOk($username, $password)) {
                return $this->login($session, 'Usuario o clave inválidos.', Response::HTTP_UNAUTHORIZED, $username);
            }

            $session->migrate(true);
            $session->set(self::SESSION, true);
            $session->set('cnb_nivel_token', bin2hex(random_bytes(16)));

            return $this->redirectToRoute('nivel_lago_publico');
        }

        if ($session->get(self::SESSION) !== true) {
            return $this->login($session, null, Response::HTTP_OK);
        }

        $rango = trim((string) $request->query->get('rango', 'semana'));
        if (!in_array($rango, ['semana', 'mes', 'anio'], true)) {
            $rango = 'semana';
        }

        $hasta = new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $desde = match ($rango) {
            'mes' => $hasta->modify('-30 days'),
            'anio' => $hasta->modify('-365 days'),
            default => $hasta->modify('-7 days'),
        };

        $rows = $this->connection->fetchAllAssociative(
            'SELECT fecha, msnm, profundidad_cm, distancia_medida_cm
             FROM cnb_app.mediciones_nivel
             WHERE fecha >= ? AND fecha <= ?
             ORDER BY fecha ASC',
            [$desde->format('Y-m-d H:i:s'), $hasta->format('Y-m-d H:i:s')]
        );

        $labels = [];
        $valores = [];
        foreach ($rows as $row) {
            $labels[] = substr((string) $row['fecha'], 0, 16);
            $valores[] = isset($row['msnm']) ? (float) $row['msnm'] : null;
        }

        $ultima = $rows === [] ? null : $this->registry->normalizeRow($rows[array_key_last($rows)]);

        return $this->render('admin/nivel_lago.html.twig', [
            'publico' => true,
            'rango' => $rango,
            'desde' => $desde->format('Y-m-d H:i'),
            'hasta' => $hasta->format('Y-m-d H:i'),
            'labels' => $labels,
            'valores' => $valores,
            'ultima' => $ultima,
            'total' => count($rows),
        ]);
    }

    private function login(SessionInterface $session, ?string $error, int $status, string $username = ''): Response
    {
        return $this->render('nivel_lago_login.html.twig', [
            'error' => $error,
            'username' => $username,
            'token' => $this->formToken($session),
        ], new Response('', $status));
    }

    private function claveOk(string $username, string $password): bool
    {
        return hash_equals(self::USERNAME, $username) && hash_equals(self::PASSWORD, $password);
    }

    private function formToken(SessionInterface $session): string
    {
        $token = $session->get('cnb_nivel_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $session->set('cnb_nivel_token', $token);
        }

        return $token;
    }

    private function tokenOk(Request $request): bool
    {
        $sent = (string) $request->request->get('_token', '');
        $saved = (string) $request->getSession()->get('cnb_nivel_token', '');

        return $sent !== '' && $saved !== '' && hash_equals($saved, $sent);
    }
}
