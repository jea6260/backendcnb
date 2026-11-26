<?php

namespace App\EventSubscriber;

use App\Service\UsuarioAuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * El panel /admin exige sesion. Un socio solo llega a lago, timbre, varadero y su cuenta.
 */
final class AdminAuthSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UsuarioAuthService $usuarios,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/admin') || $this->isPublic($path)) {
            return;
        }

        $session = $request->getSession();
        $user = $this->usuarios->findActivePanelUser($this->sessionAdminId($session));
        if ($user !== null) {
            $session->set(UsuarioAuthService::ADMIN_SESSION_USERNAME, $user['username']);
            $session->set(UsuarioAuthService::ADMIN_SESSION_ROL, $user['rol']);
            $session->set(UsuarioAuthService::ADMIN_SESSION_SOCIO, $user['numero_socio']);
            $session->set(UsuarioAuthService::ADMIN_SESSION_PERFIL, $this->usuarios->perfil($user));
            $session->set(UsuarioAuthService::ADMIN_SESSION_NOMBRE, $this->usuarios->nombreVisible($user));
            if ($this->denegado($this->usuarios->perfil($user), $path, $request->getMethod())) {
                $event->setResponse(new RedirectResponse($this->urls->generate('admin_dashboard')));
            }

            return;
        }

        $session->remove(UsuarioAuthService::ADMIN_SESSION_ID);
        $session->remove(UsuarioAuthService::ADMIN_SESSION_USERNAME);

        if ($this->isJsonEndpoint($path)) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Debe iniciar sesion'],
                Response::HTTP_UNAUTHORIZED
            ));

            return;
        }

        if ($request->isMethod('GET')) {
            $session->set(UsuarioAuthService::ADMIN_SESSION_NEXT, $request->getRequestUri());
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('admin_login')));
    }

    private function sessionAdminId(SessionInterface $session): int
    {
        $raw = $session->get(UsuarioAuthService::ADMIN_SESSION_ID);

        if (is_int($raw)) {
            return $raw;
        }

        return is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    }

    private function isPublic(string $path): bool
    {
        return $path === '/admin/login'
            || $path === '/admin/logout'
            || $path === '/admin/registro'
            || $path === '/admin/recuperar';
    }

    private function denegado(string $perfil, string $path, string $method): bool
    {
        if (in_array($perfil, ['administrador', 'superuser'], true)) {
            return false;
        }

        $recurso = $this->recurso($path);
        if ($perfil === 'socio') {
            return !$this->socioAllowed($path);
        }

        if (in_array($perfil, ['marinero', 'guardia'], true)) {
            if ($recurso !== null) {
                $permiso = $this->usuarios->permisoRecurso($perfil, $recurso);
                if (!$permiso['ver']) {
                    return true;
                }
                if (str_contains($path, '/eliminar') || str_contains($path, '/nuevo')) {
                    return true;
                }
                if (str_contains($path, '/editar') && !$permiso['editar']) {
                    return true;
                }

                return false;
            }

            return !$this->marineroLibre($path);
        }

        if ($perfil === 'operador') {
            if ($recurso !== null && !$this->usuarios->permisoRecurso($perfil, $recurso)['ver']) {
                return true;
            }

            return $path === '/admin/mis-datos';
        }

        return true;
    }

    private function socioAllowed(string $path): bool
    {
        return $path === '/admin'
            || $path === '/admin/cuenta'
            || $path === '/admin/perfil-foto'
            || $path === '/admin/mis-datos'
            || $path === '/admin/timbre'
            || str_starts_with($path, '/admin/timbre/')
            || $path === '/admin/amarras'
            || str_starts_with($path, '/admin/nivel-lago')
            || str_starts_with($path, '/admin/varadero');
    }

    private function marineroLibre(string $path): bool
    {
        return $path === '/admin'
            || $path === '/admin/cuenta'
            || str_starts_with($path, '/admin/nivel-lago')
            || str_starts_with($path, '/admin/varadero');
    }

    private function recurso(string $path): ?string
    {
        if (!preg_match('#^/admin/([a-z0-9-]+)#', $path, $coincide)) {
            return null;
        }

        $nombre = $coincide[1];
        $reservados = [
            'cuenta', 'timbre', 'varadero', 'amarras', 'nivel-lago', 'personal',
            'historial-timbre', 'mis-datos', 'login', 'logout', 'registro', 'recuperar',
        ];

        return in_array($nombre, $reservados, true) ? null : $nombre;
    }

    private function isJsonEndpoint(string $path): bool
    {
        return $path === '/admin/varadero/timeline'
            || $path === '/admin/varadero/disponibilidad'
            || $path === '/admin/varadero/embarcaciones';
    }
}
