<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IPathogenRepository;
use App\Core\Interfaces\IProtocolAttachmentStorage;
use App\Core\Interfaces\IProtocolNumberSequenceRepository;
use App\Core\Interfaces\ISampleShipmentRepository;
use App\Core\Interfaces\ISanitaryExposureRepository;
use App\Core\Interfaces\IUserInvitationRepository;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Core\Interfaces\IVeterinaryDiagnosisRepository;
use App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository;
use App\Core\Interfaces\IVeterinaryPortalContext;
use App\Core\Services\BullHealthEvaluationEngine;
use App\Core\Services\SanitaryExposureResolver;
use App\Infrastructure\Persistence\EloquentBullHealthEvaluationRepository;
use App\Infrastructure\Persistence\EloquentDiagnosticProtocolRepository;
use App\Infrastructure\Persistence\EloquentPathogenRepository;
use App\Infrastructure\Persistence\EloquentProtocolNumberSequenceRepository;
use App\Infrastructure\Persistence\EloquentSampleShipmentRepository;
use App\Infrastructure\Persistence\EloquentSanitaryExposureRepository;
use App\Infrastructure\Persistence\EloquentUserInvitationRepository;
use App\Infrastructure\Persistence\EloquentVeterinarianRepository;
use App\Infrastructure\Persistence\EloquentVeterinaryDiagnosisRepository;
use App\Infrastructure\Persistence\EloquentVeterinaryPortalAccessTokenRepository;
use App\Infrastructure\Storage\LocalTenantAttachmentStorage;
use App\Infrastructure\Veterinary\VeterinaryPortalContext;
use Illuminate\Support\ServiceProvider;

final class VeterinaryServiceProvider extends ServiceProvider
{
    /**
     * Standard 1:1 container bindings for the Veterinary & Sanitary domain.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        IBullHealthEvaluationRepository::class => EloquentBullHealthEvaluationRepository::class,
        IDiagnosticProtocolRepository::class => EloquentDiagnosticProtocolRepository::class,
        IPathogenRepository::class => EloquentPathogenRepository::class,
        IProtocolAttachmentStorage::class => LocalTenantAttachmentStorage::class,
        IProtocolNumberSequenceRepository::class => EloquentProtocolNumberSequenceRepository::class,
        ISampleShipmentRepository::class => EloquentSampleShipmentRepository::class,
        ISanitaryExposureRepository::class => EloquentSanitaryExposureRepository::class,
        IUserInvitationRepository::class => EloquentUserInvitationRepository::class,
        IVeterinarianRepository::class => EloquentVeterinarianRepository::class,
        IVeterinaryDiagnosisRepository::class => EloquentVeterinaryDiagnosisRepository::class,
        IVeterinaryPortalAccessTokenRepository::class => EloquentVeterinaryPortalAccessTokenRepository::class,
    ];

    /**
     * Register domain services, scoped portal contexts, and configured engines.
     */
    public function register(): void
    {
        // One portal identity per request, populated by ResolveVeterinaryPortalAccess.
        $this->app->scoped(VeterinaryPortalContext::class);
        $this->app->bind(
            IVeterinaryPortalContext::class,
            VeterinaryPortalContext::class
        );

        // ADR-4: Aptitude engine reading sanitary rules and thresholds from config.
        $this->app->bind(
            BullHealthEvaluationEngine::class,
            static fn (): BullHealthEvaluationEngine => BullHealthEvaluationEngine::fromConfig()
        );

        // Exposure risk window based on configured validity days.
        $this->app->bind(
            SanitaryExposureResolver::class,
            static fn (): SanitaryExposureResolver => new SanitaryExposureResolver(
                (int) config('livestock.venereal.validity_days', 365)
            )
        );
    }
}
