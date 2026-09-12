<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class VeterinaryDomainException extends DomainException
{
    public static function duplicateProtocolNumber(string $protocolNumber, ?int $existingId = null): self
    {
        $suffix = $existingId !== null ? " (protocolo existente ID {$existingId})" : '';

        return new self("Ya existe un protocolo con el número {$protocolNumber} para esta compañía{$suffix}.");
    }

    public static function caravanNotOwnedOrNotMale(string $identifier): self
    {
        return new self("La caravana {$identifier} no pertenece a la compañía o no es un macho.");
    }

    public static function attachmentRequired(): self
    {
        return new self('La digitalización de evidencia externa exige al menos un archivo adjunto.');
    }

    public static function protocolNotFound(int $id): self
    {
        return new self("Protocolo diagnóstico ID {$id} no encontrado.");
    }

    public static function protocolAlreadyVoided(string $protocolNumber): self
    {
        return new self("El protocolo {$protocolNumber} ya se encuentra anulado.");
    }

    public static function veterinarianNotFound(int $id): self
    {
        return new self("Veterinario ID {$id} no encontrado en el catálogo de la compañía.");
    }

    public static function batchNotAssigned(int $batchId): self
    {
        return new self("El profesional no tiene asignado el lote ID {$batchId}.");
    }

    public static function actNotFound(int $id): self
    {
        return new self("Acta de extracción ID {$id} no encontrada.");
    }

    public static function actAlreadySigned(string $actNumber): self
    {
        return new self("El acta {$actNumber} ya fue firmada y es inmutable. Para corregirla debe anularse y reemitirse.");
    }

    public static function actNotSigned(string $actNumber): self
    {
        return new self("El acta {$actNumber} todavía no fue firmada por el profesional actuante: no puede recibir un informe de laboratorio.");
    }

    public static function signatureNotAllowed(string $actNumber): self
    {
        return new self("Sólo el profesional asignado al acta {$actNumber} puede firmarla.");
    }

    public static function labReportAlreadyIssued(string $actNumber, string $reportNumber): self
    {
        return new self("El acta {$actNumber} ya tiene el informe de laboratorio {$reportNumber} asociado.");
    }

    public static function protocolTypeMismatch(string $expected, string $actual): self
    {
        return new self("Se esperaba un documento de tipo {$expected} y se recibió {$actual}.");
    }

    public static function sampleNotInAct(int $sampleId, string $actNumber): self
    {
        return new self("La muestra ID {$sampleId} no pertenece al acta {$actNumber}.");
    }

    /**
     * ADR-22: the tubes exist, but nobody ever declared having received them. The physical fact
     * was never blocked — its consequence is.
     */
    public static function samplesNotReceived(string $actNumber): self
    {
        return new self("Ninguna muestra del acta {$actNumber} fue declarada como recibida: no se puede cargar un informe sobre tubos que nadie tomó en custodia.");
    }

    public static function sampleNotAnalysable(int $sampleId, string $receptionStatus): self
    {
        return new self("La muestra ID {$sampleId} no está en condiciones de ser analizada (estado de llegada {$receptionStatus}). Un tubo faltante o dañado exige reextracción, no un resultado.");
    }

    /** ADR-27: the transcription of an external laboratory stands on its PDF or on nothing. */
    public static function derivedReportRequiresAttachment(string $actNumber): self
    {
        return new self("El acta {$actNumber} se derivó a un laboratorio externo: el informe transcrito exige adjuntar el PDF original del laboratorio.");
    }

    // ---------------------------------------------------------------- v9

    /** ADR-29: a typo rejected at the door beats a history fragmented months later. */
    public static function invalidCuit(string $raw, string $reason): self
    {
        return new self("El CUIT {$raw} no es válido: {$reason}.");
    }

    public static function institutionNameRequired(): self
    {
        return new self('La institución necesita al menos un nombre: es lo que identifica dónde estuvieron las muestras.');
    }

    /** ADR-30: a shipment that declares nothing is not a shipment. */
    public static function shipmentNeedsSamples(): self
    {
        return new self('El envío no incluye ningún tubo. Elija al menos uno de los que tiene en su poder.');
    }

    public static function sampleNotAvailableForShipment(int $sampleId): self
    {
        return new self("La muestra ID {$sampleId} no está disponible para despachar: ya viajó en otro envío o no pertenece a este profesional.");
    }

    /** §4: a broken cold chain is a professional judgement, and it has to be written down. */
    public static function shipmentRequiresColdChainNote(): self
    {
        return new self('Si la cadena de frío se cortó hay que dejar constancia de en qué condiciones viajaron las muestras.');
    }

    /** ADR-37: once a report cited these tubes, the shipment is evidence somebody relied on. */
    public static function shipmentAlreadyCited(int $shipmentId): self
    {
        return new self("El envío ID {$shipmentId} ya fue citado por un informe de laboratorio: para corregirlo hay que anularlo con motivo y emitir uno nuevo.");
    }

    public static function shipmentAlreadyVoided(int $shipmentId): self
    {
        return new self("El envío ID {$shipmentId} ya está anulado.");
    }

    /** ADR-35: a transcription of somebody else's analysis stands on their paper or on nothing. */
    public static function analysisReportRequiresAttachment(string $institutionName): self
    {
        return new self("El análisis lo realizó {$institutionName}: el informe transcrito exige adjuntar el PDF original de esa institución.");
    }

    public static function reportingInstitutionRequired(): self
    {
        return new self('Indique la institución desde la que informa: el protocolo siempre lleva su nombre y CUIT.');
    }

    public static function derivationRequiresAnalysingInstitution(): self
    {
        return new self('Marcó que el análisis fue derivado: indique el nombre y CUIT de quién lo procesó.');
    }

    public static function invitationNotUsable(): self
    {
        return new self('Esta invitación ya fue utilizada o venció. Solicite una nueva al establecimiento.');
    }

    public static function domainError(string $message): self
    {
        return new self($message);
    }
}
