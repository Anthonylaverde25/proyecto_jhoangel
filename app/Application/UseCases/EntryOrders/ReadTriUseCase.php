<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Infrastructure\OCR\AiAgentOCRProvider;
use App\Models\Caravan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Reads the TRI (Tarjeta de Registro Individual de Tropa, SENASA) attached to the reception of a
 * DTE, to fill in its caravans: one or several files — photos of each page, or one PDF with all of
 * them — read by the AI with the TRI schema. It only reads: nothing is received until the person
 * reviews the caravans and registers the reception.
 *
 * What looks wrong is reported, never fixed: a caravan written twice, one that already exists in
 * the system, a RENSPA other than the order's, a page that could not be read.
 */
final class ReadTriUseCase
{
    public const TEMPLATE_CODE = 'TRI';

    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @param UploadedFile[] $files
     * @return array{
     *     tri_numbers: list<string>,
     *     pages: list<array{file_name: string, page: ?int, total: ?int, caravans: int, error: ?string}>,
     *     caravans: list<array{caravana: string, item: ?int, page: ?int}>,
     *     repeated: list<string>,
     *     existing: list<string>,
     *     warnings: list<string>
     * }
     *
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $orderId, int $companyId, array $files): array
    {
        $order = $this->repository->findById($orderId, $companyId) ?? throw EntryOrderDomainException::notFound();
        $reader = new AiAgentOCRProvider($companyId);
        $triNumbers = [];
        $renspas = [];
        $pages = [];
        $caravans = [];
        $seen = [];
        $repeated = [];
        $warnings = [];

        foreach ($files as $file) {
            try {
                $reading = $reader->analyze($file, self::TEMPLATE_CODE);
            } catch (\RuntimeException $e) {
                Log::warning('[ReadTriUseCase] A TRI page could not be read', ['file' => $file->getClientOriginalName(), 'error' => $e->getMessage()]);
                $pages[] = ['file_name' => $file->getClientOriginalName(), 'page' => null, 'total' => null, 'caravans' => 0, 'error' => 'No se pudo leer el archivo.'];
                $warnings[] = "No se pudo leer {$file->getClientOriginalName()}: cargá sus caravanas a mano o probá con otra foto.";

                continue;
            }

            $context = $reading['context'] ?? [];
            $page = self::number($context['hoja_numero'] ?? null);
            $read = 0;

            $triNumber = self::text($context['tri_numero'] ?? null);
            $renspa = self::text($context['renspa_origen'] ?? null);
            if ($triNumber !== '') {
                $triNumbers[$triNumber] = true;
            }
            if ($renspa !== '') {
                $renspas[$renspa] = true;
            }

            $rows = $reading['data'][0]['mapped_rows'] ?? [];
            // In item order. A PDF of several pages repeats the items (1, 2… on each page): then the
            // reading order, page by page, is kept.
            $items = array_map(fn (array $row) => self::number($row['item'] ?? null), $rows);
            if (count(array_unique($items)) === count($items)) {
                usort($rows, fn (array $a, array $b) => (self::number($a['item'] ?? null) ?? PHP_INT_MAX) <=> (self::number($b['item'] ?? null) ?? PHP_INT_MAX));
            }

            foreach ($rows as $row) {
                $tag = mb_strtoupper(preg_replace('/\s+/', '', self::text($row['caravana'] ?? null)) ?? '');

                if ($tag === '') {
                    continue;
                }

                $read++;

                if (isset($seen[$tag])) {
                    $repeated[$tag] = true;

                    continue;
                }

                $seen[$tag] = true;
                $caravans[] = ['caravana' => $tag, 'item' => self::number($row['item'] ?? null), 'page' => $page];
            }

            $pages[] = [
                'file_name' => $file->getClientOriginalName(),
                'page' => $page,
                'total' => self::number($context['hoja_total'] ?? null),
                'caravans' => $read,
                'error' => null,
            ];
        }

        $existing = $caravans === [] ? [] : Caravan::withoutGlobalScopes()
            ->whereIn('identification', array_column($caravans, 'caravana'))
            ->pluck('identification')
            ->map(fn ($tag) => mb_strtoupper((string) $tag))
            ->unique()
            ->values()
            ->all();

        $orderRenspa = preg_replace('/\D/', '', (string) $order->name('farm_renspa'));
        foreach (array_keys($renspas) as $renspa) {
            if ($orderRenspa !== '' && preg_replace('/\D/', '', $renspa) !== $orderRenspa) {
                $warnings[] = "El TRI dice RENSPA de origen {$renspa} y la orden es de {$order->name('farm_renspa')}: revisá que sea el TRI de esta tropa.";
            }
        }

        if (count($triNumbers) > 1) {
            $warnings[] = 'Las hojas son de distintos TRI (' . implode(', ', array_keys($triNumbers)) . '): revisá que sean de este DTE.';
        }

        return [
            'tri_numbers' => array_map('strval', array_keys($triNumbers)),
            'pages' => $pages,
            'caravans' => $caravans,
            'repeated' => array_map('strval', array_keys($repeated)),
            'existing' => $existing,
            'warnings' => $warnings,
        ];
    }

    /** A cell as the AI returns it ({value}) or plain. */
    private static function text(mixed $cell): string
    {
        $value = is_array($cell) ? ($cell['value'] ?? null) : $cell;

        return trim((string) $value);
    }

    private static function number(mixed $cell): ?int
    {
        $digits = preg_replace('/\D/', '', self::text($cell));

        return $digits !== '' ? (int) $digits : null;
    }
}
