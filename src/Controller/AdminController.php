<?php

namespace App\Controller;

use App\Service\ResourceRegistry;
use App\Service\UsuarioAuthService;
use App\Service\VaraderoService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
        private readonly VaraderoService $varadero,
        private readonly UsuarioAuthService $usuarios,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        if ($request->getSession()->get(UsuarioAuthService::ADMIN_SESSION_ROL) === 'socio') {
            return $this->render('admin/socio_home.html.twig', [
                'numero' => $request->getSession()->get(UsuarioAuthService::ADMIN_SESSION_SOCIO),
                'nombre' => (string) $request->getSession()->get(UsuarioAuthService::ADMIN_SESSION_NOMBRE, ''),
            ]);
        }

        $resources = $this->registry->all();
        $stats = [];

        foreach ($resources as $name => $definition) {
            try {
                $stats[$name] = (int) $this->connection->fetchOne(
                    sprintf('SELECT COUNT(*) FROM %s', $definition['table'])
                );
            } catch (DbalException) {
                $stats[$name] = 0;
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'resources' => $resources,
            'stats' => $stats,
        ]);
    }

    #[Route('/admin/varadero', name: 'admin_varadero', priority: 20, methods: ['GET', 'POST'])]
    public function varadero(Request $request): Response
    {
        $hoy = new \DateTimeImmutable('today');
        $session = $request->getSession();
        $soloPropio = $session->get(UsuarioAuthService::ADMIN_SESSION_ROL) === 'socio';
        $numeroFijo = (int) $session->get(UsuarioAuthService::ADMIN_SESSION_SOCIO, 0);
        $error = null;
        $data = [
            'numero_socio' => '',
            'embarcacion_id' => '',
            'espacio_id' => '',
            'fecha_inicio' => $hoy->format('Y-m-d'),
            'fecha_fin' => $hoy->format('Y-m-d'),
            'estado' => 'pendiente',
            'motivo' => 'limpieza_mantenimiento',
            'observaciones' => '',
        ];

        if ($request->isMethod('POST')) {
            $data = array_merge($data, $request->request->all());
            if ($soloPropio) {
                $data['numero_socio'] = (string) $numeroFijo;
                $embarcacionId = (int) ($data['embarcacion_id'] ?? 0);
                if ($embarcacionId > 0 && !$this->embarcacionDelSocio($numeroFijo, $embarcacionId)) {
                    $error = 'Esa embarcación no está vinculada a tu número de socio.';
                    $data['embarcacion_id'] = '';
                }
            }
            $accion = (string) ($data['_accion'] ?? 'crear');

            if ($error !== null) {
                // La embarcacion no es del socio: no se guarda nada.
            } elseif ($accion === 'observacion') {
                $reservaId = (int) ($data['reserva_id'] ?? 0);
                $numeroSocio = (int) ($data['numero_socio'] ?? 0);
                $texto = trim((string) ($data['observaciones'] ?? ''));
                $resultado = $this->varadero->agregarObservacion($reservaId, $numeroSocio, $texto);
                if (!$resultado['ok']) {
                    $error = $resultado['error'];
                } else {
                    $this->addFlash('success', 'Observacion agregada a la reserva.');

                    return $this->redirectToRoute('admin_varadero', [
                        'numero_socio' => $numeroSocio,
                    ]);
                }
            } else {
                $resultado = $this->varadero->validarYPrepararReserva($data);

                if (!$resultado['ok']) {
                    $error = $resultado['error'];
                } else {
                    try {
                        $this->connection->insert('cnb_app.reservas_varadero', $resultado['payload']);
                        $this->addFlash('success', 'Reserva de varadero creada correctamente.');

                        return $this->redirectToRoute('admin_varadero', [
                            'desde' => $resultado['payload']['fecha_inicio'],
                            'numero_socio' => $resultado['payload']['numero_socio'],
                        ]);
                    } catch (DbalException $exception) {
                        $error = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
                    }
                }
            }
        } else {
            $querySocio = $soloPropio ? $numeroFijo : (int) $request->query->get('numero_socio', 0);
            if ($querySocio > 0) {
                $data['numero_socio'] = (string) $querySocio;
            }
        }

        $desde = (string) $request->query->get('desde', $hoy->format('Y-m-d'));
        try {
            $desdeDate = new \DateTimeImmutable($desde);
        } catch (\Exception) {
            $desdeDate = $hoy;
            $desde = $hoy->format('Y-m-d');
        }
        $hasta = $desdeDate->modify('+41 days')->format('Y-m-d');

        $socios = $soloPropio ? [] : $this->connection->fetchAllAssociative(
            "SELECT numero_socio AS id,
                    apellido || ', ' || nombre || ' #' || numero_socio AS label
             FROM cnb_app.socios
             WHERE estado = 'activo'
             ORDER BY apellido ASC, nombre ASC"
        );

        $espacios = $this->connection->fetchAllAssociative(
            "SELECT id, codigo || ' (' || eslora_max_m || 'm)' AS label, eslora_max_m, activo
             FROM cnb_app.espacios_varadero
             WHERE activo = TRUE
             ORDER BY codigo ASC"
        );

        $embarcaciones = [];
        $reservaActiva = null;
        $numeroSocio = (int) ($data['numero_socio'] ?? 0);
        if ($numeroSocio > 0) {
            $embarcaciones = $this->varadero->embarcacionesDeSocio($numeroSocio);
            $reservaActiva = $this->varadero->reservaBloqueanteDeSocio($numeroSocio);
        }

        return $this->render('admin/varadero.html.twig', [
            'data' => $data,
            'error' => $error,
            'socios' => $socios,
            'espacios' => $espacios,
            'embarcaciones' => $embarcaciones,
            'reserva_activa' => $reservaActiva,
            'timeline_desde' => $desde,
            'timeline_hasta' => $hasta,
            'timeline' => $this->varadero->timeline($desde, $hasta),
            'dias_max_reserva' => VaraderoService::DIAS_MAX_RESERVA,
            'dias_max_anio' => VaraderoService::DIAS_MAX_ANIO,
            'solo_propio' => $soloPropio,
        ]);
    }

    #[Route('/admin/timbre', name: 'admin_timbre', priority: 30, methods: ['GET', 'POST'])]
    public function timbre(Request $request): Response
    {
        $session = $request->getSession();
        if ($session->get(UsuarioAuthService::ADMIN_SESSION_ROL) !== 'socio') {
            return $this->redirectToRoute('admin_dashboard');
        }

        $numero = (int) $session->get(UsuarioAuthService::ADMIN_SESSION_SOCIO, 0);
        $aviso = null;
        if ($request->isMethod('POST') && $numero > 0) {
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
                $aviso = $inserted === 1 ? 'enviado' : 'espera';
                if (!is_string($session->get('cnb_timbre_desde'))) {
                    $session->set('cnb_timbre_desde', (string) $this->connection->fetchOne('SELECT NOW()'));
                }
            } catch (DbalException) {
                $aviso = 'error';
            }
        }

        return $this->render('admin/timbre.html.twig', [
            'aviso' => $aviso,
            'numero' => $numero,
            'nombre' => (string) $session->get(UsuarioAuthService::ADMIN_SESSION_NOMBRE, ''),
            'esperando' => is_string($session->get('cnb_timbre_desde')),
        ]);
    }

    #[Route('/admin/timbre/estado', name: 'admin_timbre_estado', priority: 30, methods: ['GET'])]
    public function timbreEstado(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $numero = (int) $session->get(UsuarioAuthService::ADMIN_SESSION_SOCIO, 0);
        $desde = $session->get('cnb_timbre_desde');
        if ($session->get(UsuarioAuthService::ADMIN_SESSION_ROL) !== 'socio' || !is_string($desde)) {
            return $this->json(['logged' => false]);
        }

        try {
            $id = $this->connection->fetchOne(
                'SELECT id FROM cnb_app.historial_relays
                 WHERE numero_socio = ? AND created_at >= ?
                 ORDER BY id DESC LIMIT 1',
                [$numero, $desde]
            );
        } catch (DbalException) {
            return $this->json(['logged' => false]);
        }

        if (!$id) {
            return $this->json(['logged' => false]);
        }

        $session->remove('cnb_timbre_desde');

        return $this->json(['logged' => true]);
    }

    #[Route('/admin/personal', name: 'admin_personal', priority: 30, methods: ['POST'])]
    public function altaPersonal(Request $request): Response
    {
        $session = $request->getSession();
        if ($session->get(UsuarioAuthService::ADMIN_SESSION_ROL) !== 'admin') {
            return $this->redirectToRoute('admin_dashboard');
        }

        $email = strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < 8) {
            $this->addFlash('danger', 'Mail inválido o clave de menos de 8 caracteres.');

            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->usuarios->findByUsername($email) !== null) {
            $this->addFlash('danger', 'Ese mail ya tiene usuario.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $id = $this->usuarios->ensureUser($email, $this->usuarios->hashPassword($password), 'empleado', null, null, true);
        $this->addFlash($id === null ? 'danger' : 'success', $id === null
            ? 'No se pudo crear el personal del club.'
            : 'Personal del club creado. Entra con ese mail y tiene que cambiar la clave.');

        return $this->redirectToRoute('admin_dashboard');
    }

    private function numeroForzado(Request $request): ?int
    {
        $session = $request->getSession();
        if ($session->get(UsuarioAuthService::ADMIN_SESSION_ROL) !== 'socio') {
            return null;
        }

        return (int) $session->get(UsuarioAuthService::ADMIN_SESSION_SOCIO, 0);
    }

    private function embarcacionDelSocio(int $numeroSocio, int $embarcacionId): bool
    {
        foreach ($this->varadero->embarcacionesDeSocio($numeroSocio) as $barco) {
            if ((int) ($barco['id'] ?? 0) === $embarcacionId) {
                return true;
            }
        }

        return false;
    }

    #[Route('/admin/nivel-lago', name: 'admin_nivel_lago', priority: 25, methods: ['GET'])]
    public function nivelLago(Request $request): Response
    {
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
            'rango' => $rango,
            'desde' => $desde->format('Y-m-d H:i'),
            'hasta' => $hasta->format('Y-m-d H:i'),
            'labels' => $labels,
            'valores' => $valores,
            'ultima' => $ultima,
            'total' => count($rows),
        ]);
    }

    #[Route('/admin/varadero/timeline', name: 'admin_varadero_timeline', priority: 20, methods: ['GET'])]
    public function varaderoTimeline(Request $request): JsonResponse
    {
        $hoy = new \DateTimeImmutable('today');
        $desde = trim((string) $request->query->get('desde', $hoy->format('Y-m-d')));
        $hasta = trim((string) $request->query->get('hasta', $hoy->modify('+41 days')->format('Y-m-d')));

        try {
            new \DateTimeImmutable($desde);
            new \DateTimeImmutable($hasta);
        } catch (\Exception) {
            return $this->json(['error' => 'Fechas invalidas'], Response::HTTP_BAD_REQUEST);
        }

        $data = $this->varadero->timeline($desde, $hasta);
        $propio = $this->numeroForzado($request);
        if ($propio !== null) {
            foreach ($data['espacios'] as &$espacio) {
                foreach ($espacio['reservas'] as &$reserva) {
                    if ((int) $reserva['numero_socio'] !== $propio) {
                        $reserva['socio'] = 'Ocupado';
                        $reserva['embarcacion'] = 'Ocupado';
                        $reserva['observaciones'] = null;
                        $reserva['numero_socio'] = 0;
                        $reserva['embarcacion_id'] = 0;
                    }
                }
            }
            unset($espacio, $reserva);
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/admin/varadero/disponibilidad', name: 'admin_varadero_disponibilidad', priority: 20, methods: ['GET'])]
    public function varaderoDisponibilidad(Request $request): JsonResponse
    {
        $espacioId = (int) $request->query->get('espacio_id', 0);
        $numeroSocio = $this->numeroForzado($request) ?? (int) $request->query->get('numero_socio', 0);
        $hoy = new \DateTimeImmutable('today');
        $desde = trim((string) $request->query->get('desde', $hoy->format('Y-m-d')));
        $hasta = trim((string) $request->query->get('hasta', $hoy->modify('+6 months')->format('Y-m-d')));

        try {
            new \DateTimeImmutable($desde);
            new \DateTimeImmutable($hasta);
        } catch (\Exception) {
            return $this->json(['error' => 'Fechas invalidas'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'data' => $this->varadero->disponibilidad(
                $espacioId > 0 ? $espacioId : null,
                $desde,
                $hasta,
                $numeroSocio > 0 ? $numeroSocio : null
            ),
        ]);
    }

    #[Route('/admin/varadero/embarcaciones', name: 'admin_varadero_embarcaciones', priority: 20, methods: ['GET'])]
    public function varaderoEmbarcaciones(Request $request): JsonResponse
    {
        $numeroSocio = $this->numeroForzado($request) ?? (int) $request->query->get('numero_socio', 0);
        if ($numeroSocio < 1) {
            return $this->json(['error' => 'numero_socio es obligatorio'], Response::HTTP_BAD_REQUEST);
        }

        $embarcaciones = $this->varadero->embarcacionesDeSocio($numeroSocio);
        $reservaActiva = $this->varadero->reservaBloqueanteDeSocio($numeroSocio);

        return $this->json([
            'data' => $embarcaciones,
            'reserva_activa' => $reservaActiva,
            'puede_crear' => $reservaActiva === null,
        ]);
    }

    #[Route('/admin/{resource}', name: 'admin_resource_index', methods: ['GET'])]
    public function index(string $resource, Request $request): Response
    {
        $definition = $this->registry->get($resource);
        $fields = $this->registry->listFields($definition);
        $pk = $this->registry->primaryKey($definition);
        $sort = (string) $request->query->get('sort', $pk);
        $dir = (string) $request->query->get('dir', 'desc');
        $filters = array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $request->query->all('f')
        );
        $filters = array_filter($filters, static fn (string $value): bool => $value !== '');

        $rows = $this->registry->withRelationLabels(
            $this->connection,
            $definition,
            $this->registry->fetchListRows($this->connection, $definition, $sort, $dir, $filters)
        );

        return $this->render('admin/index.html.twig', [
            'resource' => $resource,
            'definition' => $definition,
            'fields' => $fields,
            'rows' => $rows,
            'primary_key' => $pk,
            'show_id' => ($definition['list_id'] ?? true) && $pk === 'id',
            'sort' => in_array($sort, $this->registry->listableColumns($definition), true) ? $sort : $pk,
            'dir' => strtolower($dir) === 'asc' ? 'asc' : 'desc',
            'filters' => $filters,
        ]);
    }

    #[Route('/admin/{resource}/nuevo', name: 'admin_resource_new', methods: ['GET', 'POST'])]
    public function new(string $resource, Request $request): Response
    {
        if ($resource === 'reservas-varadero') {
            return $this->redirectToRoute('admin_varadero');
        }

        $definition = $this->registry->get($resource);
        $data = [];
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            try {
                if ($resource === 'reservas-varadero') {
                    $resultado = $this->varadero->validarYPrepararReserva($data);
                    if (!$resultado['ok']) {
                        $error = $resultado['error'];
                    } else {
                        $this->connection->insert($definition['table'], $resultado['payload']);
                        $this->addFlash('success', sprintf('%s creado correctamente.', $definition['singular']));

                        return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
                    }
                } else {
                    $data = $this->mergeUploadedImages($request, $definition, $data);
                    $payload = $this->registry->payloadFor($definition, $data, form: true);
                    $this->connection->insert($definition['table'], $payload);
                    $this->syncSocioFacialFromFoto($resource, $payload);
                    $this->addFlash('success', sprintf('%s creado correctamente.', $definition['singular']));

                    return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
                }
            } catch (DbalException $exception) {
                $error = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
            }
        }

        return $this->renderCrudForm($resource, $definition, $data, $error);
    }

    #[Route('/admin/{resource}/{id}/editar', name: 'admin_resource_edit', requirements: ['id' => '[^/]+'], methods: ['GET', 'POST'])]
    public function edit(string $resource, string $id, Request $request): Response
    {
        $definition = $this->registry->get($resource);
        $pk = $this->registry->primaryKey($definition);
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ?', $definition['table'], $pk),
            [$id]
        );

        if (!$row) {
            throw $this->createNotFoundException();
        }

        $data = $this->registry->normalizeRow($row);
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            try {
                if ($resource === 'reservas-varadero') {
                    $resultado = $this->varadero->validarYPrepararReserva($data, (int) $id);
                    if (!$resultado['ok']) {
                        $error = $resultado['error'];
                    } else {
                        $this->connection->update($definition['table'], $resultado['payload'], [$pk => $id]);
                        $this->addFlash('success', sprintf('%s actualizado correctamente.', $definition['singular']));

                        return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
                    }
                } else {
                    $data = $this->mergeUploadedImages($request, $definition, $data);
                    $payload = $this->registry->payloadFor($definition, $data, patch: true, form: true);
                    $this->connection->update($definition['table'], $payload, [$pk => $id]);
                    $this->syncSocioFacialFromFoto($resource, $payload + ['numero_socio' => $id]);
                    $this->addFlash('success', sprintf('%s actualizado correctamente.', $definition['singular']));

                    return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
                }
            } catch (DbalException $exception) {
                $error = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
            }
        }

        return $this->renderCrudForm($resource, $definition, $data, $error, $id);
    }

    #[Route('/admin/{resource}/{id}/eliminar', name: 'admin_resource_delete', requirements: ['id' => '[^/]+'], methods: ['POST'])]
    public function delete(string $resource, string $id): RedirectResponse
    {
        $definition = $this->registry->get($resource);
        $pk = $this->registry->primaryKey($definition);

        try {
            $this->connection->delete($definition['table'], [$pk => $id]);
            $this->addFlash('success', sprintf('%s eliminado correctamente.', $definition['singular']));
        } catch (DbalException $exception) {
            $this->addFlash('danger', $exception->getPrevious()?->getMessage() ?? $exception->getMessage());
        }

        return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     */
    private function renderCrudForm(
        string $resource,
        array $definition,
        array $data,
        ?string $error,
        string|int|null $id = null,
    ): Response {
        $fields = $this->registry->formFields($definition);
        $relations = [];

        foreach ($fields as $name => $field) {
            $relations[$name] = $this->registry->relationOptions($this->connection, $field);
        }

        return $this->render('admin/form.html.twig', [
            'resource' => $resource,
            'definition' => $definition,
            'fields' => $fields,
            'relations' => $relations,
            'data' => $data,
            'error' => $error,
            'id' => $id,
            'primary_key' => $this->registry->primaryKey($definition),
        ]);
    }

    /**
     * Si el navegador envía el archivo sin pasar por FileReader, lo convierte a data-URL.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mergeUploadedImages(Request $request, array $definition, array $data): array
    {
        foreach ($this->registry->formFields($definition) as $name => $field) {
            if (($field['type'] ?? null) !== 'image') {
                continue;
            }

            $file = $request->files->get($name.'_file');
            if ($file === null || !$file->isValid()) {
                continue;
            }

            $mime = (string) ($file->getMimeType() ?: 'image/jpeg');
            if (!str_starts_with($mime, 'image/')) {
                continue;
            }
            if ($file->getSize() > 4 * 1024 * 1024) {
                continue;
            }

            $binary = @file_get_contents($file->getPathname());
            if ($binary === false || $binary === '') {
                continue;
            }

            $data[$name] = 'data:'.$mime.';base64,'.base64_encode($binary);
        }

        return $data;
    }

    /**
     * La foto de perfil del socio también alimenta el reconocimiento facial.
     *
     * @param array<string, mixed> $payload
     */
    private function syncSocioFacialFromFoto(string $resource, array $payload): void
    {
        if ($resource !== 'socios' || !array_key_exists('foto_perfil', $payload)) {
            return;
        }

        $numeroSocio = $payload['numero_socio'] ?? null;
        if ($numeroSocio === null || $numeroSocio === '') {
            return;
        }

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM cnb_app.socio_acceso WHERE numero_socio = ?',
            [$numeroSocio]
        );

        if (!$exists) {
            return;
        }

        $this->connection->update('cnb_app.socio_acceso', [
            'facial_reference' => $payload['foto_perfil'],
            'updated_at' => date('c'),
        ], ['numero_socio' => $numeroSocio]);
    }
}
