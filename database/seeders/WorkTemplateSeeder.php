<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\WorkTemplate;
use Illuminate\Database\Seeder;

class WorkTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            // Seed some templates for each company
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'ING-01'],
                [
                    'category' => 'ENTRY',
                    'title' => 'Ingreso de Compra Directa',
                    'description' => 'Registro básico de ingreso con datos de proveedor y pesaje inicial.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'lote',
                                'label' => 'Nombre del Lote Propio',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => "Nombre del lote interno propio donde se ingresarán los animales. Ej: LOTE 104, RECRIA 2. Puede estar en blanco si se usa lote del proveedor.",
                            ],
                            [
                                'name' => 'activity',
                                'label' => 'Actividad del Lote (Destino)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => "Actividad productiva del lote propio de destino (ej: 'CRIA', 'RECRIA', 'INVERNADA', 'Cría', 'Recría', 'Invernada'). Extraer el nombre o código tal como figure en la cabecera.",
                            ],
                            [
                                'name' => 'provider_name',
                                'label' => 'Nombre del Proveedor / Vendedor',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre o razón social del vendedor/proveedor de la tropa.',
                            ],
                            [
                                'name' => 'provider_cuit',
                                'label' => 'CUIT del Proveedor / Vendedor',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Formato de 11 dígitos con o sin guiones. Ej: 30-12345678-9',
                            ],
                            [
                                'name' => 'provider_farm_name',
                                'label' => 'Establecimiento de Origen (Campo Vendedor)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre del campo, finca o establecimiento de origen del proveedor. Ej: Establecimiento Norte, La Porteña',
                            ],
                            [
                                'name' => 'provider_renspa',
                                'label' => 'RENSPA de Origen',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'RENSPA del establecimiento de origen del proveedor. Formato XX.XXX.X.XXXXX/XX',
                            ],
                            [
                                'name' => 'provider_batch_name',
                                'label' => 'Lote de Origen (Proveedor)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre o código de lote/tropa externa de origen asignada por el proveedor. Ej: TROPA-492',
                            ],
                            [
                                'name' => 'guia_dte',
                                'label' => 'N° de DTE / Guía de Traslado',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Número de documento de tránsito electrónico o remito',
                            ],
                            [
                                'name' => 'entry_date',
                                'label' => 'Fecha de Ingreso',
                                'type' => 'date',
                                'required' => true,
                                'default' => 'today',
                                'ai_hint' => 'Fecha en el encabezado (DD/MM/AAAA o AAAA-MM-DD)',
                            ],
                        ],
                        'table_columns' => [
                            [
                                'name' => 'caravana',
                                'label' => 'Caravana / Tag',
                                'type' => 'string',
                                'required' => true,
                                'validation' => [
                                    'rules' => ['required', 'string', 'max:30'],
                                ],
                                'ai_hint' => 'Número visible o código de caravana/botón. Ej: 1024, 058, AR-492',
                            ],
                            [
                                'name' => 'category',
                                'label' => 'Categoría / Subcategoría',
                                'type' => 'string',
                                'required' => true,
                                'validation' => [
                                    'rules' => ['required', 'string'],
                                ],
                                'ai_hint' => "Texto manuscrito completo de categoría (ej: 'VACA', 'TORO', 'NOVILLO', 'VAQUILLONA REPOSICION', 'VACA CUT', 'TERNERA'). Extraer el texto completo tal como está escrito.",
                            ],
                            [
                                'name' => 'sex',
                                'label' => 'Sexo',
                                'type' => 'string',
                                'required' => false,
                                'options' => [
                                    ['value' => 'M', 'label' => 'Macho'],
                                    ['value' => 'H', 'label' => 'Hembra'],
                                ],
                                'validation' => [
                                    'rules' => ['nullable', 'in:M,H'],
                                ],
                                'ai_hint' => 'M = Macho, H = Hembra. Si falta o está vacío, se inferirá automáticamente de la categoría.',
                            ],
                            [
                                'name' => 'breed',
                                'label' => 'Raza / Pelaje',
                                'type' => 'string',
                                'required' => false,
                                'validation' => [
                                    'rules' => ['nullable', 'string'],
                                ],
                                'ai_hint' => 'Angus Negro, Angus Colorado, Hereford, Brangus Colorado, Braford, Cruza Careta, Holando Overo, etc. Extraer el texto completo de la celda única.',
                            ],
                            [
                                'name' => 'teeth',
                                'label' => 'Dentición',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'DL (0), 2D (2), 4D (4), 6D (6), 8D (8), Diente de Leche, Boca Llena, Media Boca. Extraer texto tal como está escrito.',
                            ],
                            [
                                'name' => 'entry_weight',
                                'label' => 'Peso Ingreso (Kg)',
                                'type' => 'number',
                                'required' => false,
                                'validation' => [
                                    'rules' => ['nullable', 'numeric', 'min:30', 'max:1500'],
                                ],
                                'ai_hint' => 'Peso individual de balanza en kilogramos',
                            ],
                            [
                                'name' => 'observations',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'validation' => [
                                    'rules' => ['nullable', 'string', 'max:500'],
                                ],
                                'ai_hint' => 'Defectos físicos, marcas líquidas o notas sanitarias',
                            ],
                        ],
                    ],
                ]
            );

            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'OP-01'],
                [
                    'category' => 'WEIGHT',
                    'title' => 'Control Mensual de Lotes',
                    'description' => 'Planilla para el pesaje de rutina mensual de tropas en recría.',
                    'status' => 'active'
                ]
            );

            // OP-02 was a placeholder: category and title only, with no schema, no
            // processing channel and no printable component. CACT-01 supersedes it and
            // covers far more, so it is archived instead of left listed and inert.
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'OP-02'],
                [
                    'category' => 'ACTIVITY',
                    'title' => 'Transferencia a Invernada',
                    'description' => 'Reemplazada por CACT-01 (Cambio de Actividad de Hacienda).',
                    'status' => 'archived'
                ]
            );

            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'MON-01'],
                [
                    'category' => 'REPRODUCTIVE',
                    'title' => 'Servicio de Monta y Entore a Campo',
                    'description' => 'Planilla oficial de asignación y control zootécnico de vientres en entore con padrillos.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'lote',
                                'label' => 'Lote de Servicio (Destino)',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Nombre o código del lote de entore/reproducción.'
                            ],
                            [
                                'name' => 'service_type',
                                'label' => 'Modalidad de Entore',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Colectivo, Rotación o Individual.'
                            ],
                            [
                                'name' => 'planned_start_date',
                                'label' => 'Fecha Inicio Planificada',
                                'type' => 'date',
                                'required' => false,
                                'ai_hint' => 'Fecha programada de inicio del entore.'
                            ]
                        ],
                        'table_columns' => [
                            [
                                'name' => 'caravana',
                                'label' => 'Caravana Vientre',
                                'type' => 'string',
                                'required' => true,
                                'validation' => [
                                    'rules' => ['required', 'string', 'max:30']
                                ],
                                'ai_hint' => 'Número visible o código de caravana de la hembra.'
                            ],
                            [
                                'name' => 'category',
                                'label' => 'Categoría',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Vaca o Vaquillona.'
                            ],
                            [
                                'name' => 'body_condition',
                                'label' => 'Condición Corporal (1-5)',
                                'type' => 'number',
                                'required' => false,
                                'validation' => [
                                    'rules' => ['nullable', 'numeric', 'min:1', 'max:5']
                                ],
                                'ai_hint' => 'Puntuación zootécnica de condición corporal.'
                            ],
                            [
                                'name' => 'sire_caravan',
                                'label' => 'Toro Asignado / Detectado',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Caravana del toro que realizó el servicio o monta.'
                            ],
                            [
                                'name' => 'service_date',
                                'label' => 'Fecha de Monta',
                                'type' => 'date',
                                'required' => false,
                                'ai_hint' => 'Fecha observada de monta o servicio.'
                            ],
                            [
                                'name' => 'observations',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas zootécnicas o comportamiento.'
                            ]
                        ]
                    ]
                ]
            );

            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'REP-01'],
                [
                    'category' => 'REPRODUCTIVE',
                    'title' => 'Planilla de Tacto y Ecografía',
                    'description' => 'Registro de diagnóstico de gestación, tacto rectal y ecografía.',
                    'status' => 'active',
                    'schema_definition' => [
                        [
                            'name' => 'caravana',
                            'label' => 'Caravana',
                            'type' => 'string',
                            'required' => true,
                            'validation' => [
                                'rules' => ['required', 'alpha_num']
                            ]
                        ],
                        [
                            'name' => 'category',
                            'label' => 'Categoría',
                            'type' => 'string',
                            'required' => true,
                            'validation' => [
                                'rules' => ['required']
                            ]
                        ],
                        [
                            'name' => 'diagnosis',
                            'label' => 'Diagnóstico',
                            'type' => 'select',
                            'required' => true,
                            'options' => [
                                ['value' => 'PREGNANT', 'label' => 'Preñada'],
                                ['value' => 'EMPTY', 'label' => 'Vacía']
                            ],
                            'validation' => [
                                'rules' => ['required']
                            ]
                        ],
                        [
                            'name' => 'gestational_stage',
                            'label' => 'Estadio Estimado',
                            'type' => 'select',
                            'required' => false,
                            'options' => [
                                ['value' => 'CABEZA', 'label' => 'Cabeza'],
                                ['value' => 'CUERPO', 'label' => 'Cuerpo'],
                                ['value' => 'COLA', 'label' => 'Cola']
                            ],
                            'validation' => [
                                'rules' => ['nullable']
                            ]
                        ],
                        [
                            'name' => 'observations',
                            'label' => 'Observaciones',
                            'type' => 'text',
                            'required' => false
                        ]
                    ]
                ]
            );

            // Seed TOR-01: Planilla Oficial de Revisación Andrológica y Sanitaria en Manga
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'TOR-01'],
                [
                    'category' => 'REPRODUCTIVE',
                    'title' => 'Revisación Andrológica y Muestreo en Manga',
                    'description' => 'Evaluación andrológica en corral (CE, CC, aplomos) y doble muestreo de raspaje prepucial (ETS) y serología de sangre.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'farm_name',
                                'label' => 'Establecimiento / Campo',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre del campo o finca donde se realiza la manga. Ej: Establecimiento El Ombú',
                            ],
                            [
                                'name' => 'renspa',
                                'label' => 'RENSPA',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'RENSPA del establecimiento del rodeo de toros.',
                            ],
                            [
                                'name' => 'evaluation_date',
                                'label' => 'Fecha de Manga',
                                'type' => 'date',
                                'required' => true,
                                'default' => 'today',
                                'ai_hint' => 'Fecha en que se pasó la torada por manga (DD/MM/AAAA)',
                            ],
                            [
                                'name' => 'veterinarian_name',
                                'label' => 'Médico Veterinario Actuante',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre del profesional veterinario que firma la planilla.',
                            ],
                            [
                                'name' => 'veterinarian_license',
                                'label' => 'Matrícula Profesional (MP)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Número de matrícula profesional o registro colegiado.',
                            ],
                            [
                                'name' => 'sample_round',
                                'label' => 'Ronda de Raspaje',
                                'type' => 'number',
                                'required' => false,
                                'default' => 1,
                                'ai_hint' => 'Número de raspaje prepucial seriado (1 o 2).',
                            ],
                        ],
                        'table_columns' => [
                            [
                                'name' => 'caravana',
                                'label' => 'Caravana / Toro',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Identificador o número de caravana visible del toro (ej: TR-001, 482, 105).',
                            ],
                            [
                                'name' => 'ce_cm',
                                'label' => 'Circunferencia Escrotal (cm)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Medida en centímetros con cinta métrica (ej: 34.5, 36.0, 38). Umbral mínimo Carrillo: 28.0 cm.',
                            ],
                            [
                                'name' => 'bcs',
                                'label' => 'Condición Corporal (1 a 5)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Puntaje de CC escala 1 a 5 (ej: 2.5, 3.0, 3.5, 4.0). Óptimo servicio: 3.0 a 3.5.',
                            ],
                            [
                                'name' => 'libido',
                                'label' => 'Líbido',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'BAJA, MEDIA, ALTA, MUY_ALTA (o marcas B, M, A, MA).',
                            ],
                            [
                                'name' => 'aplomos',
                                'label' => 'Aplomos & Locomoción',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Correctos, lesión podal, tarso, garrón recto, infosura, etc.',
                            ],
                            [
                                'name' => 'scrape_collected',
                                'label' => 'Raspaje ETS Tomado',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Indica si se extrajo raspaje prepucial (SI / NO / X / [X]).',
                            ],
                            [
                                'name' => 'scrape_tube',
                                'label' => 'N° Tubo Raspaje',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Identificador del tubo de raspaje prepucial (ej: R-01, 1, Tubo 1).',
                            ],
                            [
                                'name' => 'serology_collected',
                                'label' => 'Serología Sangre Tomada',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Indica si se extrajo muestra de sangre para serología (SI / NO / X / [X]).',
                            ],
                            [
                                'name' => 'serology_tube',
                                'label' => 'N° Tubo Serología',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Identificador del tubo de sangre vacutainer (ej: S-01, 1, Tubo S1).',
                            ],
                            [
                                'name' => 'physical_verdict',
                                'label' => 'Dictamen Físico en Manga',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'APTO, RECHAZO, EN TRATAMIENTO según examen andrológico y locomotor.',
                            ],
                            [
                                'name' => 'observations',
                                'label' => 'Observaciones Clínicas',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Cualquier nota adicional, asimetría testicular, prepucio o tratamiento.',
                            ],
                        ],
                    ],
                ]
            );

            // Seed LSER-01: Conformación de Lote de Servicio de toro único (genera lote, orden y movimientos)
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'LSER-01'],
                [
                    'category' => 'REPRODUCTIVE',
                    'title' => 'Conformación de Lote de Servicio — Toro Único',
                    'description' => 'Constitución de un lote de servicio antes del entore: el toro en el encabezado y los vientres en la tabla. No es registro de montas (MON-01). Al cargarse genera el lote, la orden de servicio y los movimientos.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'lote',
                                'label' => 'Nombre del Lote de Servicio',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Nombre del lote de servicio escrito en el encabezado. Ej: Entore Vaquillonas Toro 004',
                            ],
                            [
                                'name' => 'toro_caravana',
                                'label' => 'Caravana del Toro',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Caravana del único toro del lote, en el recuadro destacado del encabezado. No es un vientre de la tabla.',
                            ],
                            [
                                'name' => 'planned_start_date',
                                'label' => 'Fecha Inicio de Servicio',
                                'type' => 'date',
                                'required' => true,
                                'default' => 'today',
                                'ai_hint' => 'Fecha de inicio del servicio y del ingreso de los animales al lote (DD/MM/AAAA).',
                            ],
                            [
                                'name' => 'planned_end_date',
                                'label' => 'Fecha Fin de Servicio',
                                'type' => 'date',
                                'required' => false,
                                'ai_hint' => 'Fecha prevista de retiro del toro (DD/MM/AAAA). Puede estar vacía.',
                            ],
                            [
                                'name' => 'responsable',
                                'label' => 'Responsable / Firma',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre de quien arma el lote en la manga.',
                            ],
                            [
                                'name' => 'observaciones',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas generales del lote.',
                            ],
                        ],
                        'table_columns' => [
                            [
                                'name' => 'caravana',
                                'label' => 'Caravana del Vientre',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Número de caravana del vientre escrito a mano, una por fila. Ignorar filas vacías.',
                            ],
                            [
                                'name' => 'observations',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas de la manga sobre ese vientre.',
                            ],
                        ],
                    ],
                ]
            );

            // Seed DEST-01: Destete de crías al pie. Cumple una orden de destete (su código va en el
            // encabezado) o, impresa en blanco, genera la orden al confirmarse. Destino único (el
            // lote del encabezado) o por animal (columna Lote destino), y C/S nueva por cría.
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'DEST-01'],
                [
                    'category' => 'WEANING',
                    'title' => 'Destete y Conformación de Lote de Destete',
                    'description' => 'Desmadre de crías al pie. Una fila por cría, con peso de destete opcional. Cumple la orden de destete impresa en el encabezado. Destina las crías al lote de destete del encabezado o al de cada fila, existente o nuevo, puede cambiar su categoría y registra el movimiento de cada ternero.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'orden_destete',
                                'label' => 'Orden de Destete',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Código de la orden de destete, formato DS-AAAAMMDD-NNNN, impreso en el recuadro ORDEN DE DESTETE del encabezado. No confundir con DEST-01, que es el código de la planilla (recuadro TEMPLATE CODE). Si el recuadro está vacío, devolver vacío: la planilla se llenó sin orden.',
                            ],
                            [
                                'name' => 'lote_destete',
                                'label' => 'Lote de Destete (todas las crías)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre del lote de destete al que van TODAS las crías, impreso o escrito a mano en el recuadro destacado del encabezado. Ej: Destete Marzo 2026. Si el recuadro dice "— por animal —", la planilla usa la columna de destino por fila: devolver vacío.',
                            ],
                            [
                                'name' => 'sistema_manejo',
                                'label' => 'Sistema de Manejo del Lote de Destete',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Casillero marcado: CORRAL (encierre) o PASTURA (a campo), del lote de destete del encabezado. Puede estar vacío.',
                            ],
                            [
                                'name' => 'fecha_destete',
                                'label' => 'Fecha de Destete',
                                'type' => 'date',
                                'required' => true,
                                'default' => 'today',
                                'ai_hint' => 'Fecha en que se desmadran las crías (DD/MM/AAAA).',
                            ],
                            [
                                'name' => 'tipo_destete',
                                'label' => 'Tipo de Destete',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Casillas marcadas entre TRADICIONAL, ANTICIPADO y PRECOZ. Si hay más de una marcada, devolver TODAS separadas por coma (ej.: "ANTICIPADO, PRECOZ"); nunca elegir una. Vacío si no hay ninguna marcada.',
                            ],
                            [
                                'name' => 'lote_origen',
                                'label' => 'Lote de Cría (Origen)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre del lote de vacas con cría al pie de donde salen los terneros. Con crías de varios lotes dice "Varios". Puede estar vacío.',
                            ],
                            [
                                'name' => 'responsable',
                                'label' => 'Responsable / Firma',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre de quien hace el destete en la manga.',
                            ],
                            [
                                'name' => 'hoja_numero',
                                'label' => 'Hoja N°',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Número de hoja del recuadro "Hoja N de M" (el N).',
                            ],
                            [
                                'name' => 'hoja_total',
                                'label' => 'De (total de hojas)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Total de hojas del recuadro "Hoja N de M" (el M).',
                            ],
                            [
                                'name' => 'observaciones',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas generales del destete.',
                            ],
                        ],
                        'table_columns' => [
                            [
                                'name' => 'caravana',
                                'label' => 'Caravana de la Cría',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Caravana del ternero, impresa o escrita a mano. Ignorar filas vacías.',
                            ],
                            [
                                'name' => 'caravana_madre',
                                'label' => 'Caravana de la Madre',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Caravana de la vaca. Puede estar impresa o vacía.',
                            ],
                            [
                                'name' => 'categoria',
                                'label' => 'C/S actual',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Categoría ACTUAL de la cría, impresa en gris por el sistema. Ej: Ternero. Sólo sirve para encontrar la cría.',
                            ],
                            [
                                'name' => 'cs_nueva',
                                'label' => 'C/S nueva',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Columna "C/S nueva": categoría o subcategoría NUEVA de la cría, escrita a mano o impresa. Puede traer sólo la categoría (Novillito), sólo la subcategoría (Reposición) o ambas separadas por barra (Vaquillona / Reposición). Si está vacía o tiene un guion, devolver vacío.',
                            ],
                            [
                                'name' => 'peso',
                                'label' => 'Peso Destete (kg)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Peso de balanza en kg, escrito a mano. Puede estar vacío. Ej: 172, 185.5',
                            ],
                            [
                                'name' => 'lote_destino',
                                'label' => 'Lote de Destete (por cría)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Lote de destete de ESTA cría, impreso o escrito a mano. Sólo existe cuando la planilla usa destino por animal. Si está vacío, devolver vacío: NO completar con el encabezado.',
                            ],
                            [
                                'name' => 'manejo',
                                'label' => 'M',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Celda angosta de UNA letra manuscrita, a la derecha del lote de destete de la fila: C = corral, P = pastura. Es el sistema de manejo del LOTE de esta fila. Si está vacía, devolver vacío.',
                            ],
                            [
                                'name' => 'observations',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas de la manga sobre la cría.',
                            ],
                        ],
                    ],
                ]
            );

            // Seed CACT-01: cambio de actividad con pesaje en la manga. A diferencia de
            // la transferencia por pantalla, la planilla registra mediciones del día, y
            // admite un destino único o un destino por animal.
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'CACT-01'],
                [
                    'category' => 'ACTIVITY',
                    'title' => 'Cambio de Actividad de Hacienda',
                    'description' => 'Movimiento de animales de un lote a otro con cambio de actividad productiva. Una fila por cabeza, con el peso de entrada a la nueva actividad y la dentición. Admite un destino único o un destino por animal.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'actividad_origen',
                                'label' => 'Actividad de Origen',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Actividad de la que salen los animales, impresa o escrita. Ej: Cría, Recría, Invernada. Puede estar vacía.',
                            ],
                            [
                                'name' => 'actividad_destino',
                                'label' => 'Actividad de Destino',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Actividad a la que pasan los animales, una sola para toda la planilla y todas sus hojas. Ej: Cría, Recría, Invernada. Todo lote de destino escrito en la planilla pertenece a esta actividad.',
                            ],
                            [
                                'name' => 'lote_origen',
                                'label' => 'Lote de Origen',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Nombre del lote del que salen los animales, en el recuadro destacado del encabezado.',
                            ],
                            [
                                'name' => 'lote_destino',
                                'label' => 'Lote de Destino (todos)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre del lote al que van TODOS los animales. Queda vacío cuando la planilla usa la columna de destino por animal.',
                            ],
                            [
                                'name' => 'fecha_movimiento',
                                'label' => 'Fecha del Movimiento',
                                'type' => 'date',
                                'required' => true,
                                'default' => 'today',
                                'ai_hint' => 'Fecha en que se hace el cambio de actividad y el pesaje (DD/MM/AAAA).',
                            ],
                            [
                                'name' => 'sistema_manejo',
                                'label' => 'Sistema de Manejo',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Casillero marcado: CORRAL (encierre) o PASTURA (a campo). Puede estar vacío.',
                            ],
                            [
                                'name' => 'total_cabezas',
                                'label' => 'Total de Cabezas',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Cantidad total de animales escrita en el recuadro de resumen. Puede estar vacía.',
                            ],
                            [
                                'name' => 'peso_total',
                                'label' => 'Peso Total de la Tropa (kg)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Kilos totales escritos en el recuadro de resumen. Puede estar vacío.',
                            ],
                            [
                                'name' => 'responsable',
                                'label' => 'Responsable / Firma',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre de quien hace el trabajo en la manga.',
                            ],
                            [
                                'name' => 'hoja_numero',
                                'label' => 'Hoja N°',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Número de hoja del recuadro "Hoja N de M" (el N).',
                            ],
                            [
                                'name' => 'hoja_total',
                                'label' => 'De (total de hojas)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Total de hojas del recuadro "Hoja N de M" (el M).',
                            ],
                            [
                                'name' => 'orden_transferencia',
                                'label' => 'Orden de Transferencia',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Código de la orden de transferencia, formato TR-AAAAMMDD-NNNN, impreso en el recuadro del encabezado. Puede estar vacío si la planilla se llenó sin orden.',
                            ],
                            [
                                'name' => 'observaciones',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas generales del movimiento.',
                            ],
                        ],
                        'table_columns' => [
                            [
                                'name' => 'caravana',
                                'label' => 'Caravana',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Número o código de caravana, impreso o manuscrito. Ignorar filas vacías.',
                            ],
                            [
                                'name' => 'peso_actual',
                                'label' => 'Peso Actual (kg)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Peso de balanza del día, en kg. Es el peso con el que el animal entra a la nueva actividad. Puede estar vacío. Ej: 248, 305.5',
                            ],
                            [
                                'name' => 'sexo',
                                'label' => 'Sexo',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Grupo SEXO de ESTA fila: dos subcolumnas con UNA casilla cada una, con encabezado MACHO y HEMBRA. Una casilla cuenta sólo si tiene una X o tilde de tinta encima. Devolver M si está marcada la casilla bajo MACHO, H si está marcada la de HEMBRA, M, H si están marcadas las dos, y vacío si ninguna está marcada. No deducirlo de la caravana ni de otras filas.',
                            ],
                            [
                                'name' => 'categoria',
                                'label' => 'Categoría',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Categoría ACTUAL del animal, impresa en gris por el sistema en formato C/S. Ej: Ternero, Vaquillona / Reposición.',
                            ],
                            [
                                // Only printed when the order says the category changes. The
                                // ai-agent appends the catalog to this hint when it loads the schema.
                                'name' => 'cs_nueva',
                                'label' => 'C/S Nueva',
                                'type' => 'string',
                                'required' => false,
                                'catalog' => 'animal_categories',
                                'ai_hint' => 'Columna "C/S nueva": categoría o subcategoría NUEVA del animal, escrita a mano o impresa. Puede traer sólo la categoría (Novillito), sólo la subcategoría (Reposición) o ambas separadas por barra (Vaquillona / Reposición). Si está vacía o tiene un guion, devolver vacío.',
                            ],
                            [
                                'name' => 'dientes',
                                'label' => 'Dentición',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'DL (0), 2D, 4D, 6D, 8D, Boca Llena. Extraer el texto tal como está. Si la celda está vacía, devolver vacío: no completar con cero.',
                            ],
                            [
                                'name' => 'lote_destino',
                                'label' => 'Lote Destino',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Lote al que va ESTE animal, siempre un lote de la actividad de destino del encabezado. Sólo se completa cuando la planilla usa destino por animal; si está vacío vale el lote del encabezado.',
                            ],
                            [
                                'name' => 'manejo',
                                'label' => 'M',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Celda angosta de UNA letra manuscrita, a la derecha del lote destino: C = corral, P = pastura. Es el sistema de manejo del LOTE DESTINO de esta fila, no del animal. Si está vacía, devolver vacío: no completar.',
                            ],
                            [
                                'name' => 'observations',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas de la manga sobre el animal.',
                            ],
                        ],
                    ],
                ]
            );

            // Seed PAR-01: planilla de parición. Cumple una orden de parición (su código va en el
            // encabezado) en una o varias recorridas, o, impresa en blanco, genera la orden al
            // confirmarse. Sin lote destino: la cría nace en el lote de su madre. No lleva padre
            // ni dientes: el padre se confirma aparte y la cría nace con 0 dientes.
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'PAR-01'],
                [
                    'category' => 'BIRTH',
                    'title' => 'Planilla de Parición',
                    'description' => 'Recorrida de parición. Una fila por vientre preñado, con el resultado marcado (parió, nacido muerto, aborto) y, si parió, la caravana, el sexo, el peso, la raza y la fecha de nacimiento de la cría. Un parto que la orden no listaba se escribe en una fila libre con la casilla «Fuera de orden». Cumple la orden de parición del encabezado; la cría queda en el lote de su madre.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'orden_paricion',
                                'label' => 'Orden de Parición',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Código de la orden de parición, formato PA-AAAAMMDD-NNNN, impreso en el recuadro del encabezado. No confundir con PAR-01, que es el código de la planilla. Puede estar vacío si la planilla se llenó sin orden.',
                            ],
                            [
                                'name' => 'lote',
                                'label' => 'Lote(s)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Lote de los vientres, impreso. Con vientres de varios lotes dice "Varios". Informativo.',
                            ],
                            [
                                'name' => 'fecha_recorrida',
                                'label' => 'Fecha de Recorrida',
                                'type' => 'date',
                                'required' => false,
                                'ai_hint' => 'Fecha de la recorrida (DD/MM/AAAA). Es informativa: NO copiarla a la fecha de nacimiento de las filas.',
                            ],
                            [
                                'name' => 'responsable',
                                'label' => 'Responsable / Firma',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre de quien hizo la recorrida.',
                            ],
                            [
                                'name' => 'hoja_numero',
                                'label' => 'Hoja N°',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Número de hoja del recuadro "Hoja N de M" (el N).',
                            ],
                            [
                                'name' => 'hoja_total',
                                'label' => 'De (total de hojas)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Total de hojas del recuadro "Hoja N de M" (el M).',
                            ],
                            [
                                'name' => 'observaciones',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas generales de la recorrida.',
                            ],
                        ],
                        'table_columns' => [
                            [
                                'name' => 'caravana_madre',
                                'label' => 'Caravana de la Madre',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Caravana del vientre, impresa por el sistema. En las filas en blanco del final puede estar escrita a mano. Ignorar filas completamente vacías.',
                            ],
                            [
                                'name' => 'resultado',
                                'label' => 'Resultado (V/M/A)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Grupo RESULTADO de ESTA fila: tres subcolumnas con UNA casilla cada una, con encabezado PARIÓ, MUERTO y ABORTO (de izquierda a derecha). Una casilla cuenta sólo si tiene una X o tilde de tinta encima. Devolver V si está marcada la casilla bajo PARIÓ, M si está marcada la de MUERTO, A si está marcada la de ABORTO. Si hay dos o más marcadas devolver todas separadas por coma (ej: V, M). Si ninguna de las tres está marcada devolver vacío, aunque la fila tenga caravana de cría, sexo o fecha: NUNCA deducir el resultado de los otros datos.',
                            ],
                            [
                                'name' => 'caravana_cria',
                                'label' => 'Caravana de la Cría',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Caravana de la cría, escrita a mano. Puede estar vacía.',
                            ],
                            [
                                'name' => 'sexo',
                                'label' => 'Sexo (M/H)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Celda angosta SEXO (M/H) de ESTA fila, a la derecha de CARAVANA CRÍA: UNA letra manuscrita, M = macho, H = hembra. Devolver la letra tal como está escrita. Si la celda está vacía, devolver vacío: no deducirlo de la caravana ni de otras filas. Si hay otra cosa escrita o no se entiende, devolver el texto tal cual.',
                            ],
                            [
                                'name' => 'peso',
                                'label' => 'Peso al Nacer (kg)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Peso de la cría en kg, escrito a mano. Puede estar vacío. Ej: 32, 35.5',
                            ],
                            [
                                'name' => 'raza',
                                'label' => 'Raza',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Raza de la cría, escrita a mano (Ej: Angus, Hereford, Brangus). Puede estar vacía.',
                            ],
                            [
                                'name' => 'fecha_nacimiento',
                                'label' => 'Fecha de Nacimiento',
                                'type' => 'date',
                                'required' => false,
                                'ai_hint' => 'Fecha del parto de ESTA fila (DD/MM/AAAA), escrita a mano. Si está vacía, devolver vacío: NO completar con la fecha de recorrida del encabezado.',
                            ],
                            [
                                'name' => 'fuera_de_orden',
                                'label' => 'Fuera de orden',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Última columna, FUERA DE ORDEN: UNA casilla por fila. Devolver X si la casilla de ESTA fila tiene una X o tilde de tinta encima, y vacío si no. No deducirlo de que la caravana de la madre esté escrita a mano.',
                            ],
                        ],
                    ],
                ]
            );

            // Seed ING-02: Orden de Ingreso de Hacienda Externa. Es el documento de la compra: la
            // orden nace sin caravanas (llegan después con el DTE), así que la hoja es sólo
            // encabezado más la tabla de razas. Se imprime desde una orden (completa) o en blanco,
            // para llenarla en el remate y escanearla después.
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'ING-02'],
                [
                    'category' => 'ENTRY',
                    'title' => 'Orden de Ingreso de Hacienda Externa',
                    'description' => 'Documento de la compra de una tropa externa (subasta o compra directa): lote externo, proveedor, establecimiento de origen, cabezas, categoría, sexo, razas, pesos, estado, edad, sabe comer, garrapata y desbaste. Las caravanas se cargan después, con el DTE.',
                    'status' => 'active',
                    'schema_definition' => [
                        'header_fields' => [
                            [
                                'name' => 'orden_ingreso',
                                'label' => 'Orden de Ingreso',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Código de la orden de ingreso, formato EN-AAAAMMDD-NNNN, impreso en el recuadro ORDEN DE INGRESO. No confundir con ING-02, que es el código de la planilla. Vacío en una hoja en blanco.',
                            ],
                            [
                                'name' => 'nombre_lote',
                                'label' => 'Nombre del Lote',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Nombre del lote externo, escrito en el recuadro LOTE EXTERNO (NOMBRE).',
                            ],
                            [
                                'name' => 'proveedor',
                                'label' => 'Proveedor / Vendedor',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Nombre o razón social del vendedor o consignataria.',
                            ],
                            [
                                'name' => 'cuit_proveedor',
                                'label' => 'CUIT del Proveedor',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'CUIT de 11 dígitos, con o sin guiones. Ej: 30-12345678-9.',
                            ],
                            [
                                'name' => 'establecimiento',
                                'label' => 'Establecimiento de Origen',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Nombre del campo o establecimiento de donde sale la hacienda.',
                            ],
                            [
                                'name' => 'renspa_origen',
                                'label' => 'RENSPA de Origen',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'RENSPA del establecimiento de origen. Formato XX.XXX.X.XXXXX/XX.',
                            ],
                            [
                                'name' => 'fecha_compra',
                                'label' => 'Fecha de Compra',
                                'type' => 'date',
                                'required' => true,
                                'ai_hint' => 'Fecha de la compra o del remate (DD/MM/AAAA).',
                            ],
                            [
                                'name' => 'cabezas',
                                'label' => 'Cabezas',
                                'type' => 'number',
                                'required' => true,
                                'ai_hint' => 'Cantidad de animales comprados, número entero.',
                            ],
                            [
                                'name' => 'categoria',
                                'label' => 'Categoría',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Categoría general de la tropa, tal como está escrita (Ternero, Vaquillona, Novillito, Novillo, Vaca, Toro).',
                            ],
                            [
                                'name' => 'sexo',
                                'label' => 'Sexo',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Casilla marcada entre MACHOS, HEMBRAS y AMBOS. Si hay más de una marcada, devolverlas todas separadas por coma.',
                            ],
                            [
                                'name' => 'machos',
                                'label' => 'Machos',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Sólo con AMBOS: cantidad de machos. Vacío en otro caso.',
                            ],
                            [
                                'name' => 'hembras',
                                'label' => 'Hembras',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Sólo con AMBOS: cantidad de hembras. Vacío en otro caso.',
                            ],
                            [
                                'name' => 'peso_aprox',
                                'label' => 'Peso Aproximado (kg)',
                                'type' => 'number',
                                'required' => true,
                                'ai_hint' => 'Peso promedio aproximado por cabeza, en kg.',
                            ],
                            [
                                'name' => 'peso_min',
                                'label' => 'Peso Mínimo (kg)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Peso mínimo por cabeza, en kg. Puede estar vacío.',
                            ],
                            [
                                'name' => 'peso_max',
                                'label' => 'Peso Máximo (kg)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Peso máximo por cabeza, en kg. Puede estar vacío.',
                            ],
                            [
                                'name' => 'desbaste',
                                'label' => 'Desbaste (%)',
                                'type' => 'number',
                                'required' => false,
                                'ai_hint' => 'Porcentaje de desbaste, sin el signo %. Ej: 3,5. Puede estar vacío.',
                            ],
                            [
                                'name' => 'estado',
                                'label' => 'Estado',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Casilla marcada entre REGULAR, BUENO, MUY BUENO y EXCELENTE.',
                            ],
                            [
                                'name' => 'edad',
                                'label' => 'Edad Aproximada (meses)',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Rango de edad en meses escrito como mínimo/máximo, ej: 9/10. Devolverlo tal cual. Puede estar vacío.',
                            ],
                            [
                                'name' => 'sabe_comer',
                                'label' => 'Sabe Comer',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Casilla marcada: SI o NO.',
                            ],
                            [
                                'name' => 'garrapata',
                                'label' => 'Garrapata (vacunado)',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Casilla marcada: SI (vacunado contra la garrapata) o NO.',
                            ],
                            [
                                'name' => 'observaciones',
                                'label' => 'Observaciones',
                                'type' => 'text',
                                'required' => false,
                                'ai_hint' => 'Notas generales de la compra.',
                            ],
                        ],
                        // The breeds of the troop, one line each: the only table of the sheet.
                        'table_columns' => [
                            [
                                'name' => 'raza',
                                'label' => 'Raza',
                                'type' => 'string',
                                'required' => true,
                                'ai_hint' => 'Raza de ESTE renglón de la tabla RAZAS (Angus, Hereford, Braford, Brangus, Holando, Shorthorn, Limousin, Cruza).',
                            ],
                            [
                                'name' => 'pelaje',
                                'label' => 'Pelaje',
                                'type' => 'string',
                                'required' => false,
                                'ai_hint' => 'Pelaje del mismo renglón (Negro, Colorado, Pampa, Overo Negro…). Puede estar vacío.',
                            ],
                        ],
                    ],
                ]
            );
        }
    }
}
