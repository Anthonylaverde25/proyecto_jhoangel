<?php

declare(strict_types=1);

namespace Tests\Feature\FiscalDocuments;

use App\Core\Exceptions\FiscalReaderUnavailableException;
use App\Core\Interfaces\IFiscalDocumentReader;
use App\Core\ValueObjects\Cuit;
use App\Core\ValueObjects\FiscalIssuerRead;
use App\Models\Company;
use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * Recognizing the provider of a document image (Delivery F, part 01 §2.2). The agent is faked: these
 * tests cover the rules that need the company and its portfolio.
 */
class ReadFiscalDocumentImageTest extends VeterinaryTestCase
{
    private const COMPANY_CUIT = '30717000028';

    protected function setUp(): void
    {
        parent::setUp();

        Company::findOrFail($this->company->id)->fill(['cuit' => self::COMPANY_CUIT])->save();
    }

    public function test_a_provider_selling_items_or_services_is_matched(): void
    {
        $provider = $this->provider(true);
        $this->agentReads($this->read($provider->cuit, 'qr', receiver: self::COMPANY_CUIT));

        $this->upload()->assertOk()
            ->assertJsonPath('status', 'MATCHED')
            ->assertJsonPath('provider.id', $provider->id)
            ->assertJsonPath('issuer.source', 'qr')
            ->assertJsonPath('receiver_mismatch', false);
    }

    public function test_a_livestock_only_provider_needs_the_role(): void
    {
        $provider = $this->provider(false);
        $this->agentReads($this->read($provider->cuit, 'qr'));

        $this->upload()->assertOk()->assertJsonPath('status', 'ROLE_MISSING')->assertJsonPath('provider.id', $provider->id);
    }

    public function test_an_unknown_valid_cuit_is_new_and_carries_what_was_read(): void
    {
        $this->agentReads($this->read($this->freshCuit(), 'vision', name: 'Distribuidora Pampa S.A.'));

        $this->upload()->assertOk()
            ->assertJsonPath('status', 'NEW')
            ->assertJsonPath('provider', null)
            ->assertJsonPath('issuer.name', 'Distribuidora Pampa S.A.');
    }

    public function test_a_provider_of_another_company_is_not_a_match(): void
    {
        $other = Company::where('id', '!=', $this->company->id)->firstOrFail();
        $foreign = Provider::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'name' => 'Del vecino', 'cuit' => $this->freshCuit(), 'supplies_items' => true,
        ]);
        $this->agentReads($this->read($foreign->cuit, 'qr'));

        $this->upload()->assertOk()->assertJsonPath('status', 'NEW');
    }

    public function test_a_missing_receiver_changes_nothing_and_warns_nothing(): void
    {
        $provider = $this->provider(true);
        $this->agentReads($this->read($provider->cuit, 'vision', receiver: null));

        $this->upload()->assertOk()
            ->assertJsonPath('status', 'MATCHED')
            ->assertJsonPath('receiver', null)
            ->assertJsonPath('receiver_mismatch', false);
    }

    public function test_a_receiver_other_than_the_company_is_warned_without_changing_the_status(): void
    {
        $provider = $this->provider(true);
        $this->agentReads($this->read($provider->cuit, 'qr', receiver: '20987654323'));

        $this->upload()->assertOk()->assertJsonPath('status', 'MATCHED')->assertJsonPath('receiver_mismatch', true);
    }

    public function test_without_company_cuit_the_receiver_is_not_checked(): void
    {
        Company::findOrFail($this->company->id)->fill(['cuit' => null])->save();
        $provider = $this->provider(true);
        $this->agentReads($this->read($provider->cuit, 'qr', receiver: '20987654323'));

        $this->upload()->assertOk()
            ->assertJsonPath('status', 'MATCHED')
            ->assertJsonPath('receiver_mismatch', false)
            ->assertJsonPath('company_cuit_missing', true);
    }

    public function test_a_qr_issued_by_the_company_itself_is_a_sale(): void
    {
        $this->agentReads($this->read(self::COMPANY_CUIT, 'qr', receiver: '20987654323'));

        $this->upload()->assertOk()->assertJsonPath('status', 'UNREADABLE')->assertJsonPath('reason', 'ISSUED_BY_OWN_COMPANY');
    }

    public function test_a_vision_read_of_the_company_cuit_suggests_the_other_one(): void
    {
        $this->agentReads($this->read(self::COMPANY_CUIT, 'vision', receiver: '20123456786'));

        $this->upload()->assertOk()
            ->assertJsonPath('status', 'UNREADABLE')
            ->assertJsonPath('reason', 'ISSUER_IS_OWN_COMPANY')
            ->assertJsonPath('suggested_cuit', '20123456786');
    }

    /**
     * @return array<string, array{string, ?string, ?string, ?string, ?string}>
     */
    public static function unreadableReasons(): array
    {
        return [
            'no cuit' => ['NO_CUIT', null, null, null, FiscalIssuerRead::PROBLEM_NO_CUIT],
            'block uncertain' => ['ISSUER_BLOCK_UNCERTAIN', '20123456786', 'vision', null, FiscalIssuerRead::PROBLEM_ISSUER_BLOCK_UNCERTAIN],
            'invalid check digit' => ['INVALID_CHECK_DIGIT', '30123456789', 'vision', null, null],
            'same as receiver' => ['SAME_AS_RECEIVER', '20123456786', 'vision', '20123456786', null],
        ];
    }

    #[DataProvider('unreadableReasons')]
    public function test_each_unreadable_reason(string $reason, ?string $issuer, ?string $source, ?string $receiver, ?string $problem): void
    {
        $this->agentReads($this->read($issuer, $source, receiver: $receiver, problem: $problem));

        $this->upload()->assertOk()->assertJsonPath('status', 'UNREADABLE')->assertJsonPath('reason', $reason);
    }

    public function test_the_qr_vision_discrepancy_travels_to_the_sheet(): void
    {
        $provider = $this->provider(true);
        $this->agentReads($this->read($provider->cuit, 'qr', discrepancy: '20111111112'));

        $this->upload()->assertOk()->assertJsonPath('status', 'MATCHED')->assertJsonPath('issuer.discrepancy_cuit', '20111111112');
    }

    public function test_an_unavailable_agent_is_a_502_with_a_clear_message(): void
    {
        $this->app->instance(IFiscalDocumentReader::class, new class implements IFiscalDocumentReader {
            public function readIssuer(string $contents, string $fileName, string $mimeType, int $companyId): FiscalIssuerRead
            {
                throw FiscalReaderUnavailableException::unreachable();
            }
        });

        $this->upload()->assertStatus(502)->assertJsonPath('message', FiscalReaderUnavailableException::unreachable()->getMessage());
    }

    public function test_an_avif_image_is_accepted(): void
    {
        $provider = $this->provider(true);
        $this->agentReads($this->read($provider->cuit, 'qr'));
        $avif = new UploadedFile(base_path('tests/Fixtures/fiscal/blank.avif'), 'recibo.avif', 'image/avif', null, true);

        $this->apiAs('POST', '/fiscal-documents/read-image', ['document' => $avif])
            ->assertOk()->assertJsonPath('status', 'MATCHED');
    }

    public function test_only_images_and_pdf_are_accepted(): void
    {
        $this->apiAs('POST', '/fiscal-documents/read-image', ['document' => UploadedFile::fake()->create('factura.docx', 10)])
            ->assertStatus(422)->assertJsonValidationErrors('document');
    }

    private function upload()
    {
        return $this->apiAs('POST', '/fiscal-documents/read-image', ['document' => UploadedFile::fake()->image('factura.png')]);
    }

    private function agentReads(FiscalIssuerRead $read): void
    {
        $this->app->instance(IFiscalDocumentReader::class, new class($read) implements IFiscalDocumentReader {
            public function __construct(private readonly FiscalIssuerRead $read)
            {
            }

            public function readIssuer(string $contents, string $fileName, string $mimeType, int $companyId): FiscalIssuerRead
            {
                return $this->read;
            }
        });
    }

    private function read(
        ?string $issuer,
        ?string $source,
        ?string $receiver = null,
        ?string $problem = null,
        ?string $discrepancy = null,
        ?string $name = null,
    ): FiscalIssuerRead {
        return new FiscalIssuerRead(
            $issuer, $source, $discrepancy, $problem, $receiver, $receiver !== null ? $source : null,
            $name, null, null, null, null, null, 'fake', 10,
        );
    }

    private function provider(bool $suppliesItems): Provider
    {
        return Provider::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Proveedor leído',
            'cuit' => $this->freshCuit(),
            'supplies_livestock' => !$suppliesItems,
            'supplies_items' => $suppliesItems,
        ]);
    }

    /** A valid CUIT nobody uses yet (the test tenant persists between runs). */
    private function freshCuit(): string
    {
        do {
            $ten = '30' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $expected = Cuit::fromString($ten . '0')->expectedCheckDigit();
            $digits = $ten . $expected;
        } while ($expected === null || Provider::withoutGlobalScopes()->where('cuit', $digits)->exists());

        return $digits;
    }
}
