<?php

namespace App\Controller;

use App\Service\UsuarioAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class AdminAuthController extends AbstractController
{
    public function __construct(
        private readonly UsuarioAuthService $usuarios,
        #[Autowire('%env(default:app.empty_string:ADMIN_USERNAME)%')]
        private readonly string $envUsername,
        #[Autowire('%env(default:app.empty_string:ADMIN_PASSWORD)%')]
        private readonly string $envPassword,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/login', name: 'admin_login', priority: 30, methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();
        if ($this->usuarios->findActiveAdminById($this->sessionAdminId($session)) !== null) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = null;
        $username = '';
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username', ''));
            $password = (string) $request->request->get('password', '');
            $error = $this->bootstrapError();

            if ($error === null) {
                $result = $this->usuarios->authenticateAdmin($username, $password);
                if (is_string($result)) {
                    $error = $result;
                } else {
                    $session->migrate(true);
                    $session->set(UsuarioAuthService::ADMIN_SESSION_ID, $result['id']);
                    $session->set(UsuarioAuthService::ADMIN_SESSION_USERNAME, $result['username']);
                    $next = $session->get(UsuarioAuthService::ADMIN_SESSION_NEXT);
                    $session->remove(UsuarioAuthService::ADMIN_SESSION_NEXT);
                    $target = is_string($next) ? $this->safeNext($next) : null;

                    if ($target !== null) {
                        return new RedirectResponse($target);
                    }

                    return $this->redirectToRoute('admin_dashboard');
                }
            }

            $status = str_contains($error, 'Falta la tabla')
                ? Response::HTTP_SERVICE_UNAVAILABLE
                : Response::HTTP_UNAUTHORIZED;
        }

        return $this->render('admin/login.html.twig', [
            'error' => $error,
            'username' => $username,
            'configured' => trim($this->envUsername) !== '' && $this->envPassword !== '',
        ], new Response('', $status));
    }

    #[Route('/logout', name: 'admin_logout', priority: 30, methods: ['POST'])]
    public function logout(Request $request): RedirectResponse
    {
        $request->getSession()->invalidate();

        return $this->redirectToRoute('admin_login');
    }

    private function bootstrapError(): ?string
    {
        $username = trim($this->envUsername);
        $password = $this->envPassword;
        if ($username === '' || $password === '') {
            return null;
        }

        if ($this->environment === 'prod' && strlen($password) < 8) {
            return 'ADMIN_PASSWORD debe tener al menos 8 caracteres';
        }

        return $this->usuarios->ensureAdmin($username, $password);
    }

    private function sessionAdminId(SessionInterface $session): int
    {
        $raw = $session->get(UsuarioAuthService::ADMIN_SESSION_ID);
        if (is_int($raw)) {
            return $raw;
        }

        return is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    }

    private function safeNext(string $next): ?string
    {
        if (!str_starts_with($next, '/admin') || str_starts_with($next, '/admin/login') || str_starts_with($next, '//')) {
            return null;
        }

        if (preg_match('/[[:cntrl:]]/', $next) === 1) {
            return null;
        }

        if (str_contains($next, '://') || str_contains($next, '\\')) {
            return null;
        }

        return $next;
    }
}
