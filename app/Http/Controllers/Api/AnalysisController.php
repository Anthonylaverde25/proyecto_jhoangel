<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalysisController extends Controller
{
    /**
     * Analyze a handwritten table image using Gemini 1.5 Flash.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240', // Max 10MB
        ]);

        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            return response()->json(['error' => 'Gemini API key not configured.'], 500);
        }

        try {
            $imageFile = $request->file('image');
            $imageData = base64_encode(file_get_contents($imageFile->getPathname()));
            $mimeType = $imageFile->getMimeType();

            $prompt = "Actúa como un experto en digitalización de datos ganaderos. " .
                "Analiza la siguiente imagen que contiene una tabla escrita a mano (tipo Excel). " .
                "Extrae todos los campos (cabeceras) en un array. normaliza los espacios en blanco, ejemplo user name user_name " .
                "Devuelve el resultado estrictamente en formato JSON, como un array de objetos, ";
            // "donde cada objeto represente una fila de la tabla. No incluyas texto adicional fuera del JSON.";

            $response = Http::post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key={$apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $imageData
                                ]
                            ]
                        ]
                    ]
                ],
            ]);

            if ($response->failed()) {
                return response()->json([
                    'error' => 'Failed to process image with Gemini.',
                    'details' => $response->json()
                ], $response->status());
            }

            $result = $response->json();
            $outputText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '[]';

            // Remove markdown code blocks if present
            $outputText = preg_replace('/^```json\s*|\s*```$/i', '', trim($outputText));

            $data = json_decode($outputText, true);

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Analysis Exception', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'An unexpected error occurred during analysis.'], 500);
        }
    }
}
