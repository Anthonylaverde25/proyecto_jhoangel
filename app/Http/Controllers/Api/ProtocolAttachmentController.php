<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Interfaces\IProtocolAttachmentStorage;
use App\Http\Controllers\Controller;
use App\Models\ProtocolAttachment;
use App\Policies\DiagnosticProtocolPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ADR-6: the only read path into the private tenant disk. Reached through a short lived
 * signed URL AND re-checked against company membership, because a signed URL that leaks is
 * otherwise a bearer credential to sanitary evidence.
 */
class ProtocolAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        int $attachment,
        IProtocolAttachmentStorage $storage,
        DiagnosticProtocolPolicy $policy
    ): BinaryFileResponse|Response {
        $model = ProtocolAttachment::withoutGlobalScopes()->find($attachment);

        if ($model === null) {
            return response()->json(['message' => 'Adjunto no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $user = $request->user() ?? auth('sanctum')->user();

        if ($user === null || !$policy->downloadAttachment($user, $model)) {
            return response()->json(['message' => 'No autorizado para acceder a esta evidencia.'], Response::HTTP_FORBIDDEN);
        }

        if (!$storage->exists($model->file_path)) {
            return response()->json(['message' => 'El archivo de evidencia ya no está disponible.'], Response::HTTP_GONE);
        }

        return response()->file($storage->absolutePath($model->file_path), [
            'Content-Type' => $model->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($model->file_name) . '"',
        ]);
    }
}
