<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Interfaces\IVeterinaryPortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A management user may open any professional's portal and read all of it. What they may never
 * do is write in their name.
 *
 * Enforced here rather than in each action on purpose: the rule is "a reader writes nothing",
 * and a new portal endpoint added tomorrow is covered without anybody remembering to guard it.
 * Signing, dispatching and reporting are acts somebody attests to — a manager performing one
 * would put a professional's licence under a document they never saw.
 */
class DenyReadOnlyPortalWrites
{
    public function __construct(private readonly IVeterinaryPortalContext $portalContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (!$isRead && $this->portalContext->isResolved() && $this->portalContext->isReadOnly()) {
            return response()->json([
                'message' => 'Está viendo el portal de otro profesional. Puede mirar todo, pero firmar, despachar o informar son actos que sólo puede realizar quien responde por ellos.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
