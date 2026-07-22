<?php

namespace App\Controller;

use App\Service\ResourceRegistry;
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
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $resources = $this->registry->all();
        $stats = [];

        foreach ($resources as $name => $definition) {
            $stats[$name] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $definition['table']));
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
            $accion = (string) ($data['_accion'] ?? 'crear');

            if ($accion === 'observacion') {
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
            $querySocio = (int) $request->query->get('numero_socio', 0);
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

        $socios = $this->connection->fetchAllAssociative(
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

        return $this->json(['data' => $this->varadero->timeline($desde, $hasta)]);
    }

    #[Route('/admin/varadero/disponibilidad', name: 'admin_varadero_disponibilidad', priority: 20, methods: ['GET'])]
    public function varaderoDisponibilidad(Request $request): JsonResponse
    {
        $espacioId = (int) $request->query->get('espacio_id', 0);
        $numeroSocio = (int) $request->query->get('numero_socio', 0);
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
        $numeroSocio = (int) $request->query->get('numero_socio', 0);
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
                    $payload = $this->registry->payloadFor($definition, $data, form: true);
                    $this->connection->insert($definition['table'], $payload);
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
                    $payload = $this->registry->payloadFor($definition, $data, patch: true, form: true);
                    $this->connection->update($definition['table'], $payload, [$pk => $id]);
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
}
