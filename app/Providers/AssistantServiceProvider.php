<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Interfaces\IAssistantGateway;
use App\Core\Interfaces\IFiscalDocumentReader;
use App\Core\Interfaces\IFiscalItemMatcher;
use App\Core\Interfaces\IMcpAuthorizationCodeRepository;
use App\Core\Interfaces\IMcpConnectionRepository;
use App\Core\Interfaces\IMcpOAuthClientRepository;
use App\Core\Interfaces\IMcpTokenGateway;
use App\Core\Interfaces\ITaskRepository;
use App\Infrastructure\Assistant\AiAgentAssistantGateway;
use App\Infrastructure\Auth\SanctumMcpTokenGateway;
use App\Infrastructure\OCR\AiAgentFiscalDocumentReader;
use App\Infrastructure\OCR\AiAgentFiscalItemMatcher;
use App\Infrastructure\Persistence\EloquentMcpAuthorizationCodeRepository;
use App\Infrastructure\Persistence\EloquentMcpConnectionRepository;
use App\Infrastructure\Persistence\EloquentMcpOAuthClientRepository;
use App\Infrastructure\Persistence\EloquentTaskRepository;
use Illuminate\Support\ServiceProvider;

final class AssistantServiceProvider extends ServiceProvider
{
    /**
     * Bindings for AI Assistant Gateways, OCR Readers, and MCP Protocols.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        IAssistantGateway::class => AiAgentAssistantGateway::class,
        IFiscalDocumentReader::class => AiAgentFiscalDocumentReader::class,
        IFiscalItemMatcher::class => AiAgentFiscalItemMatcher::class,
        IMcpAuthorizationCodeRepository::class => EloquentMcpAuthorizationCodeRepository::class,
        IMcpConnectionRepository::class => EloquentMcpConnectionRepository::class,
        IMcpOAuthClientRepository::class => EloquentMcpOAuthClientRepository::class,
        IMcpTokenGateway::class => SanctumMcpTokenGateway::class,
        ITaskRepository::class => EloquentTaskRepository::class,
    ];
}
