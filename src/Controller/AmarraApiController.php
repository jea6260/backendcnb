<?php

namespace App\Controller;

use App\Service\AmarraMailer;
use App\Service\AmarraService;
use App\Service\UsuarioAuthService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', priority: 20)]
final class AmarraApiController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AmarraService $amarras,
        private readonly AmarraMailer $mailer,
        private readonly UsuarioAuthService $usuarios,
    ) {
    }

    #[Route('/socio/tipos-embarcacion', name: 'api_socio_tipos_embarcacion', methods: ['GET'])]
    public function tipos(Request $request): JsonResponse
    {
        $numero = $this->numeroSocio($request);
        if ($numero instanceof JsonResponse) {
            return $numero;
        }

        try {
            return $this->json(['data' => $this->amarras->tipos()]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/socio/amarras', name: 'api_socio_amarras', methods: ['GET'])]
    public function propias(Request $request): JsonResponse
    {
        $numero = $this->numeroSocio($request);
        if ($numero instanceof JsonResponse) {
            return $numero;
        }

        try {
            return $this->json(['data' => $this->amarras->lista($numero)]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/socio/amarras', name: 'api_socio_amarras_crear', methods: ['POST'])]
    public function crear(Request $request): JsonResponse
    {
        $numero = $this->numeroSocio($request);
        if ($numero instanceof JsonResponse) {
            return $numero;
        }

        $data = $this->jsonBody($request);
        $embarcacion = isset($data['embarcacion_id']) ? (int) $data['embarcacion_id'] : 0;
        $tipo = isset($data['tipo_id']) ? (int) $data['tipo_id'] : 0;

        try {
            $resultado = $this->amarras->solicitar(
                $numero,
                (string) ($data['modalidad'] ?? ''),
                (string) ($data['destino'] ?? ''),
                $embarcacion > 0 ? $embarcacion : null,
                $tipo > 0 ? $tipo : null,
                trim((string) ($data['observaciones'] ?? '')),
                filter_var($data['cambio_embarcacion'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }

        if (!$resultado['ok']) {
            return $this->json(['error' => $resultado['error']], Response::HTTP_BAD_REQUEST);
        }

        $ficha = $this->amarras->ficha((int) $resultado['id']);
        $mail = $ficha ? $this->mailer->enviar($ficha) : 'No se armó el aviso.';

        return $this->json([
            'data' => $ficha,
            'mail' => $mail,
        ], Response::HTTP_CREATED);
    }

    #[Route('/amarras', name: 'api_amarras', methods: ['GET'])]
    public function todas(Request $request): JsonResponse
    {
        $denegado = $this->exigeStaff($request);
        if ($denegado instanceof JsonResponse) {
            return $denegado;
        }

        try {
            return $this->json(['data' => $this->amarras->lista()]);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/amarras/{id}', name: 'api_amarras_actualizar', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function actualizar(int $id, Request $request): JsonResponse
    {
        $denegado = $this->exigeStaff($request);
        if ($denegado instanceof JsonResponse) {
            return $denegado;
        }

        $data = $this->jsonBody($request);
        try {
            $error = $this->amarras->actualizar(
                $id,
                isset($data['m2_disponibles']) ? (string) $data['m2_disponibles'] : null,
                isset($data['destino_disponible']) ? (string) $data['destino_disponible'] : null,
                (string) ($data['estado'] ?? 'en_lista'),
            );
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }

        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['data' => $this->amarras->ficha($id)]);
    }

    private function numeroSocio(Request $request): int|JsonResponse
    {
        $token = $this->bearer($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            $usuario = $this->usuarios->userFromToken($token);
            if ($usuario && ($usuario['rol'] ?? '') === 'socio' && !empty($usuario['numero_socio'])) {
                return (int) $usuario['numero_socio'];
            }

            $numero = $this->connection->fetchOne(
                'SELECT numero_socio FROM cnb_app.socio_sesiones WHERE token = ? AND expires_at > NOW()',
                [$token]
            );
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }

        if (!$numero) {
            return $this->json(['error' => 'Sesion expirada o invalida'], Response::HTTP_UNAUTHORIZED);
        }

        return (int) $numero;
    }

    private function exigeStaff(Request $request): ?JsonResponse
    {
        $token = $this->bearer($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $user = $this->usuarios->userFromToken($token);
        if (!$user || !in_array($user['rol'] ?? '', ['admin', 'empleado'], true)) {
            return $this->json(['error' => 'Token de administracion requerido'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    private function bearer(Request $request): string|JsonResponse
    {
        $header = $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->json(['error' => 'Token requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return $this->json(['error' => 'Token invalido'], Response::HTTP_UNAUTHORIZED);
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function dbalError(DbalException $exception): JsonResponse
    {
        return $this->json([
            'error' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
