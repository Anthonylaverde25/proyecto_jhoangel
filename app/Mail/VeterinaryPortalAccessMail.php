<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Hands an external professional or laboratory their temporary portal link.
 *
 * The plaintext token exists only inside the request that mints it — the database keeps just its
 * SHA-256 — so this mail is the single delivery opportunity. If it fails, the correct recovery is
 * to reissue, never to try to recover the old link.
 */
class VeterinaryPortalAccessMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $veterinarianName,
        public readonly ?string $licenseNumber,
        public readonly string $accessUrl,
        public readonly string $expiresAt,
        public readonly ?string $label,
        public readonly ?string $scopeDescription,
        public readonly ?string $healthCenterName,
        public readonly string $establishmentName,
        public readonly ?string $senderNote = null
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->label !== null && $this->label !== ''
            ? "Acceso al portal veterinario — {$this->label}"
            : 'Acceso al portal veterinario';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.veterinary-portal-access');
    }
}
