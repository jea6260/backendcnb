<?php

namespace App\Controller;

use App\Service\ResourceRegistry;
use App\Service\UsuarioAuthService;
use App\Service\VaraderoService;
use Doctrine\DBAL\ArrayParameterType;
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
    private const SENSITIVE_RESOURCES = [
        'tareas',
        'reservas-varadero',
        'comandos-voz',
        'frases-comando-voz',
        'embarcaciones',
        'socio-embarcacion',
        'vehiculos',
        'marineros',
        'socios',
        'espacios-varadero',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
        private readonly UsuarioAuthService $usuarios,
        private readonly VaraderoService $varadero,
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
        $denied = $this->requireEmpleado($request);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

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
        $denied = $this->requireEmpleado($request);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

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
        if ($resource !== 'socios') {
            $rows = $this->attachSocioNombres($rows);
        }

        return $this->json(['data' => array_map($this->registry->normalizeRow(...), $rows)]);
    }

    #[Route('/{resource}', name: 'api_resource_create', requirements: ['resource' => '(?!socio$)[a-z-]+'], methods: ['POST'])]
    public function create(string $resource, Request $request): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $denied = $this->requireEmpleadoIfSensitive($request, $resource);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        if ($resource === 'reservas-varadero') {
            $data = $this->jsonBody($request);
            $data['estado'] = 'pendiente';
            $resultado = $this->varadero->validarYPrepararReserva($data);
            if (!$resultado['ok']) {
                return $this->json(['error' => $resultado['error']], $resultado['status']);
            }

            try {
                $row = $this->insertReturning('cnb_app.reservas_varadero', $resultado['payload']);

                return $this->json(['data' => $this->registry->normalizeRow($row)], Response::HTTP_CREATED);
            } catch (DbalException $exception) {
                return $this->dbalError($exception);
            }
        }

        try {
            $payload = $this->registry->payloadFor($definition, $this->jsonBody($request));
            $row = $this->insertReturning($definition['table'], $payload);
            $this->syncEmbarcacionTitular($resource, $row);

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
        $denied = $this->requireEmpleadoIfSensitive($request, $resource);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        $pk = $this->registry->primaryKey($definition);

        try {
            $payload = $this->registry->payloadFor($definition, $this->jsonBody($request), patch: true);

            if ($resource === 'reservas-varadero' && isset($payload['estado'])) {
                $actor = $this->actor($request);
                if ($actor instanceof JsonResponse) {
                    return $actor;
                }
                $paso = $this->varadero->transicionar((int) $id, (string) $payload['estado'], $actor['perfil']);
                if (!$paso['ok']) {
                    return $this->json(['error' => $paso['error']], $paso['status']);
                }
                unset($payload['estado']);
            }

            if ($payload !== []) {
                $this->connection->update($definition['table'], $payload, [$pk => $id]);
            }

            if ($resource === 'embarcaciones') {
                $row = $this->connection->fetchAssociative(
                    sprintf('SELECT * FROM %s WHERE %s = ?', $definition['table'], $pk),
                    [$id]
                );
                if ($row) {
                    $this->syncEmbarcacionTitular($resource, $row);
                }
            }

            return $this->rowResponse($definition, $id);
        } catch (DbalException $exception) {
            return $this->dbalError($exception);
        }
    }

    #[Route('/{resource}/{id}', name: 'api_resource_delete', requirements: ['resource' => '(?!socio$)[a-z-]+', 'id' => '[^/]+'], methods: ['DELETE'])]
    public function delete(string $resource, string $id, Request $request): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $denied = $this->requireEmpleadoIfSensitive($request, $resource);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

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

    /**
     * @return array{perfil: string}|JsonResponse
     */
    private function actor(Request $request): array|JsonResponse
    {
        $header = $request->headers->get('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
        $user = $this->usuarios->userFromToken($token);
        if (!$user) {
            return $this->json(['error' => 'Token requerido'], Response::HTTP_UNAUTHORIZED);
        }

        return ['perfil' => $this->usuarios->perfil($user)];
    }

    private function requireEmpleadoIfSensitive(Request $request, string $resource): ?JsonResponse
    {
        if (!in_array($resource, self::SENSITIVE_RESOURCES, true)) {
            return null;
        }

        return $this->requireEmpleado($request);
    }

    private function requireEmpleado(Request $request): ?JsonResponse
    {
        if (!$this->usuarios->schemaReady()) {
            return $this->json(
                ['error' => 'Falta la tabla de usuarios. Ejecutar sql/016_usuarios_auth.sql'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $header = $request->headers->get('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
        $user = $this->usuarios->userFromToken($token);
        if (!$user || !in_array($user['rol'] ?? '', ['admin', 'empleado', 'socio'], true)) {
            return $this->json(['error' => 'Token de empleado requerido'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachSocioNombres(array $rows): array
    {
        if ($rows === [] || !array_key_exists('numero_socio', $rows[0])) {
            return $rows;
        }

        $ids = [];
        foreach ($rows as $row) {
            $numero = (int) ($row['numero_socio'] ?? 0);
            if ($numero > 0) {
                $ids[$numero] = $numero;
            }
        }
        if ($ids === []) {
            return $rows;
        }

        $socios = $this->connection->fetchAllAssociative(
            'SELECT numero_socio, nombre, apellido FROM cnb_app.socios WHERE numero_socio IN (:ids)',
            ['ids' => array_values($ids)],
            ['ids' => ArrayParameterType::INTEGER]
        );
        $porNumero = [];
        foreach ($socios as $socio) {
            $porNumero[(int) $socio['numero_socio']] = $socio;
        }

        foreach ($rows as &$row) {
            $socio = $porNumero[(int) ($row['numero_socio'] ?? 0)] ?? null;
            $row['socio_nombre'] = $socio['nombre'] ?? null;
            $row['socio_apellido'] = $socio['apellido'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Mantiene el pivote N:N cuando el alta/edicion sigue enviando numero_socio legado.
     *
     * @param array<string, mixed> $row
     */
    private function syncEmbarcacionTitular(string $resource, array $row): void
    {
        if ($resource !== 'embarcaciones') {
            return;
        }

        $numeroSocio = $row['numero_socio'] ?? null;
        $embarcacionId = $row['id'] ?? null;
        if ($numeroSocio === null || $numeroSocio === '' || $embarcacionId === null) {
            return;
        }

        try {
            $this->connection->executeStatement(
                "INSERT INTO cnb_app.socio_embarcacion (numero_socio, embarcacion_id, rol)
                 VALUES (?, ?, 'titular')
                 ON CONFLICT (numero_socio, embarcacion_id) DO NOTHING",
                [(int) $numeroSocio, (int) $embarcacionId]
            );
        } catch (DbalException) {
            // La migracion 015/016 todavia no corrio.
        }
    }

    private function dbalError(DbalException $exception): JsonResponse
    {
        return $this->json([
            'error' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
