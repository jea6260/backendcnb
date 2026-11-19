<?php

namespace App\Controller;

use App\Service\SocioCuentaService;
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
        private readonly SocioCuentaService $cuentas,
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
        if ($this->usuarios->findActivePanelUser($this->sessionAdminId($session)) !== null) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = null;
        $username = '';
        $status = Response::HTTP_OK;
        $cambiarPin = $this->pinPendiente($session);

        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                $error = 'La sesión venció. Volvé a intentar.';
                $status = Response::HTTP_BAD_REQUEST;
            } elseif ($request->request->get('accion') === 'cambiar_pin') {
                $numero = (string) ($cambiarPin['numero'] ?? '');
                if ($numero === '') {
                    $error = 'Volvé a ingresar con tu número y el PIN.';
                    $status = Response::HTTP_BAD_REQUEST;
                } else {
                    $result = $this->cuentas->cambiarPinInicial(
                        $numero,
                        trim((string) $request->request->get('nuevo', '')),
                        trim((string) $request->request->get('confirmar', '')),
                    );
                    if (is_string($result)) {
                        $error = $result;
                        $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    } else {
                        $session->remove('cnb_cambiar_pin');

                        return $this->enter($session, $result);
                    }
                }
            } else {
                $session->remove('cnb_cambiar_pin');
                $cambiarPin = null;
                $username = trim((string) $request->request->get('username', ''));
                $password = (string) $request->request->get('password', '');
                if (preg_match('/^[1-9][0-9]{0,4}$/', $username) === 1) {
                    $result = $this->cuentas->ingresarConPin($username, trim($password));
                    if ($result === 'cambiar_pin') {
                        $session->set('cnb_cambiar_pin', [
                            'numero' => (int) $username,
                            'hasta' => time() + 600,
                        ]);
                        $cambiarPin = $this->pinPendiente($session);
                    } elseif (is_string($result)) {
                        $error = $result;
                        $status = Response::HTTP_UNAUTHORIZED;
                    } else {
                        return $this->enter($session, $result);
                    }
                } else {
                    $error = $this->bootstrapError();
                    if ($error === null) {
                        $result = $this->usuarios->authenticatePanel($username, $password);
                        if (is_string($result)) {
                            $error = 'Usuario o contraseña inválidos. Si no sos socio, pedile acceso al administrador.';
                            $status = Response::HTTP_UNAUTHORIZED;
                        } else {
                            return $this->enter($session, $result);
                        }
                    }
                }
            }
        } elseif ($cambiarPin === null) {
            $session->remove('cnb_cambiar_pin');
        }

        return $this->render('admin/login.html.twig', [
            'error' => $error,
            'username' => $username,
            'cambiar_pin' => $cambiarPin !== null,
            'numero' => $cambiarPin['numero'] ?? null,
            'token' => $this->formToken($session),
        ], new Response('', $status));
    }

    /**
     * @return array{numero: int, hasta: int}|null
     */
    private function pinPendiente(SessionInterface $session): ?array
    {
        $raw = $session->get('cnb_cambiar_pin');
        if (!is_array($raw) || !isset($raw['numero'], $raw['hasta'])) {
            return null;
        }
        if ((int) $raw['hasta'] < time()) {
            $session->remove('cnb_cambiar_pin');

            return null;
        }

        return ['numero' => (int) $raw['numero'], 'hasta' => (int) $raw['hasta']];
    }

    #[Route('/registro', name: 'admin_registro', priority: 30, methods: ['GET', 'POST'])]
    public function registro(Request $request): Response
    {
        $error = null;
        $status = Response::HTTP_OK;
        $numero = '';
        $email = '';
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                $error = 'La sesión venció. Volvé a intentar.';
                $status = Response::HTTP_BAD_REQUEST;
            } else {
                $numero = trim((string) $request->request->get('numeroSocio', ''));
                $email = trim((string) $request->request->get('email', ''));
                $pin = trim((string) $request->request->get('pin', ''));
                $password = (string) $request->request->get('password', '');
                $confirmar = (string) $request->request->get('confirmar', '');
                if ($password !== $confirmar) {
                    $error = 'Las contraseñas no coinciden.';
                } else {
                    $result = $this->cuentas->registrar($numero, $pin, $email, $password);
                    if (is_string($result)) {
                        $error = $result;
                    } else {
                        $user = $this->usuarios->authenticatePanel($result['username'], $password);

                        return is_string($user)
                            ? $this->redirectToRoute('admin_login')
                            : $this->enter($request->getSession(), $user);
                    }
                }
            }
            if ($error !== null && $status === Response::HTTP_OK) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('admin/registro.html.twig', [
            'error' => $error,
            'numero' => $numero,
            'email' => $email,
            'token' => $this->formToken($request->getSession()),
        ], new Response('', $status));
    }

    #[Route('/recuperar', name: 'admin_recuperar', priority: 30, methods: ['GET', 'POST'])]
    public function recuperar(Request $request): Response
    {
        $error = null;
        $status = Response::HTTP_OK;
        $numero = '';
        $email = '';
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                $error = 'La sesión venció. Volvé a intentar.';
                $status = Response::HTTP_BAD_REQUEST;
            } else {
                $numero = trim((string) $request->request->get('numeroSocio', ''));
                $email = trim((string) $request->request->get('email', ''));
                $pin = trim((string) $request->request->get('pin', ''));
                $password = (string) $request->request->get('password', '');
                $confirmar = (string) $request->request->get('confirmar', '');
                if ($password !== $confirmar) {
                    $error = 'Las contraseñas no coinciden.';
                } else {
                    $result = $this->cuentas->recuperar($numero, $pin, $password, $email);
                    if (is_string($result)) {
                        $error = $result;
                    } else {
                        $this->addFlash('success', 'Clave actualizada. Entrá con el mail.');

                        return $this->redirectToRoute('admin_login');
                    }
                }
            }
            if ($error !== null && $status === Response::HTTP_OK) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('admin/recuperar.html.twig', [
            'error' => $error,
            'numero' => $numero,
            'email' => $email,
            'token' => $this->formToken($request->getSession()),
        ], new Response('', $status));
    }

    #[Route('/cuenta', name: 'admin_cuenta', priority: 30, methods: ['GET', 'POST'])]
    public function cuenta(Request $request): Response
    {
        $session = $request->getSession();
        $user = $this->usuarios->findActivePanelUser($this->sessionAdminId($session));
        if ($user === null) {
            return $this->redirectToRoute('admin_login');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                $error = 'La sesión venció. Volvé a intentar.';
            } elseif ($user['rol'] === 'socio') {
                $result = $this->cuentas->cambiarPin(
                    (int) $user['numero_socio'],
                    trim((string) $request->request->get('actual', '')),
                    trim((string) $request->request->get('nuevo', '')),
                    trim((string) $request->request->get('confirmar', '')),
                );
                if (is_string($result)) {
                    $error = $result;
                } else {
                    $this->addFlash('success', 'PIN actualizado.');

                    return $this->redirectToRoute('admin_cuenta');
                }
            } else {
                $error = $this->cuentas->cambiarCuenta(
                    $user['id'],
                    (string) $request->request->get('actual', ''),
                    (string) $request->request->get('email', ''),
                    (string) $request->request->get('password', ''),
                );
                if ($error === null) {
                    $nuevo = strtolower(trim((string) $request->request->get('email', '')));
                    if ($nuevo !== '') {
                        $session->set(UsuarioAuthService::ADMIN_SESSION_USERNAME, $nuevo);
                    }
                    $this->addFlash('success', 'Datos actualizados.');

                    return $this->redirectToRoute('admin_cuenta');
                }
            }
        }

        return $this->render('admin/cuenta.html.twig', [
            'error' => $error,
            'email' => (string) $session->get(UsuarioAuthService::ADMIN_SESSION_USERNAME, $user['username']),
            'rol' => $user['rol'],
            'token' => $this->formToken($session),
        ]);
    }

    /**
     * @param array{id: int, username: string, rol: string, numero_socio: ?int, cambiar_clave: bool} $user
     */
    private function enter(SessionInterface $session, array $user): RedirectResponse
    {
        $session->migrate(true);
        $session->set(UsuarioAuthService::ADMIN_SESSION_ID, $user['id']);
        $session->set(UsuarioAuthService::ADMIN_SESSION_USERNAME, $user['username']);
        $session->set(UsuarioAuthService::ADMIN_SESSION_ROL, $user['rol']);
        $session->set(UsuarioAuthService::ADMIN_SESSION_SOCIO, $user['numero_socio']);
        if ($user['cambiar_clave']) {
            $this->addFlash('success', 'Tenés que cambiar la contraseña.');

            return $this->redirectToRoute('admin_cuenta');
        }

        $next = $session->get(UsuarioAuthService::ADMIN_SESSION_NEXT);
        $session->remove(UsuarioAuthService::ADMIN_SESSION_NEXT);
        $target = is_string($next) ? $this->safeNext($next) : null;
        if ($user['rol'] === 'socio' && is_string($target) && !$this->socioPath($target)) {
            $target = null;
        }

        return $this->redirect($target ?? $this->generateUrl('admin_dashboard'));
    }

    private function socioPath(string $path): bool
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;

        return $path === '/admin'
            || $path === '/admin/cuenta'
            || $path === '/admin/timbre'
            || str_starts_with($path, '/admin/nivel-lago')
            || str_starts_with($path, '/admin/varadero');
    }

    private function formToken(SessionInterface $session): string
    {
        $token = $session->get('cnb_form_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $session->set('cnb_form_token', $token);
        }

        return $token;
    }

    private function tokenOk(Request $request): bool
    {
        $sent = (string) $request->request->get('_token', '');
        $saved = (string) $request->getSession()->get('cnb_form_token', '');

        return $sent !== '' && $saved !== '' && hash_equals($saved, $sent);
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
