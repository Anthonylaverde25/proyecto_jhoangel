<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use App\Mail\VeterinaryPortalAccessMail;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers a freshly minted portal link by email.
 *
 * Deliberately never throws. The grant is already persisted by the time this runs, and the
 * plaintext link exists only in memory for the rest of the request — so a bounced SMTP
 * connection must not abort the response and take the only copy of the link with it. The caller
 * reports the failure and still shows the link on screen to be copied by hand.
 */
final class SendVeterinaryPortalAccessService
{
    /**
     * @return array{sent: bool, recipient: ?string, error: ?string}
     */
    public function __invoke(
        VeterinaryPortalAccessTokenEntity $token,
        ?string $recipientEmail,
        ?string $senderNote = null
    ): array {
        $accessUrl = $this->buildAccessUrl($token);

        if ($accessUrl === null) {
            return [
                'sent' => false,
                'recipient' => $recipientEmail,
                'error' => 'El enlace en claro no está disponible: sólo puede enviarse en el momento de emitirlo.',
            ];
        }

        if ($recipientEmail === null || trim($recipientEmail) === '') {
            return [
                'sent' => false,
                'recipient' => null,
                'error' => 'El profesional no tiene correo cargado. Indique una dirección de destino.',
            ];
        }

        try {
            Mail::to($recipientEmail)->send(new VeterinaryPortalAccessMail(
                veterinarianName: (string) ($token->getVeterinarianName() ?? 'Profesional'),
                licenseNumber: $token->getLicenseNumber(),
                accessUrl: $accessUrl,
                expiresAt: $token->getExpiresAt()->format('d/m/Y H:i'),
                label: $token->getLabel(),
                scopeDescription: $this->describeScope($token),
                establishmentName: $this->establishmentName($token->getCompanyId()),
                senderNote: $senderNote
            ));

            return ['sent' => true, 'recipient' => $recipientEmail, 'error' => null];
        } catch (Throwable $e) {
            // The link stays on screen; the operator can still copy it and send it by hand.
            Log::warning('No se pudo enviar el acceso al portal veterinario por correo.', [
                'token_id' => $token->getId(),
                'recipient' => $recipientEmail,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'recipient' => $recipientEmail, 'error' => $e->getMessage()];
        }
    }

    private function buildAccessUrl(VeterinaryPortalAccessTokenEntity $token): ?string
    {
        $plainToken = $token->getPlainToken();

        if ($plainToken === null) {
            return null;
        }

        $template = (string) config(
            'livestock.veterinary_portal.public_url_template',
            'http://localhost:3000/vet-portal/:token'
        );

        return str_replace(':token', $plainToken, $template);
    }

    private function describeScope(VeterinaryPortalAccessTokenEntity $token): ?string
    {
        if ($token->isScopedToAct()) {
            return 'Acta de extracción ' . ($token->getProtocolNumber() ?? '');
        }

        if ($token->getBatchName() !== null) {
            return 'Lote ' . $token->getBatchName();
        }

        return 'Todas las tropas asignadas al profesional';
    }

    private function establishmentName(int $companyId): string
    {
        return (string) (Company::query()->find($companyId)?->name ?? 'El establecimiento');
    }
}
