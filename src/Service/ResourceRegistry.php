<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResourceRegistry
{
    private const SCHEMA = 'cnb_app';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->withSchema([
            'socios' => [
                'table' => 'socios',
                'label' => 'Socios',
                'singular' => 'Socio',
                'description' => 'Padron de socios y estado administrativo.',
                'list_id' => false,
                'fields' => [
                    'numero_socio' => ['label' => 'Numero de socio', 'type' => 'text', 'required' => true, 'list' => true],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'apellido' => ['label' => 'Apellido', 'type' => 'text', 'required' => true, 'list' => true],
                    'email' => ['label' => 'Email', 'type' => 'email', 'list' => true],
                    'telefono' => ['label' => 'Telefono', 'type' => 'text', 'list' => true],
                    'documento' => ['label' => 'Documento', 'type' => 'text'],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'activo', 'list' => true, 'choices' => [
                        'activo' => 'Activo',
                        'suspendido' => 'Suspendido',
                        'baja' => 'Baja',
                    ]],
                ],
            ],
            'marineros' => [
                'table' => 'marineros',
                'label' => 'Marineros',
                'singular' => 'Marinero',
                'description' => 'Equipo operativo al que se asignan tareas.',
                'fields' => [
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'apellido' => ['label' => 'Apellido', 'type' => 'text', 'required' => true, 'list' => true],
                    'email' => ['label' => 'Email', 'type' => 'email'],
                    'telefono' => ['label' => 'Telefono', 'type' => 'text', 'list' => true],
                    'especialidad' => ['label' => 'Especialidad', 'type' => 'text', 'list' => true],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'activo', 'list' => true, 'choices' => [
                        'activo' => 'Activo',
                        'licencia' => 'Licencia',
                        'baja' => 'Baja',
                    ]],
                ],
            ],
            'embarcaciones' => [
                'table' => 'embarcaciones',
                'label' => 'Embarcaciones',
                'singular' => 'Embarcacion',
                'description' => 'Embarcaciones de socios y del club.',
                'fields' => [
                    'socio_id' => ['label' => 'Socio propietario', 'type' => 'relation', 'relation' => ['table' => 'socios', 'label' => "apellido || ', ' || nombre || ' #' || numero_socio"]],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'matricula' => ['label' => 'Matricula', 'type' => 'text', 'list' => true],
                    'tipo' => ['label' => 'Tipo', 'type' => 'text', 'required' => true, 'default' => 'velero', 'list' => true],
                    'eslora_m' => ['label' => 'Eslora (m)', 'type' => 'decimal', 'required' => true, 'list' => true],
                    'manga_m' => ['label' => 'Manga (m)', 'type' => 'decimal'],
                    'calado_m' => ['label' => 'Calado (m)', 'type' => 'decimal'],
                    'es_cnb' => ['label' => 'Propiedad CNB', 'type' => 'boolean', 'default' => false, 'list' => true],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'activa', 'list' => true, 'choices' => [
                        'activa' => 'Activa',
                        'mantenimiento' => 'Mantenimiento',
                        'inactiva' => 'Inactiva',
                    ]],
                    'observaciones' => ['label' => 'Observaciones', 'type' => 'textarea'],
                ],
            ],
            'vehiculos' => [
                'table' => 'vehiculos',
                'label' => 'Tractores y vehiculos',
                'singular' => 'Vehiculo',
                'description' => 'Activos moviles del club para operacion y mantenimiento.',
                'fields' => [
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'tipo' => ['label' => 'Tipo', 'type' => 'choice', 'required' => true, 'default' => 'tractor', 'list' => true, 'choices' => [
                        'tractor' => 'Tractor',
                        'camioneta' => 'Camioneta',
                        'grua' => 'Grua',
                        'otro' => 'Otro',
                    ]],
                    'patente' => ['label' => 'Patente', 'type' => 'text', 'list' => true],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'disponible', 'list' => true, 'choices' => [
                        'disponible' => 'Disponible',
                        'en_uso' => 'En uso',
                        'mantenimiento' => 'Mantenimiento',
                        'fuera_servicio' => 'Fuera de servicio',
                    ]],
                    'horometro' => ['label' => 'Horometro', 'type' => 'decimal'],
                    'ultimo_mantenimiento' => ['label' => 'Ultimo mantenimiento', 'type' => 'date'],
                    'observaciones' => ['label' => 'Observaciones', 'type' => 'textarea'],
                ],
            ],
            'espacios-varadero' => [
                'table' => 'espacios_varadero',
                'label' => 'Espacios de varadero',
                'singular' => 'Espacio de varadero',
                'description' => 'Tres posiciones disponibles, filtradas por eslora maxima.',
                'fields' => [
                    'codigo' => ['label' => 'Codigo', 'type' => 'text', 'required' => true, 'list' => true],
                    'descripcion' => ['label' => 'Descripcion', 'type' => 'text', 'list' => true],
                    'eslora_max_m' => ['label' => 'Eslora maxima (m)', 'type' => 'decimal', 'required' => true, 'list' => true],
                    'manga_max_m' => ['label' => 'Manga maxima (m)', 'type' => 'decimal'],
                    'activo' => ['label' => 'Activo', 'type' => 'boolean', 'default' => true, 'list' => true],
                ],
            ],
            'reservas-varadero' => [
                'table' => 'reservas_varadero',
                'label' => 'Reservas de varadero',
                'singular' => 'Reserva de varadero',
                'description' => 'Reservas de limpieza y mantenimiento con cupo anual por socio.',
                'fields' => [
                    'socio_id' => ['label' => 'Socio', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'socios', 'label' => "apellido || ', ' || nombre || ' #' || numero_socio"]],
                    'embarcacion_id' => ['label' => 'Embarcacion', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'embarcaciones', 'label' => "nombre || COALESCE(' - ' || matricula, '')"]],
                    'espacio_id' => ['label' => 'Espacio', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'espacios_varadero', 'label' => "codigo || ' (' || eslora_max_m || 'm)'"]],
                    'fecha_inicio' => ['label' => 'Fecha inicio', 'type' => 'date', 'required' => true, 'list' => true],
                    'fecha_fin' => ['label' => 'Fecha fin', 'type' => 'date', 'required' => true, 'list' => true],
                    'cantidad_dias' => ['label' => 'Dias', 'type' => 'integer', 'readonly' => true, 'list' => true],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'pendiente', 'list' => true, 'choices' => [
                        'pendiente' => 'Pendiente',
                        'confirmada' => 'Confirmada',
                        'en_curso' => 'En curso',
                        'finalizada' => 'Finalizada',
                        'cancelada' => 'Cancelada',
                        'rechazada' => 'Rechazada',
                    ]],
                    'motivo' => ['label' => 'Motivo', 'type' => 'text', 'required' => true, 'default' => 'limpieza_mantenimiento'],
                    'observaciones' => ['label' => 'Observaciones', 'type' => 'textarea'],
                ],
            ],
            'tareas' => [
                'table' => 'tareas',
                'label' => 'Tareas',
                'singular' => 'Tarea',
                'description' => 'Planificacion, asignacion y avance de trabajos operativos.',
                'fields' => [
                    'titulo' => ['label' => 'Titulo', 'type' => 'text', 'required' => true, 'list' => true],
                    'descripcion' => ['label' => 'Descripcion', 'type' => 'textarea'],
                    'tipo' => ['label' => 'Tipo', 'type' => 'choice', 'required' => true, 'default' => 'general', 'list' => true, 'choices' => [
                        'general' => 'General',
                        'varadero' => 'Varadero',
                        'mantenimiento' => 'Mantenimiento',
                        'limpieza' => 'Limpieza',
                        'seguridad' => 'Seguridad',
                        'administrativa' => 'Administrativa',
                    ]],
                    'prioridad' => ['label' => 'Prioridad', 'type' => 'choice', 'required' => true, 'default' => 'media', 'list' => true, 'choices' => [
                        'baja' => 'Baja',
                        'media' => 'Media',
                        'alta' => 'Alta',
                        'urgente' => 'Urgente',
                    ]],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'pendiente', 'list' => true, 'choices' => [
                        'pendiente' => 'Pendiente',
                        'asignada' => 'Asignada',
                        'en_progreso' => 'En progreso',
                        'bloqueada' => 'Bloqueada',
                        'finalizada' => 'Finalizada',
                        'cancelada' => 'Cancelada',
                    ]],
                    'progreso' => ['label' => 'Progreso %', 'type' => 'integer', 'default' => 0, 'list' => true],
                    'socio_id' => ['label' => 'Socio', 'type' => 'relation', 'relation' => ['table' => 'socios', 'label' => "apellido || ', ' || nombre || ' #' || numero_socio"]],
                    'embarcacion_id' => ['label' => 'Embarcacion', 'type' => 'relation', 'relation' => ['table' => 'embarcaciones', 'label' => "nombre || COALESCE(' - ' || matricula, '')"]],
                    'reserva_varadero_id' => ['label' => 'Reserva varadero', 'type' => 'relation', 'relation' => ['table' => 'reservas_varadero', 'label' => "'Reserva #' || id || ' ' || fecha_inicio || ' a ' || fecha_fin"]],
                    'vehiculo_id' => ['label' => 'Vehiculo', 'type' => 'relation', 'relation' => ['table' => 'vehiculos', 'label' => "nombre || ' - ' || tipo"]],
                    'fecha_planificada' => ['label' => 'Fecha planificada', 'type' => 'date', 'list' => true],
                    'fecha_limite' => ['label' => 'Fecha limite', 'type' => 'date'],
                    'fecha_inicio' => ['label' => 'Inicio real', 'type' => 'datetime'],
                    'fecha_finalizacion' => ['label' => 'Finalizacion', 'type' => 'datetime'],
                ],
            ],
            'tarea-asignaciones' => [
                'table' => 'tarea_asignaciones',
                'label' => 'Asignaciones de tareas',
                'singular' => 'Asignacion',
                'description' => 'Relacion entre tareas y marineros asignados.',
                'fields' => [
                    'tarea_id' => ['label' => 'Tarea', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'tareas', 'label' => "titulo"]],
                    'marinero_id' => ['label' => 'Marinero', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'marineros', 'label' => "apellido || ', ' || nombre"]],
                    'rol' => ['label' => 'Rol', 'type' => 'text', 'list' => true],
                    'finalizado_at' => ['label' => 'Finalizado', 'type' => 'datetime'],
                ],
            ],
            'avances-tarea' => [
                'table' => 'avances_tarea',
                'label' => 'Avances de tareas',
                'singular' => 'Avance',
                'description' => 'Bitacora de progreso y comentarios de trabajos.',
                'fields' => [
                    'tarea_id' => ['label' => 'Tarea', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'tareas', 'label' => "titulo"]],
                    'marinero_id' => ['label' => 'Marinero', 'type' => 'relation', 'relation' => ['table' => 'marineros', 'label' => "apellido || ', ' || nombre"]],
                    'progreso' => ['label' => 'Progreso %', 'type' => 'integer', 'required' => true, 'list' => true],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'en_progreso', 'list' => true, 'choices' => [
                        'pendiente' => 'Pendiente',
                        'asignada' => 'Asignada',
                        'en_progreso' => 'En progreso',
                        'bloqueada' => 'Bloqueada',
                        'finalizada' => 'Finalizada',
                        'cancelada' => 'Cancelada',
                    ]],
                    'comentario' => ['label' => 'Comentario', 'type' => 'textarea', 'list' => true],
                ],
            ],
            'novedades' => [
                'table' => 'novedades',
                'label' => 'Novedades',
                'singular' => 'Novedad',
                'description' => 'Comunicaciones publicadas para socios.',
                'fields' => [
                    'titulo' => ['label' => 'Titulo', 'type' => 'text', 'required' => true, 'list' => true],
                    'contenido' => ['label' => 'Contenido', 'type' => 'textarea', 'required' => true],
                    'publicado_at' => ['label' => 'Publicado', 'type' => 'datetime', 'list' => true],
                    'activo' => ['label' => 'Activo', 'type' => 'boolean', 'default' => true, 'list' => true],
                ],
            ],
            'camaras' => [
                'table' => 'camaras',
                'label' => 'Camaras',
                'singular' => 'Camara',
                'description' => 'Streams de video visibles para socios.',
                'fields' => [
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'ubicacion' => ['label' => 'Ubicacion', 'type' => 'text', 'list' => true],
                    'stream_url' => ['label' => 'URL stream', 'type' => 'text', 'required' => true],
                    'activa' => ['label' => 'Activa', 'type' => 'boolean', 'default' => true, 'list' => true],
                    'orden' => ['label' => 'Orden', 'type' => 'integer', 'default' => 0, 'list' => true],
                ],
            ],
            'socio-acceso' => [
                'table' => 'socio_acceso',
                'label' => 'Accesos socios',
                'singular' => 'Acceso socio',
                'description' => 'Credenciales de portal para socios.',
                'fields' => [
                    'socio_id' => ['label' => 'Socio', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'socios', 'label' => "apellido || ', ' || nombre || ' #' || numero_socio"]],
                    'email' => ['label' => 'Email login', 'type' => 'email', 'required' => true, 'list' => true],
                    'password_hash' => ['label' => 'Hash clave', 'type' => 'text', 'required' => true],
                    'biometric_habilitado' => ['label' => 'Biometria', 'type' => 'boolean', 'default' => false, 'list' => true],
                ],
            ],
            'mediciones-nivel' => [
                'table' => 'mediciones_nivel',
                'label' => 'Mediciones de nivel',
                'singular' => 'Medicion de nivel',
                'description' => 'Lecturas del sensor ESP8266 (distancia, profundidad y msnm).',
                'fields' => [
                    'fecha' => ['label' => 'Fecha', 'type' => 'datetime', 'list' => true],
                    'distancia_medida_cm' => ['label' => 'Distancia medida (cm)', 'type' => 'decimal', 'required' => true, 'list' => true],
                    'profundidad_cm' => ['label' => 'Profundidad (cm)', 'type' => 'decimal', 'required' => true, 'list' => true],
                    'msnm' => ['label' => 'msnm', 'type' => 'decimal', 'required' => true, 'list' => true],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $resources
     * @return array<string, array<string, mixed>>
     */
    private function withSchema(array $resources): array
    {
        foreach ($resources as &$definition) {
            $definition['table'] = $this->qualifyTable($definition['table']);

            foreach ($definition['fields'] as &$field) {
                if (($field['type'] ?? null) === 'relation') {
                    $field['relation']['table'] = $this->qualifyTable($field['relation']['table']);
                }
            }
        }

        return $resources;
    }

    private function qualifyTable(string $table): string
    {
        if (str_contains($table, '.')) {
            return $table;
        }

        return self::SCHEMA . '.' . $table;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $resource): array
    {
        $resources = $this->all();

        if (!isset($resources[$resource])) {
            throw new NotFoundHttpException(sprintf('Recurso "%s" no encontrado.', $resource));
        }

        return $resources[$resource] + ['name' => $resource];
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    public function listableColumns(array $definition): array
    {
        return ['id', ...array_keys($this->listFields($definition))];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function fetchListRows(
        Connection $connection,
        array $definition,
        string $sort,
        string $direction,
        array $filters,
    ): array {
        $columns = $this->listableColumns($definition);
        $sort = in_array($sort, $columns, true) ? $sort : 'id';
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        $query = $connection->createQueryBuilder()
            ->select('t.*')
            ->from($definition['table'], 't')
            ->orderBy('t.'.$sort, $direction)
            ->setMaxResults(300);

        foreach ($filters as $column => $value) {
            $value = trim($value);
            if ($value === '' || !in_array($column, $columns, true)) {
                continue;
            }

            $parameter = 'filter_'.$column;
            $query
                ->andWhere(sprintf('CAST(t.%s AS TEXT) ILIKE :%s', $column, $parameter))
                ->setParameter($parameter, '%'.$this->escapeLike($value).'%');
        }

        return array_map(
            $this->normalizeRow(...),
            $query->executeQuery()->fetchAllAssociative()
        );
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, array<string, mixed>>
     */
    public function listFields(array $definition): array
    {
        return array_filter(
            $definition['fields'],
            static fn (array $field): bool => (bool) ($field['list'] ?? false)
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, array<string, mixed>>
     */
    public function formFields(array $definition): array
    {
        return array_filter(
            $definition['fields'],
            static fn (array $field): bool => !($field['readonly'] ?? false)
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function payloadFor(array $definition, array $source, bool $patch = false, bool $form = false): array
    {
        $payload = [];

        foreach ($this->formFields($definition) as $column => $field) {
            if ($field['type'] === 'boolean' && $form && !$patch) {
                $payload[$column] = array_key_exists($column, $source);
                continue;
            }

            if (!array_key_exists($column, $source)) {
                if (!$patch && array_key_exists('default', $field)) {
                    $payload[$column] = $field['default'];
                }
                continue;
            }

            $payload[$column] = $this->coerceValue($source[$column], $field);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, array{id: mixed, label: string}>
     */
    public function relationOptions(Connection $connection, array $field): array
    {
        if (($field['type'] ?? null) !== 'relation') {
            return [];
        }

        $relation = $field['relation'];
        $sql = sprintf(
            'SELECT id, %s AS label FROM %s ORDER BY label ASC LIMIT 500',
            $relation['label'],
            $relation['table']
        );

        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'label' => (string) $row['label']],
            $connection->fetchAllAssociative($sql)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_resource($value)) {
                $row[$key] = stream_get_contents($value);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function coerceValue(mixed $value, array $field): mixed
    {
        if ($value === '') {
            return null;
        }

        return match ($field['type']) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'integer', 'relation' => $value === null ? null : (int) $value,
            'decimal' => $value === null ? null : (string) $value,
            default => $value,
        };
    }
}
