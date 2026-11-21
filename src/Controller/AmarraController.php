<?php

namespace App\Controller;

use App\Service\AmarraMailer;
use App\Service\AmarraService;
use App\Service\UsuarioAuthService;
use App\Service\VaraderoService;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/amarras', priority: 30)]
final class AmarraController extends AbstractController
{
    public function __construct(
        private readonly AmarraService $amarras,
        private readonly AmarraMailer $mailer,
        private readonly VaraderoService $varadero,
    ) {
    }

    #[Route('', name: 'admin_amarras', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $rol = (string) $session->get(UsuarioAuthService::ADMIN_SESSION_ROL, '');
        $perfil = (string) $session->get(UsuarioAuthService::ADMIN_SESSION_PERFIL, '');
        $socio = $rol === 'socio';
        $gestiona = in_array($perfil, ['administrador', 'superuser', 'operador'], true);
        $numero = (int) $session->get(UsuarioAuthService::ADMIN_SESSION_SOCIO, 0);
        $error = null;

        if ($request->isMethod('POST')) {
            $accion = (string) $request->request->get('accion', 'solicitar');
            try {
                if ($accion === 'actualizar' && $gestiona) {
                    $error = $this->amarras->actualizar(
                        (int) $request->request->get('id', 0),
                        $request->request->get('m2_disponibles'),
                        $request->request->get('destino_disponible'),
                        (string) $request->request->get('estado', 'en_lista'),
                    );
                    if ($error === null) {
                        $this->addFlash('success', 'Solicitud actualizada.');

                        return $this->redirectToRoute('admin_amarras');
                    }
                } elseif ($socio) {
                    $embarcacion = (int) $request->request->get('embarcacion_id', 0);
                    $tipo = (int) $request->request->get('tipo_id', 0);
                    $resultado = $this->amarras->solicitar(
                        $numero,
                        (string) $request->request->get('modalidad', ''),
                        (string) $request->request->get('destino', ''),
                        $embarcacion > 0 ? $embarcacion : null,
                        $tipo > 0 ? $tipo : null,
                        trim((string) $request->request->get('observaciones', '')),
                    );
                    if (!$resultado['ok']) {
                        $error = $resultado['error'];
                    } else {
                        $ficha = $this->amarras->ficha((int) $resultado['id']);
                        $mail = $ficha ? $this->mailer->enviar($ficha) : 'No se armó el aviso.';
                        $this->addFlash(
                            'success',
                            $mail === null
                                ? 'Solicitud de amarra anotada. Se avisó a administración.'
                                : 'Solicitud de amarra anotada. '.$mail
                        );

                        return $this->redirectToRoute('admin_amarras');
                    }
                }
            } catch (DbalException) {
                $error = 'No se pudo guardar. Falta aplicar sql/021_tipos_y_amarras.sql.';
            }
        }

        try {
            $lista = $this->amarras->lista($socio ? $numero : null);
            $tipos = $this->amarras->tipos();
            $barcos = $socio ? $this->varadero->embarcacionesDeSocio($numero, false) : [];
        } catch (DbalException) {
            $lista = [];
            $tipos = [];
            $barcos = [];
            $error ??= 'Falta aplicar sql/021_tipos_y_amarras.sql.';
        }

        return $this->render('admin/amarras.html.twig', [
            'socio' => $socio,
            'gestiona' => $gestiona,
            'numero' => $numero,
            'nombre' => (string) $session->get(UsuarioAuthService::ADMIN_SESSION_NOMBRE, ''),
            'error' => $error,
            'lista' => $lista,
            'tipos' => $tipos,
            'barcos' => $barcos,
            'pies' => AmarraService::PIES_TIERRA,
        ]);
    }
}
