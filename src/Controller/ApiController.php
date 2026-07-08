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

            return $this->rowResponse('cnb_app.tareas', $id);
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

    #[Route('/{resource}', name: 'api_resource_index', requirements: ['resource' => '[a-z-]+'], methods: ['GET'])]
    public function index(string $resource, Request $request): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($definition['table'])
            ->orderBy('id', 'DESC')
            ->setMaxResults(500);

        $fields = $definition['fields'];

        if ($request->query->has('estado') && isset($fields['estado'])) {
            $query->andWhere('estado = :estado')->setParameter('estado', $request->query->get('estado'));
        }

        if ($request->query->has('socioId') && isset($fields['socio_id'])) {
            $query->andWhere('socio_id = :socioId')->setParameter('socioId', $request->query->getInt('socioId'));
        }

        if ($request->query->has('marineroId') && isset($fields['marinero_id'])) {
            $query->andWhere('marinero_id = :marineroId')->setParameter('marineroId', $request->query->getInt('marineroId'));
        }

        $dateColumn = isset($fields['fecha_planificada']) ? 'fecha_planificada' : (isset($fields['fecha_inicio']) ? 'fecha_inicio' : null);
        if ($dateColumn && $request->query->has('desde')) {
            $query->andWhere($dateColumn . ' >= :desde')->setParameter('desde', $request->query->get('desde'));
        }
        if ($dateColumn && $request->query->has('hasta')) {
            $query->andWhere($dateColumn . ' <= :hasta')->setParameter('hasta', $request->query->get('hasta'));
        }

        $rows = $query->fetchAllAssociative();

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/{resource}', name: 'api_resource_create', requirements: ['resource' => '[a-z-]+'], methods: ['POST'])]
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

    #[Route('/{resource}/{id}', name: 'api_resource_show', requirements: ['resource' => '[a-z-]+', 'id' => '\d+'], methods: ['GET'])]
    public function show(string $resource, int $id): JsonResponse
    {
        $definition = $this->registry->get($resource);

        return $this->rowResponse($definition['table'], $id);
    }

    #[Route('/{resource}/{id}', name: 'api_resource_update', requirements: ['resource' => '[a-z-]+', 'id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(string $resource, int $id, Request $request): JsonResponse
    {
        $definition = $this->registry->get($resource);

        try {
            $payload = $this->registry->payloadFor($definition, $this->jsonBody($request), patch: true);

            if ($payload !== []) {
                $this->connection->update($definition['table'], $payload, ['id' => $id]);
            }

            return $this->rowResponse($definition['table'], $id);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/{resource}/{id}', name: 'api_resource_delete', requirements: ['resource' => '[a-z-]+', 'id' => '\d+'], methods: ['DELETE'])]
    public function delete(string $resource, int $id): JsonResponse
    {
        $definition = $this->registry->get($resource);

        try {
            $this->connection->delete($definition['table'], ['id' => $id]);

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

    private function rowResponse(string $table, int $id): JsonResponse
    {
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $table), [$id]);

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
