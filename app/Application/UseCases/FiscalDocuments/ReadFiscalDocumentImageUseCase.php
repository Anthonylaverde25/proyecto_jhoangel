<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Application\DTOs\ProviderRecognition;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\IFiscalDocumentReader;
use App\Core\Interfaces\IProviderRepository;
use App\Core\ValueObjects\Cuit;
use App\Core\ValueObjects\FiscalIssuerRead;

/**
 * Recognizes the provider of a document image (Delivery F, part 01 §2.2). The agent has already
 * applied R1 (the QR wins) and R2 (the model's CUIT needs evidence); this applies the rules that
 * need the company and its portfolio: R3 (the issuer cannot be the company), R4 (a receiver other
 * than the company is warned about), R5 (a valid CUIT, different from the receiver's) and R6 (the
 * portfolio). The receiver's CUIT is often absent: no rule fails because of that. Whatever the
 * provider's status, the lines read are resolved against the catalog (Delivery G).
 */
final class ReadFiscalDocumentImageUseCase
{
    public function __construct(
        private readonly IFiscalDocumentReader $reader,
        private readonly IProviderRepository $providers,
        private readonly ICompanyRepository $companies,
        private readonly ResolveFiscalLinesUseCase $resolveLines,
    ) {
    }

    public function __invoke(string $contents, string $fileName, string $mimeType, int $companyId): ProviderRecognition
    {
        $read = $this->reader->readIssuer($contents, $fileName, $mimeType, $companyId);
        $recognition = $this->recognize($read, $companyId);

        return $recognition->withLines(($this->resolveLines)($read->lines, $recognition->knownProviderId(), $companyId));
    }

    private function recognize(FiscalIssuerRead $read, int $companyId): ProviderRecognition
    {
        $companyCuit = $this->companies->findById($companyId)?->getCuit();
        $issuer = $read->issuerCuit;
        $receiver = $read->receiverCuit;

        $unreadable = fn (string $reason, ?string $suggested = null) => new ProviderRecognition(
            ProviderRecognition::UNREADABLE, $reason, null, $read, $companyCuit, $suggested
        );

        if ($issuer === null || $read->problem === FiscalIssuerRead::PROBLEM_NO_CUIT) {
            return $unreadable(ProviderRecognition::REASON_NO_CUIT);
        }

        // R3: whoever the issuer is, it is not the company itself.
        if ($companyCuit !== null && $issuer === $companyCuit) {
            if ($read->issuerCuitSource === FiscalIssuerRead::SOURCE_QR) {
                return $unreadable(ProviderRecognition::REASON_ISSUED_BY_OWN_COMPANY);
            }

            $suggested = $receiver !== null && $receiver !== $companyCuit && Cuit::fromString($receiver)->isWellFormed()
                ? $receiver
                : null;

            return $unreadable(ProviderRecognition::REASON_ISSUER_IS_OWN_COMPANY, $suggested);
        }

        if ($read->problem === FiscalIssuerRead::PROBLEM_ISSUER_BLOCK_UNCERTAIN) {
            return $unreadable(ProviderRecognition::REASON_ISSUER_BLOCK_UNCERTAIN);
        }

        // R5: the agent is not the authority on the CUIT.
        if (!Cuit::fromString($issuer)->isWellFormed()) {
            return $unreadable(ProviderRecognition::REASON_INVALID_CHECK_DIGIT);
        }

        if ($receiver !== null && $receiver === $issuer) {
            return $unreadable(ProviderRecognition::REASON_SAME_AS_RECEIVER);
        }

        // R4: only when both CUITs exist; an absent receiver says nothing.
        $receiverMismatch = $companyCuit !== null && $receiver !== null && $receiver !== $companyCuit;

        // R6: the company's portfolio.
        $provider = $this->providers->findByCuit($issuer, $companyId);
        $status = match (true) {
            $provider === null => ProviderRecognition::NEW,
            !$provider->suppliesItems() => ProviderRecognition::ROLE_MISSING,
            default => ProviderRecognition::MATCHED,
        };

        return new ProviderRecognition($status, null, $provider, $read, $companyCuit, null, $receiverMismatch);
    }
}
