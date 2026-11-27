<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResourceRegistry
{
    private const SCHEMA = 'cnb_app';

    private const SOCIO_LABEL = "concat_ws(', ', NULLIF(btrim(apellido), ''), NULLIF(btrim(nombre), '')) || ' #' || numero_socio";

    /** @var list<string> */
    private const SOCIOS_OCULTOS = ['BA', 'MR', 'AE', 'baja', 'suspendido'];

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
                'primary_key' => 'numero_socio',
                'list_id' => false,
                'list_scope' => [
                    'column' => 'estado',
                    'exclude' => self::SOCIOS_OCULTOS,
                ],
                'fields' => [
                    'numero_socio' => ['label' => 'Numero de socio', 'type' => 'integer', 'required' => true, 'list' => true, 'primary' => true],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'apellido' => ['label' => 'Apellido', 'type' => 'text', 'required' => true, 'list' => true],
                    'email' => ['label' => 'Email', 'type' => 'email', 'list' => true],
                    'telefono' => ['label' => 'Telefono', 'type' => 'text', 'list' => true],
                    'documento' => ['label' => 'Documento', 'type' => 'text'],
                    'foto_perfil' => [
                        'label' => 'Foto perfil',
                        'type' => 'image',
                        'list' => true,
                        'help' => 'Foto del socio para carnet y reconocimiento facial.',
                    ],
                    'fecha_ingreso' => ['label' => 'Ingreso al club', 'type' => 'date', 'list' => true],
                    'estado' => ['label' => 'Estado', 'type' => 'choice', 'required' => true, 'default' => 'AO', 'list' => true, 'choices' => [
                        'AO' => 'Activo',
                        'MR' => 'Menor',
                        'AA' => 'Conyuge',
                        'VO' => 'Vitalicio',
                        'AE' => 'Ausente',
                        'HO' => 'Honorario',
                        'BA' => 'Baja',
                        'activo' => 'Activo (anterior)',
                        'suspendido' => 'Suspendido',
                        'baja' => 'Baja (anterior)',
                    ]],
                ],
            ],
            'socio-embarcacion' => [
                'table' => 'socio_embarcacion',
                'label' => 'Socios ↔ Embarcaciones',
                'singular' => 'Vinculo socio-embarcacion',
                'description' => 'Un socio puede tener varias embarcaciones y una embarcacion varios socios.',
                'list_scope' => [
                    'via' => [
                        'column' => 'numero_socio',
                        'table' => 'cnb_app.socios',
                        'key' => 'numero_socio',
                        'estado' => 'estado',
                    ],
                    'exclude' => self::SOCIOS_OCULTOS,
                    'choices' => [
                        'AO' => 'Activo',
                        'AA' => 'Conyuge',
                        'VO' => 'Vitalicio',
                        'HO' => 'Honorario',
                        'MR' => 'Menor',
                        'AE' => 'Ausente',
                        'BA' => 'Baja',
                    ],
                ],
                'fields' => [
                    'numero_socio' => ['label' => 'Socio', 'type' => 'relation', 'required' => true, 'list' => true, 'help' => 'Activo, vitalicio o cónyuge, tenga o no embarcación.', 'relation' => ['table' => 'socios', 'key' => 'numero_socio', 'label' => self::SOCIO_LABEL, 'solo_habilitados' => true]],
                    'embarcacion_id' => ['label' => 'Embarcacion', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'embarcaciones', 'label' => "nombre || COALESCE(' - ' || matricula, '')"]],
                    'rol' => ['label' => 'Rol', 'type' => 'choice', 'required' => true, 'default' => 'titular', 'list' => true, 'choices' => [
                        'titular' => 'Titular',
                        'cotitular' => 'Cotitular',
                        'autorizado' => 'Autorizado',
                    ]],
                ],
            ],
            'marineros' => [
                'table' => 'personal_cnb',
                'label' => 'Personal del club',
                'singular' => 'Empleado del club',
                'description' => 'Empleados del club. El rol define si entra como admin, marinero, operaciones o guardia.',
                'fields' => [
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'apellido' => ['label' => 'Apellido', 'type' => 'text', 'required' => true, 'list' => true],
                    'rol' => ['label' => 'Rol', 'type' => 'choice', 'required' => true, 'default' => 'marinero', 'list' => true, 'choices' => [
                        'admin' => 'Admin',
                        'marinero' => 'Marinero',
                        'operaciones' => 'Operaciones',
                        'guardia' => 'Guardia',
                    ]],
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
                'description' => 'Padron de embarcaciones (planilla Propietarios Marinas: Agua / Tierra).',
                'fields' => [
                    'ambito' => ['label' => 'Ambito', 'type' => 'choice', 'required' => true, 'default' => 'agua', 'list' => true, 'choices' => [
                        'agua' => 'Agua',
                        'tierra' => 'Tierra',
                    ]],
                    'numero_socio' => [
                        'label' => 'Socio titular (legacy)',
                        'type' => 'relation',
                        'list' => true,
                        'help' => 'Preferir vinculos en Socios ↔ Embarcaciones. Se mantiene por compatibilidad.',
                        'relation' => ['table' => 'socios', 'key' => 'numero_socio', 'label' => self::SOCIO_LABEL, 'solo_habilitados' => true],
                    ],
                    'tipo' => ['label' => 'Tipo', 'type' => 'text', 'required' => true, 'default' => 'velero', 'list' => true],
                    'modelo' => ['label' => 'Modelo', 'type' => 'text', 'list' => true],
                    'tipo_embarcacion_id' => ['label' => 'Tipo de embarcacion', 'type' => 'relation', 'list' => true, 'relation' => ['table' => 'tipos_embarcacion', 'label' => "nombre || ' (' || COALESCE(eslora_m::text, '?') || ' m)'"]],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'matricula' => ['label' => 'Matricula', 'type' => 'text', 'list' => true],
                    'eslora_m' => ['label' => 'Eslora (m)', 'type' => 'decimal', 'list' => true],
                    'manga_m' => ['label' => 'Manga (m)', 'type' => 'decimal', 'list' => true],
                    'm2_matricula' => ['label' => 'M2 x matricula', 'type' => 'decimal', 'list' => true],
                    'metros_comprados' => ['label' => 'Mts comprados', 'type' => 'decimal', 'list' => true],
                    'paga_expensas_m2' => ['label' => 'Paga expensas (m2)', 'type' => 'decimal', 'list' => true],
                    'ubicacion_id' => ['label' => 'Ubicacion', 'type' => 'relation', 'list' => true, 'relation' => ['table' => 'ubicaciones', 'label' => "ambito || ' · ' || nombre", 'active_only' => true]],
                    'observaciones' => ['label' => 'Observaciones', 'type' => 'textarea'],
                    'eslora_medida_m' => ['label' => 'Eslora medida (m)', 'type' => 'decimal'],
                    'manga_medida_m' => ['label' => 'Manga medida (m)', 'type' => 'decimal'],
                    'm2_medidos' => ['label' => 'M2 medidos', 'type' => 'decimal'],
                    'estado' => ['label' => 'Estado operativo', 'type' => 'choice', 'required' => true, 'default' => 'activa', 'list' => true, 'choices' => [
                        'activa' => 'Activa',
                        'mantenimiento' => 'Mantenimiento',
                        'inactiva' => 'Inactiva',
                    ]],
                    'estado_padron_id' => ['label' => 'Estado padron', 'type' => 'relation', 'list' => true, 'relation' => ['table' => 'estados_padron', 'label' => 'nombre', 'active_only' => true]],
                    'es_cnb' => ['label' => 'Propiedad CNB', 'type' => 'boolean', 'default' => false],
                    'calado_m' => ['label' => 'Calado (m)', 'type' => 'decimal'],
                ],
            ],
            'tipos-embarcacion' => [
                'table' => 'tipos_embarcacion',
                'label' => 'Tipos de embarcacion',
                'singular' => 'Tipo de embarcacion',
                'description' => 'Ficha de astillero o medidas tomadas del padron. Sirve para amarras y para cada embarcacion.',
                'fields' => [
                    'codigo' => ['label' => 'Codigo', 'type' => 'text', 'required' => true, 'list' => true],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'eslora_m' => ['label' => 'Eslora (m)', 'type' => 'decimal', 'list' => true],
                    'eslora_max_m' => ['label' => 'Eslora maxima (m)', 'type' => 'decimal', 'list' => true],
                    'manga_m' => ['label' => 'Manga (m)', 'type' => 'decimal', 'list' => true],
                    'puntal_m' => ['label' => 'Puntal (m)', 'type' => 'decimal'],
                    'calado_m' => ['label' => 'Calado (m)', 'type' => 'decimal', 'list' => true],
                    'calado_max_m' => ['label' => 'Calado maximo (m)', 'type' => 'decimal'],
                    'fuente' => ['label' => 'Fuente', 'type' => 'choice', 'required' => true, 'default' => 'astillero', 'list' => true, 'choices' => [
                        'astillero' => 'Astillero',
                        'padron' => 'Padron',
                    ]],
                ],
            ],
            'estados-padron' => [
                'table' => 'estados_padron',
                'label' => 'Estados padron',
                'singular' => 'Estado padron',
                'description' => 'Estados del padron de embarcaciones (Revisado OK, Firmado, etc.).',
                'fields' => [
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'activo' => ['label' => 'Activo', 'type' => 'boolean', 'default' => true, 'list' => true],
                ],
            ],
            'ubicaciones' => [
                'table' => 'ubicaciones',
                'label' => 'Ubicaciones',
                'singular' => 'Ubicacion',
                'description' => 'Ubicaciones de amarre (Agua) y sector tierra.',
                'fields' => [
                    'ambito' => ['label' => 'Ambito', 'type' => 'choice', 'required' => true, 'default' => 'agua', 'list' => true, 'choices' => [
                        'agua' => 'Agua',
                        'tierra' => 'Tierra',
                    ]],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'list' => true],
                    'activo' => ['label' => 'Activo', 'type' => 'boolean', 'default' => true, 'list' => true],
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
                'description' => 'El socio pide (pendiente). El operador confirma. El marinero marca la entrada y la vuelta a la amarra, que libera el lugar. Cupo de 15 dias por año.',
                'fields' => [
                    'numero_socio' => ['label' => 'Socio', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'socios', 'key' => 'numero_socio', 'label' => self::SOCIO_LABEL, 'solo_habilitados' => true]],
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
                    'numero_socio' => ['label' => 'Socio', 'type' => 'relation', 'relation' => ['table' => 'socios', 'key' => 'numero_socio', 'label' => self::SOCIO_LABEL]],
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
                'description' => 'Relacion entre tareas y el personal del club.',
                'fields' => [
                    'tarea_id' => ['label' => 'Tarea', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'tareas', 'label' => "titulo"]],
                    'marinero_id' => ['label' => 'Empleado', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'personal_cnb', 'label' => "apellido || ', ' || nombre || ' (' || rol || ')'"]],
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
                    'marinero_id' => ['label' => 'Empleado', 'type' => 'relation', 'relation' => ['table' => 'personal_cnb', 'label' => "apellido || ', ' || nombre || ' (' || rol || ')'"]],
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
                    'numero_socio' => ['label' => 'Socio', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'socios', 'key' => 'numero_socio', 'label' => self::SOCIO_LABEL]],
                    'email' => ['label' => 'Email login', 'type' => 'email', 'required' => true, 'list' => true],
                    'password_hash' => ['label' => 'Hash clave', 'type' => 'text', 'required' => true],
                    'biometric_habilitado' => ['label' => 'Biometria', 'type' => 'boolean', 'default' => false, 'list' => true],
                ],
            ],
            'comandos-voz' => [
                'table' => 'comandos_voz',
                'label' => 'Comandos de voz',
                'singular' => 'Comando de voz',
                'description' => 'Mnemonicos que la app ejecuta al reconocer una frase.',
                'fields' => [
                    'mnemonico' => ['label' => 'Mnemonico', 'type' => 'text', 'required' => true, 'list' => true],
                    'descripcion' => ['label' => 'Descripcion', 'type' => 'text', 'required' => true, 'list' => true],
                    'activo' => ['label' => 'Activo', 'type' => 'boolean', 'default' => true, 'list' => true],
                ],
            ],
            'frases-comando-voz' => [
                'table' => 'frases_comando_voz',
                'label' => 'Frases de voz',
                'singular' => 'Frase de voz',
                'description' => 'Formas de decir un comando. {nombre} y {socio} son huecos.',
                'fields' => [
                    'comando_id' => ['label' => 'Comando', 'type' => 'relation', 'required' => true, 'list' => true, 'relation' => ['table' => 'comandos_voz', 'label' => 'mnemonico']],
                    'frase' => ['label' => 'Frase', 'type' => 'text', 'required' => true, 'list' => true],
                    'activo' => ['label' => 'Activo', 'type' => 'boolean', 'default' => true, 'list' => true],
                ],
            ],
            'mediciones-nivel' => [
                'table' => 'mediciones_nivel',
                'label' => 'Nivel del lago',
                'singular' => 'Nivel del lago',
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
     */
    public function primaryKey(array $definition): string
    {
        return (string) ($definition['primary_key'] ?? 'id');
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    public function listableColumns(array $definition): array
    {
        $pk = $this->primaryKey($definition);
        $columns = array_keys($this->listFields($definition));

        if ($pk === 'id' || ($definition['list_id'] ?? true)) {
            return array_values(array_unique([$pk, ...$columns]));
        }

        return $columns;
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
        $pk = $this->primaryKey($definition);
        $sort = in_array($sort, $columns, true) ? $sort : $pk;
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        $query = $connection->createQueryBuilder()
            ->select('t.*')
            ->from($definition['table'], 't')
            ->orderBy('t.'.$sort, $direction)
            ->setMaxResults(500);

        $this->applyListScope($query, $definition, $filters);

        foreach ($filters as $column => $value) {
            $value = trim($value);
            if ($value === '' || $value === '__operativos' || $value === '__todos' || !in_array($column, $columns, true)) {
                continue;
            }

            $field = $definition['fields'][$column] ?? null;
            $parameter = 'filter_'.$column;
            $type = is_array($field) ? (string) ($field['type'] ?? 'text') : 'text';

            if ($type === 'choice' && isset($field['choices'][$value])) {
                $query->andWhere(sprintf('t.%s = :%s', $column, $parameter))
                    ->setParameter($parameter, $value);
                continue;
            }

            if ($type === 'boolean') {
                $query->andWhere(sprintf('t.%s = :%s', $column, $parameter))
                    ->setParameter($parameter, in_array(strtolower($value), ['1', 'si', 'sí', 'true'], true) ? 'true' : 'false');
                continue;
            }

            if ($type === 'relation' && is_array($field)) {
                $this->applyRelationFilter($query, $column, $field, $parameter, $value);
                continue;
            }

            if ($type === 'image') {
                continue;
            }

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
     * @param array<string, string> $filters
     */
    private function applyListScope(\Doctrine\DBAL\Query\QueryBuilder $query, array $definition, array $filters): void
    {
        $scope = $definition['list_scope'] ?? null;
        if (!is_array($scope) || !isset($scope['exclude']) || !is_array($scope['exclude'])) {
            return;
        }

        if (isset($scope['column'])) {
            $column = (string) $scope['column'];
            $chosen = $filters[$column] ?? '';
            if ($chosen === '__todos' || ($chosen !== '' && $chosen !== '__operativos')) {
                return;
            }
            $query->andWhere(sprintf('t.%s NOT IN (:scope_exclude)', $column))
                ->setParameter('scope_exclude', array_values($scope['exclude']), ArrayParameterType::STRING);

            return;
        }

        $via = $scope['via'] ?? null;
        if (!is_array($via)) {
            return;
        }

        $chosen = $filters['socio_estado'] ?? '';
        if ($chosen === '__todos') {
            return;
        }
        if ($chosen !== '' && $chosen !== '__operativos') {
            $query->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s s WHERE s.%s = t.%s AND s.%s = :socio_estado_exacto)',
                $via['table'],
                $via['key'],
                $via['column'],
                $via['estado']
            ))->setParameter('socio_estado_exacto', $chosen);

            return;
        }

        $query->andWhere(sprintf(
            'EXISTS (SELECT 1 FROM %s s WHERE s.%s = t.%s AND s.%s NOT IN (:scope_exclude))',
            $via['table'],
            $via['key'],
            $via['column'],
            $via['estado']
        ))->setParameter('scope_exclude', array_values($scope['exclude']), ArrayParameterType::STRING);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function applyRelationFilter(
        \Doctrine\DBAL\Query\QueryBuilder $query,
        string $column,
        array $field,
        string $parameter,
        string $value,
    ): void {
        $relation = $field['relation'];
        $key = $relation['key'] ?? 'id';
        $query->andWhere(sprintf(
            'EXISTS (SELECT 1 FROM %s rel WHERE rel.%s = t.%s AND (CAST(rel.%s AS TEXT) ILIKE :%s OR CAST((%s) AS TEXT) ILIKE :%s_label))',
            $relation['table'],
            $key,
            $column,
            $key,
            $parameter,
            $relation['label'],
            $parameter
        ))
            ->setParameter($parameter, '%'.$this->escapeLike($value).'%')
            ->setParameter($parameter.'_label', '%'.$this->escapeLike($value).'%');
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
            if (($field['primary'] ?? false) && $patch) {
                continue;
            }

            if ($field['type'] === 'boolean' && $form && !$patch) {
                $payload[$column] = array_key_exists($column, $source);
                continue;
            }

            if ($field['type'] === 'image') {
                if (!array_key_exists($column, $source)) {
                    continue;
                }
                $raw = trim((string) $source[$column]);
                // En formularios web, vacío = quitar foto (también en edición).
                $payload[$column] = $raw === '' ? null : $raw;
                continue;
            }

            if (!array_key_exists($column, $source)) {
                if (!$patch && array_key_exists('default', $field)) {
                    $payload[$column] = $field['default'];
                }
                continue;
            }

            $value = $this->coerceValue($source[$column], $field);
            if (($value === null || $value === '') && array_key_exists('default', $field)) {
                $value = $field['default'];
            }
            $payload[$column] = $value;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, array{id: mixed, label: string}>
     */
    /**
     * @param array<string, mixed> $field
     * @param list<mixed> $ids
     * @return array<string, string>
     */
    private function relationLabels(Connection $connection, array $field, array $ids): array
    {
        $relation = $field['relation'];
        $key = $relation['key'] ?? 'id';
        $ids = array_values(array_unique(array_map(static fn (mixed $id): string => (string) $id, $ids)));
        if ($ids === []) {
            return [];
        }

        $numeric = array_reduce($ids, static fn (bool $ok, string $id): bool => $ok && ctype_digit($id), true);
        $sql = sprintf(
            'SELECT %s AS id, %s AS label FROM %s WHERE %s IN (?)',
            $key,
            $relation['label'],
            $relation['table'],
            $key
        );
        $rows = $connection->fetchAllAssociative(
            $sql,
            [$numeric ? array_map('intval', $ids) : $ids],
            [$numeric ? ArrayParameterType::INTEGER : ArrayParameterType::STRING]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['id']] = (string) ($row['label'] ?? '');
        }

        return $map;
    }

    public function relationOptions(Connection $connection, array $field): array
    {
        if (($field['type'] ?? null) !== 'relation') {
            return [];
        }

        $relation = $field['relation'];
        $key = $relation['key'] ?? 'id';
        $where = [];
        if (!empty($relation['active_only'])) {
            $where[] = 'activo = TRUE';
        }
        if (!empty($relation['solo_habilitados'])) {
            $where[] = 'estado IN ('.SocioElegibilidad::sqlEstados().')';
        }
        $sql = sprintf(
            'SELECT %s AS id, %s AS label FROM %s%s ORDER BY label ASC LIMIT %d',
            $key,
            $relation['label'],
            $relation['table'],
            $where === [] ? '' : ' WHERE '.implode(' AND ', $where),
            !empty($relation['solo_habilitados']) ? 2000 : 500
        );

        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'label' => (string) $row['label']],
            $connection->fetchAllAssociative($sql)
        );
    }

    /**
     * Agrega {columna}_label para campos relation del listado admin.
     *
     * @param array<string, mixed> $definition
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function withRelationLabels(Connection $connection, array $definition, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        foreach ($this->listFields($definition) as $column => $field) {
            if (($field['type'] ?? null) !== 'relation') {
                continue;
            }

            $ids = [];
            foreach ($rows as $row) {
                $raw = $row[$column] ?? null;
                if ($raw !== null && $raw !== '') {
                    $ids[] = $raw;
                }
            }
            $map = $this->relationLabels($connection, $field, $ids);

            foreach ($rows as &$row) {
                $raw = $row[$column] ?? null;
                $row[$column.'_label'] = $raw === null || $raw === ''
                    ? ''
                    : ($map[(string) $raw] ?? ('#'.$raw));
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * Listado API de embarcaciones con labels de ubicacion y estado padron.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchEmbarcacionesApi(Connection $connection, ?int $numeroSocio = null, ?string $ambito = null, int $limit = 500): array
    {
        $sql = "SELECT e.*,
                       u.nombre AS ubicacion,
                       u.ambito AS ubicacion_ambito,
                       ep.nombre AS estado_padron,
                       s.nombre AS socio_nombre,
                       s.apellido AS socio_apellido
                FROM cnb_app.embarcaciones e
                LEFT JOIN cnb_app.ubicaciones u ON u.id = e.ubicacion_id
                LEFT JOIN cnb_app.estados_padron ep ON ep.id = e.estado_padron_id
                LEFT JOIN cnb_app.socios s ON s.numero_socio = e.numero_socio
                WHERE 1=1";
        $params = [];

        if ($numeroSocio !== null) {
            $sql .= ' AND (
                e.numero_socio = ?
                OR EXISTS (
                    SELECT 1 FROM cnb_app.socio_embarcacion se
                    WHERE se.embarcacion_id = e.id AND se.numero_socio = ?
                )
            )';
            $params[] = $numeroSocio;
            $params[] = $numeroSocio;
        }
        if ($ambito !== null && $ambito !== '') {
            $sql .= ' AND e.ambito = ?';
            $params[] = $ambito;
        }

        $sql .= ' ORDER BY e.id DESC LIMIT '.$limit;

        return array_map($this->normalizeRow(...), $connection->fetchAllAssociative($sql, $params));
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
            'integer' => $value === null ? null : (int) $value,
            'relation' => $this->coerceRelationValue($value, $field),
            'decimal' => $value === null ? null : (string) $value,
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private function coerceRelationValue(mixed $value, array $field): mixed
    {
        if ($value === null) {
            return null;
        }

        $key = $field['relation']['key'] ?? 'id';
        if ($key === 'id' || $key === 'numero_socio') {
            return (int) $value;
        }

        return (string) $value;
    }
}
