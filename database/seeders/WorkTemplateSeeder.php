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

            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'OP-02'],
                [
                    'category' => 'ACTIVITY',
                    'title' => 'Transferencia a Invernada',
                    'description' => 'Movimiento de lotes que finalizan la recría y pasan a terminación.',
                    'status' => 'active'
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
        }
    }
}
