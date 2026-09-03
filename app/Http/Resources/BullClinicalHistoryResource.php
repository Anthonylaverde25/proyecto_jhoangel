<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BullClinicalHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $caravan = $this->resource['caravan'];
        $metrics = $this->resource['metrics'];
        $evaluations = $this->resource['evaluations'];
        $labSamples = $this->resource['lab_samples'];
        $diagnoses = $this->resource['diagnoses'];

        // Build unified clinical timeline
        $timeline = [];

        foreach ($evaluations as $eval) {
            $date = $eval->last_evaluation_date ? $eval->last_evaluation_date->format('Y-m-d') : null;
            if ($date) {
                $timeline[] = [
                    'id' => 'eval-' . $eval->id,
                    'type' => 'ANDROLOGICAL_EXAM',
                    'date' => $date,
                    'title' => 'Revisación Andrológica en Manga',
                    'description' => sprintf(
                        'CE: %s cm | CC: %s | Líbido: %s | Aplomos: %s',
                        $eval->scrotal_circumference_cm ?? 'N/A',
                        $eval->body_condition_score ?? 'N/A',
                        $eval->libido ?? 'MEDIA',
                        $eval->aplomo_notes ?? 'Correctos'
                    ),
                    'status' => $eval->status?->value ?? 'PENDING_EVALUATION',
                    'meta' => [
                        'ce_cm' => $eval->scrotal_circumference_cm,
                        'bcs' => $eval->body_condition_score,
                    ],
                ];
            }
        }

        foreach ($labSamples as $sample) {
            $typeLabel = $sample->sample_type === 'PREPUCE_SCRAPE'
                ? 'Raspaje Prepucial (ETS)'
                : 'Serología Sanguínea (Brucelosis)';

            $timeline[] = [
                'id' => 'sample-' . $sample->id,
                'type' => 'LAB_SAMPLE',
                'date' => $sample->sample_date ? $sample->sample_date->format('Y-m-d') : null,
                'title' => 'Muestreo de Laboratorio: ' . $typeLabel,
                'description' => sprintf(
                    'Tubo: %s | Ronda %d | Protocolo: %s | Estado: %s',
                    $sample->tube_number ?? 'S/D',
                    $sample->sample_round ?? 1,
                    $sample->protocol_number ?? 'En proceso',
                    $sample->status ?? 'PENDING_RESULTS'
                ),
                'status' => $sample->status,
                'meta' => [
                    'sample_type' => $sample->sample_type,
                    'tube_number' => $sample->tube_number,
                    'protocol_number' => $sample->protocol_number,
                    'result_date' => $sample->result_date ? $sample->result_date->format('Y-m-d') : null,
                    'pathogen' => $sample->pathogen?->name,
                ],
            ];
        }

        foreach ($diagnoses as $diag) {
            $diagDate = $diag->diagnosis_date ? $diag->diagnosis_date->format('Y-m-d') : null;
            $timeline[] = [
                'id' => 'diag-' . $diag->id,
                'type' => 'VETERINARY_DIAGNOSIS',
                'date' => $diagDate,
                'title' => 'Diagnóstico Clínico: ' . ($diag->pathogen?->name ?? 'Afección Médica'),
                'description' => sprintf(
                    'Estado: %s | Vet: %s | %s',
                    $diag->status ?? 'ACTIVE',
                    $diag->veterinarian?->name ?? 'Médico Veterinario',
                    $diag->treatment_notes ?? 'Sin notas adicionales'
                ),
                'status' => $diag->status,
                'meta' => [
                    'is_disqualifying' => (bool) $diag->pathogen?->is_disqualifying,
                    'resolution_date' => $diag->resolution_date ? $diag->resolution_date->format('Y-m-d') : null,
                    'notes' => $diag->treatment_notes,
                ],
            ];
        }

        // Sort unified timeline descending by date
        usort($timeline, function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        return [
            'caravan' => [
                'id' => $caravan->id,
                'identification' => $caravan->identification,
                'sex' => $caravan->sex?->value ?? 'MALE',
                'teeth' => $caravan->teeth,
                'entry_weight' => $caravan->entry_weight,
                'current_weight' => $caravan->currentWeight?->weight ?? $caravan->entry_weight,
                'entry_date' => $caravan->entry_date ? $caravan->entry_date->format('Y-m-d') : null,
                'breed' => $caravan->breedRelation?->name ?? 'Angus',
                'color' => $caravan->colorRelation?->name ?? 'Negro',
                'category' => $caravan->categoryRelation?->name ?? 'Toro Reproductor',
                'batch_name' => $caravan->batch?->name ?? 'Plantel General',
                'farm_name' => $caravan->batch?->farm?->name ?? 'Establecimiento Principal',
                'renspa' => $caravan->batch?->farm?->renspa ?? $caravan->renspa,
            ],
            'computed_status' => $this->resource['computed_status'],
            'metrics' => $metrics,
            'evaluations' => $evaluations->map(function ($e) {
                return [
                    'id' => $e->id,
                    'last_evaluation_date' => $e->last_evaluation_date ? $e->last_evaluation_date->format('Y-m-d') : null,
                    'scrotal_circumference_cm' => $e->scrotal_circumference_cm !== null ? (float) $e->scrotal_circumference_cm : null,
                    'body_condition_score' => $e->body_condition_score !== null ? (float) $e->body_condition_score : null,
                    'libido' => $e->libido ?? 'MEDIA',
                    'aplomo_notes' => $e->aplomo_notes,
                    'status' => $e->status?->value ?? 'PENDING_EVALUATION',
                    'observations' => $e->observations,
                ];
            })->values()->all(),
            'lab_samples' => $labSamples->map(function ($s) {
                return [
                    'id' => $s->id,
                    'sample_type' => $s->sample_type,
                    'sample_round' => $s->sample_round,
                    'sample_date' => $s->sample_date ? $s->sample_date->format('Y-m-d') : null,
                    'tube_number' => $s->tube_number,
                    'status' => $s->status,
                    'protocol_number' => $s->protocol_number,
                    'result_date' => $s->result_date ? $s->result_date->format('Y-m-d') : null,
                    'pathogen_name' => $s->pathogen?->name,
                ];
            })->values()->all(),
            'diagnoses' => $diagnoses->map(function ($d) {
                return [
                    'id' => $d->id,
                    'pathogen_code' => $d->pathogen?->code ?? 'DIAG',
                    'pathogen_name' => $d->pathogen?->name ?? 'Afección Clínica',
                    'pathogen_is_disqualifying' => (bool) $d->pathogen?->is_disqualifying,
                    'veterinarian_name' => $d->veterinarian?->name,
                    'diagnosis_date' => $d->diagnosis_date ? $d->diagnosis_date->format('Y-m-d') : null,
                    'resolution_date' => $d->resolution_date ? $d->resolution_date->format('Y-m-d') : null,
                    'status' => $d->status,
                    'treatment_notes' => $d->treatment_notes,
                ];
            })->values()->all(),
            'timeline' => $timeline,
        ];
    }
}
