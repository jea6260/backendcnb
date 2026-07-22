<?php

namespace App\Controller;

use App\Service\ResourceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ApiController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
    ) {
    }

    #[Route('/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'ok', 'service' => 'cnb-backend']);
    }

    #[Route('/dispositivo/mediciones', name: 'api_dispositivo_mediciones', methods: ['POST'])]
    public function deviceMeasurement(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);

        foreach (['distancia_medida_cm', 'profundidad_cm', 'msnm'] as $field) {
            if (!isset($data[$field]) || !is_numeric($data[$field])) {
                return $this->json([
                    'error' => sprintf('%s es obligatorio y debe ser numerico', $field),
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        if (isset($data['fecha']) && !is_string($data['fecha'])) {
            return $this->json(['error' => 'fecha debe ser un string ISO-8601'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
            $payload = [
                'fecha' => !empty($data['fecha']) ? $data['fecha'] : $now,
                'distancia_medida_cm' => (string) $data['distancia_medida_cm'],
                'profundidad_cm' => (string) $data['profundidad_cm'],
                'msnm' => (string) $data['msnm'],
                'created_at' => $now,
            ];

            $row = $this->insertReturning('cnb_app.mediciones_nivel', $payload);

            return $this->json(['data' => $this->registry->normalizeRow($row)], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/tareas/{id}/avance', name: 'api_tareas_avance', requirements: ['id' => '\d+'], methods: ['POST', 'PATCH'])]
    public function taskProgress(int $id, Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $progress = isset($data['progreso']) ? (int) $data['progreso'] : null;
        $status = $data['estado'] ?? null;

        if ($progress === null || $status === null) {
            return $this->json(['error' => 'progreso y estado son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $update = [
                'progreso' => $progress,
                'estado' => $status,
            ];

            if ($status === 'en_progreso') {
                $update['fecha_inicio'] = date('c');
            }

            if ($status === 'finalizada') {
                $update['fecha_finalizacion'] = date('c');
            }

            $this->connection->update('cnb_app.tareas', $update, ['id' => $id]);
            $this->connection->insert('cnb_app.avances_tarea', [
                'tarea_id' => $id,
                'marinero_id' => $data['marinero_id'] ?? null,
                'progreso' => $progress,
                'estado' => $status,
                'comentario' => $data['comentario'] ?? null,
            ]);

            return $this->rowResponse($this->registry->get('tareas'), $id);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/tareas/{id}/asignaciones', name: 'api_tareas_asignar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assignTask(int $id, Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);

        if (!isset($data['marinero_id'])) {
            return $this->json(['error' => 'marinero_id es obligatorio'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->connection->insert('cnb_app.tarea_asignaciones', [
                'tarea_id' => $id,
                'marinero_id' => (int) $data['marinero_id'],
                'rol' => $data['rol'] ?? null,
            ]);
            $this->connection->executeStatement(
                "UPDATE cnb_app.tareas SET estado = 'asignada' WHERE id = ? AND estado = 'pendiente'",
                [$id]
            );

            return $this->json(['data' => ['tarea_id' => $id, 'marinero_id' => (int) $data['marinero_id']]], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    // Excluye "socio": esas rutas las atiende SocioPortalController (/api/socio/*).
    #[Route('/{resource}', name: 'api_resource_index', requirements: ['resource' => '(?!socio$)[a-z-]+'], methods: ['GET'])]
    public function index(string $resource, Request $request): JsonResponse
    {
        $definition = $this->registry->get($resource);

        if ($resource === 'embarcaciones') {
            $numeroSocio = $request->query->get('numeroSocio') ?? $request->query->get('socioId');
            $ambito = $request->query->get('ambito');
            $rows = $this->registry->fetchEmbarcacionesApi(
                $this->connection,
                $numeroSocio !== null && $numeroSocio !== '' ? (int) $numeroSocio : null,
                is_string($ambito) ? $ambito : null,
            );

            return $this->json(['data' => $rows]);
        }

        if (\in_array($resource, ['ubicaciones', 'estados-padron'], true)) {
            $pk = $this->registry->primaryKey($definition);
            $query = $this->connection->createQueryBuilder()
                ->select('*')
                ->from($definition['table'])
                ->orderBy($pk, 'ASC')
                ->setMaxResults(500);

            if ($request->query->getBoolean('soloActivos', true) && isset($definition['fields']['activo'])) {
                $query->andWhere('activo = TRUE');
            }
            if ($resource === 'ubicaciones' && $request->query->has('ambito')) {
                $query->andWhere('ambito = :ambito')->setParameter('ambito', $request->query->get('ambito'));
            }

            return $this->json(['data' => array_map($this->registry->normalizeRow(...), $query->fetchAllAssociative())]);
        }

        $pk = $this->registry->primaryKey($definition);
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($definition['table'])
            ->orderBy($pk, 'DESC')
            ->setMaxResults(500);

        $fields = $definition['fields'];

        if ($request->query->has('estado') && isset($fields['estado'])) {
            $query->andWhere('estado = :estado')->setParameter('estado', $request->query->get('estado'));
        }

        $socioFilter = $request->query->get('numeroSocio') ?? $request->query->get('socioId');
        if ($socioFilter !== null && $socioFilter !== '' && isset($fields['numero_socio'])) {
            $query->andWhere('numero_socio = :numeroSocio')->setParameter('numeroSocio', (int) $socioFilter);
        }

        if ($request->query->has('marineroId') && isset($fields['marinero_id'])) {
            $query->andWhere('marinero_id = :marineroId')->setParameter('marineroId', $request->query->getInt('marineroId'));
        }

        $dateColumn = isset($fields['fecha_planificada'])
            ? 'fecha_planificada'
            : (isset($fields['fecha_inicio'])
                ? 'fecha_inicio'
                : (isset($fields['fecha']) ? 'fecha' : null));
        if ($dateColumn && $request->query->has('desde')) {
            $query->andWhere($dateColumn . ' >= :desde')->setParameter('desde', $request->query->get('desde'));
        }
        if ($dateColumn && $request->query->has('hasta')) {
            $query->andWhere($dateColumn . ' <= :hasta')->setParameter('hasta', $request->query->get('hasta'));
        }

        $rows = $query->fetchAllAssociative();

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/{resource}', name: 'api_resource_create', requirements: ['resource' => '(?!socio$)[a-z-]+'], methods: ['POST'])]
    public function create(string $resource, Request $request): JsonResponse
    {
        $definition = $this->registry->get($resource);

        try {
            $payload = $this->registry->payloadFor($definition, $this->jsonBody($request));
            $row = $this->insertReturning($definition['table'], $payload);

            return $this->json(['data' => $this->registry->normalizeRow($row)], Response::HTTP_CREATED);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/{resource}/{id}', name: 'api_resource_show', requirements: ['resource' => '(?!socio$)[a-z-]+', 'id' => '[^/]+'], methods: ['GET'])]
    public function show(string $resource, string $id): JsonResponse
    {
        $definition = $this->registry->get($resource);

        return $this->rowResponse($definition, $id);
    }

    #[Route('/{resource}/{id}', name: 'api_resource_update', requirements: ['resource' => '(?!socio$)[a-z-]+', 'id' => '[^/]+'], methods: ['PATCH', 'PUT'])]
    public function update(string $resource, string $id, Request $request): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $pk = $this->registry->primaryKey($definition);

        try {
            $payload = $this->registry->payloadFor($definition, $this->jsonBody($request), patch: true);

            if ($payload !== []) {
                $this->connection->update($definition['table'], $payload, [$pk => $id]);
            }

            return $this->rowResponse($definition, $id);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/{resource}/{id}', name: 'api_resource_delete', requirements: ['resource' => '(?!socio$)[a-z-]+', 'id' => '[^/]+'], methods: ['DELETE'])]
    public function delete(string $resource, string $id): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $pk = $this->registry->primaryKey($definition);

        try {
            $this->connection->delete($definition['table'], [$pk => $id]);

            return $this->json(null, Response::HTTP_NO_CONTENT);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $content = $request->getContent();

        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function insertReturning(string $table, array $payload): array
    {
        $columns = array_keys($payload);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING *',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        return $this->connection->fetchAssociative($sql, $payload) ?: [];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function rowResponse(array $definition, string|int $id): JsonResponse
    {
        $pk = $this->registry->primaryKey($definition);
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ?', $definition['table'], $pk),
            [$id]
        );

        if (!$row) {
            return $this->json(['error' => 'Registro no encontrado'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->registry->normalizeRow($row)]);
    }

    private function dbalError(DbalException $exception): JsonResponse
    {
        return $this->json([
            'error' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
