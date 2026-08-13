<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Defines the method used to determine sire paternity after a birth.
 *
 * Based on "Manejo de un Rodeo de Cría" (Carrillo):
 * - Phenotypic identification is possible in first-generation crosses (e.g., Hereford "careta").
 * - Genetic lab analysis is required when phenotype is ambiguous (second-generation crosses,
 *   multiple sires of the same breed, collective service).
 * - Operational identification applies when service was individually controlled.
 */
enum SireIdentificationMethod: string
{
    /** Father known by individual/controlled service record — no ambiguity. */
    case OPERATIONAL = 'operational';

    /** Father identified by offspring's visible phenotypic traits (coat color, facial marks, conformation). */
    case PHENOTYPE = 'phenotype';

    /** Father confirmed by DNA / genomic laboratory analysis (SNP chip, microsatellites). */
    case LAB_GENETIC = 'lab_genetic';
}
