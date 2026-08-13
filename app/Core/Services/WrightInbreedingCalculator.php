<?php

declare(strict_types=1);

namespace App\Core\Services;

/**
 * Pure Domain Service for Wright's Inbreeding Coefficient (Fx) calculation
 * and zootechnical risk classification based on Dr. Jorge Carrillo (INTA Balcarce).
 *
 * Formula: Fx = Sum ( (1/2)^(n1 + n2 + 1) * (1 + Fa) )
 */
final class WrightInbreedingCalculator
{
    /**
     * Traverses the ancestry of a given animal up to a specified maximum depth.
     *
     * @param int $rootId
     * @param array<int, array{id: int, identification: string, category: ?string, breed: ?string, sex: ?string, father_id: ?int, mother_id: ?int}> $caravansMap
     * @param int $maxDepth
     * @return array<int, int[]> Map of ancestorId => array of depth levels from root
     */
    public function getAncestorPaths(int $rootId, array $caravansMap, int $maxDepth = 6): array
    {
        $ancestorPaths = [];

        $traverse = function (int $currentId, int $currentDepth, array $visited) use (&$traverse, &$ancestorPaths, $caravansMap, $maxDepth) {
            if ($currentDepth >= $maxDepth || in_array($currentId, $visited, true)) {
                return;
            }

            if (!isset($caravansMap[$currentId])) {
                return;
            }

            $current = $caravansMap[$currentId];
            $nextVisited = array_merge($visited, [$currentId]);

            $fatherId = $current['father_id'] ?? null;
            $motherId = $current['mother_id'] ?? null;

            if ($fatherId !== null && $fatherId !== $currentId) {
                if (!isset($ancestorPaths[$fatherId])) {
                    $ancestorPaths[$fatherId] = [];
                }
                $ancestorPaths[$fatherId][] = $currentDepth + 1;
                $traverse($fatherId, $currentDepth + 1, $nextVisited);
            }

            if ($motherId !== null && $motherId !== $currentId) {
                if (!isset($ancestorPaths[$motherId])) {
                    $ancestorPaths[$motherId] = [];
                }
                $ancestorPaths[$motherId][] = $currentDepth + 1;
                $traverse($motherId, $currentDepth + 1, $nextVisited);
            }
        };

        $traverse($rootId, 0, []);

        return $ancestorPaths;
    }

    /**
     * Calculates the inbreeding breakdown between sire and dam lineages.
     *
     * @param int|null $fatherId
     * @param int|null $motherId
     * @param array<int, array{id: int, identification: string, category: ?string, breed: ?string, sex: ?string, father_id: ?int, mother_id: ?int}> $caravansMap
     * @return array{
     *   fx: float,
     *   risk: string,
     *   risk_label: string,
     *   is_exogamous: bool,
     *   common_ancestors: array<int, array{
     *     ancestor_id: int,
     *     identification: string,
     *     category: ?string,
     *     breed: ?string,
     *     sex: ?string,
     *     paternal_paths: int[],
     *     maternal_paths: int[],
     *     paternal_desc: string,
     *     maternal_desc: string,
     *     contribution_percent: float
     *   }>,
     *   summary_explanation: string,
     *   zootechnical_verdict: array{
     *     status: string,
     *     title: string,
     *     description: string,
     *     field_action: string,
     *     bibliographic_note: string
     *   }
     * }
     */
    public function calculate(?int $fatherId, ?int $motherId, array $caravansMap): array
    {
        if ($fatherId === null || $motherId === null) {
            return [
                'fx' => 0.0,
                'risk' => 'OPTIMAL',
                'risk_label' => '0.0% — Sin endogamia registrada',
                'is_exogamous' => true,
                'common_ancestors' => [],
                'summary_explanation' => 'El animal no cuenta con ambos progenitores registrados en el sistema, por lo que se asume exogamia.',
                'zootechnical_verdict' => [
                    'status' => 'RECOMMENDED',
                    'title' => 'Línea de Base Genealógica',
                    'description' => 'Animal con registro de linaje parcial o fundador de rodeo. No se detecta parentesco cercano.',
                    'field_action' => 'Apto para entore general o IATF.',
                    'bibliographic_note' => 'Dr. Jorge Carrillo (INTA Balcarce), Manejo de un Rodeo de Cría, Cap. XV.',
                ],
            ];
        }

        $sireAncestors = $this->getAncestorPaths($fatherId, $caravansMap);
        $damAncestors = $this->getAncestorPaths($motherId, $caravansMap);

        $totalFx = 0.0;
        $contributions = [];

        foreach ($damAncestors as $ancestorId => $dPaths) {
            if (isset($sireAncestors[$ancestorId])) {
                $sPaths = $sireAncestors[$ancestorId];
                $ancestorObj = $caravansMap[$ancestorId] ?? null;
                $ident = $ancestorObj ? $ancestorObj['identification'] : "ID:{$ancestorId}";

                $ancestorContribution = 0.0;
                foreach ($dPaths as $dPath) {
                    foreach ($sPaths as $sPath) {
                        $ancestorContribution += pow(0.5, $dPath + $sPath + 1);
                    }
                }

                $totalFx += $ancestorContribution;

                $formatDepth = function (array $depths): string {
                    $labels = array_map(function (int $d): string {
                        return match ($d) {
                            1 => 'Padre/Madre (1ª Gen)',
                            2 => 'Abuelo/a (2ª Gen)',
                            3 => 'Bisabuelo/a (3ª Gen)',
                            default => "{$d}ª Gen",
                        };
                    }, $depths);
                    return implode(' y ', array_unique($labels));
                };

                $contributions[] = [
                    'ancestor_id' => $ancestorId,
                    'identification' => $ident,
                    'category' => $ancestorObj['category'] ?? null,
                    'breed' => $ancestorObj['breed'] ?? null,
                    'sex' => $ancestorObj['sex'] ?? null,
                    'paternal_paths' => $sPaths,
                    'maternal_paths' => $dPaths,
                    'paternal_desc' => $formatDepth($sPaths),
                    'maternal_desc' => $formatDepth($dPaths),
                    'contribution_percent' => round($ancestorContribution * 100, 4),
                ];
            }
        }

        usort($contributions, fn ($a, $b) => $b['contribution_percent'] <=> $a['contribution_percent']);

        $fxPercentage = min(round($totalFx * 100, 4), 100.0);
        $isExogamous = $fxPercentage === 0.0;

        $risk = 'OPTIMAL';
        $riskLabel = '0.0% — Óptimo (Exogamia)';
        $status = 'RECOMMENDED';
        $title = 'Apareamiento Exogámico Seguro';
        $description = 'Cruce óptimo sin depresión endogámica. Maximiza la heterosis y fertilidad.';
        $fieldAction = 'Animal apto para reproducción, reposición de vientres o retención de toros.';

        if ($fxPercentage > 12.5) {
            $risk = 'CRITICAL';
            $riskLabel = "{$fxPercentage}% — Crítico (Endogamia Severa)";
            $status = 'REJECT';
            $title = 'Depresión Endogámica Crítica (Alto Riesgo)';
            $description = "Consanguinidad excesiva ({$fxPercentage}% > 12.5%). Alta probabilidad de homocigosis de alelos deletéreos recesivos, merma en peso al destete (-8 a -18 kg) y caída en la tasa de preñez.";
            $fieldAction = 'NO RETENER COMO REPRODUCTOR. Se aconseja castración y destino exclusivo a engorde para faena.';
        } elseif ($fxPercentage > 6.25) {
            $risk = 'HIGH';
            $riskLabel = "{$fxPercentage}% — Alto (Medio Hermanos)";
            $status = 'REJECT';
            $title = 'Alerta de Endogamia Alta';
            $description = "Parentesco cercano ({$fxPercentage}% entre 6.25% y 12.5%). Se desaconseja para cría de reposición.";
            $fieldAction = 'Separar de los lotes de servicio de toros emparentados o enviar a engorde.';
        } elseif ($fxPercentage > 3.125) {
            $risk = 'MODERATE';
            $riskLabel = "{$fxPercentage}% — Moderado (Primos)";
            $status = 'CAUTION';
            $title = 'Endogamia Moderada (Precaución)';
            $description = "Parentesco equivalente a primos hermanos ({$fxPercentage}%). Aceptable para rodeo general, no recomendado para cabaña de pedigree pura.";
            $fieldAction = 'Monitorear los servicios futuros para alternar con reproductores exogámicos.';
        } elseif ($fxPercentage > 0.0) {
            $risk = 'VERY_LOW';
            $riskLabel = "{$fxPercentage}% — Muy Bajo (Seguro)";
            $status = 'RECOMMENDED';
            $title = 'Parentesco Lejano Aceptable';
            $description = "Consanguinidad mínima ({$fxPercentage}% <= 3.125%). Cruce seguro.";
            $fieldAction = 'Apto para reproducción y reposición general.';
        }

        if ($isExogamous) {
            $sireIdent = isset($caravansMap[$fatherId]) ? $caravansMap[$fatherId]['identification'] : (string) $fatherId;
            $damIdent = isset($caravansMap[$motherId]) ? $caravansMap[$motherId]['identification'] : (string) $motherId;
            $summaryExplanation = "Exogamia completa: Las líneas del padre (#{$sireIdent}) y de la madre (#{$damIdent}) no comparten ningún ancestro común conocido. Se aprovecha el 100% del vigor híbrido (heterosis).";
        } else {
            $ancestorsStr = implode(', ', array_map(fn ($c) => "#{$c['identification']} (+{$c['contribution_percent']}%)", $contributions));
            $count = count($contributions);
            $summaryExplanation = "Consanguinidad del {$fxPercentage}% originada por la repetición de {$count} ancestro(s) común(es) en ambas ramas parentales: {$ancestorsStr}.";
        }

        return [
            'fx' => $fxPercentage,
            'risk' => $risk,
            'risk_label' => $riskLabel,
            'is_exogamous' => $isExogamous,
            'common_ancestors' => $contributions,
            'summary_explanation' => $summaryExplanation,
            'zootechnical_verdict' => [
                'status' => $status,
                'title' => $title,
                'description' => $description,
                'field_action' => $fieldAction,
                'bibliographic_note' => 'Dr. Jorge Carrillo (INTA Balcarce), Manejo de un Rodeo de Cría, Cap. XV y XVI.',
            ],
        ];
    }
}
