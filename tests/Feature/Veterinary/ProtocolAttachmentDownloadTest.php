<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\Company;
use App\Models\ProtocolAttachment;
use App\Models\User;

/**
 * ADR-6: sanitary evidence lives on a private disk. The signed URL alone is not a credential;
 * company membership is re-checked on every read.
 */
class ProtocolAttachmentDownloadTest extends VeterinaryTestCase
{
    public function test_a_member_can_open_the_evidence_through_the_signed_link(): void
    {
        $attachment = $this->seededAttachment();

        $signedUrl = $this->signedUrlFor((int) $attachment->id);

        $this->actingAs($this->user, 'sanctum')
            ->get($signedUrl)
            ->assertStatus(200)
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_an_unsigned_url_is_refused(): void
    {
        $attachment = $this->seededAttachment();

        $this->actingAs($this->user, 'sanctum')
            ->get($this->url("/protocol-attachments/{$attachment->id}/download"))
            ->assertStatus(403);
    }

    public function test_a_valid_signature_is_not_enough_for_a_non_member(): void
    {
        $attachment = $this->seededAttachment();
        $signedUrl = $this->signedUrlFor((int) $attachment->id);

        $outsider = User::create([
            'name' => 'Curioso',
            'email' => 'curioso@prueba.com',
            'password' => bcrypt('secret123'),
        ]);

        // Deliberately a member of some other company: the link must still not open.
        $other = Company::create(['name' => 'Otro Establecimiento', 'cuit' => '30-70000000-2']);
        $this->linkUserToCompany($outsider, (int) $other->id);

        $this->actingAs($outsider, 'sanctum')
            ->get($signedUrl)
            ->assertStatus(403);
    }

    private function seededAttachment(): ProtocolAttachment
    {
        return ProtocolAttachment::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->firstOrFail();
    }

    /**
     * In production the link is minted while serving an API request, so its root is already the
     * tenant domain. The test rig has to pin that root explicitly or the signature would be
     * computed for `localhost`, which no tenant answers on.
     */
    private function signedUrlFor(int $attachmentId): string
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('http://' . $this->host);

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'protocol-attachments.download',
            now()->addMinutes(5),
            ['attachment' => $attachmentId]
        );
    }
}
