<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed catalog of provider document types (DS15), as defined by ARCA (AFIP). Read-only for the
 * application: a new type ships as a data migration. The code reads behaviour from the row.
 */
return new class extends Migration
{
    /** @var list<array{0:string,1:string,2:?string,3:string,4:?string,5:bool,6:bool,7:int,8:bool,9:int}> */
    private const TYPES = [
        // code, name, afip_code, kind, letter, discriminates_vat, carries_amounts, sign, is_active, order
        ['INVOICE_A', 'Factura A', '001', 'INVOICE', 'A', true, true, 1, true, 1],
        ['INVOICE_B', 'Factura B', '006', 'INVOICE', 'B', false, true, 1, true, 2],
        ['INVOICE_C', 'Factura C', '011', 'INVOICE', 'C', false, true, 1, true, 3],
        ['CREDIT_NOTE_A', 'Nota de crédito A', '003', 'CREDIT_NOTE', 'A', true, true, -1, true, 4],
        ['CREDIT_NOTE_B', 'Nota de crédito B', '008', 'CREDIT_NOTE', 'B', false, true, -1, true, 5],
        ['CREDIT_NOTE_C', 'Nota de crédito C', '013', 'CREDIT_NOTE', 'C', false, true, -1, true, 6],
        ['TICKET', 'Ticket u otro comprobante', null, 'TICKET', null, false, true, 1, true, 7],
        // Catalogued now, loaded with the invoices-and-delivery-notes delivery (DS22).
        ['DELIVERY_NOTE', 'Remito', '091', 'DELIVERY_NOTE', 'R', false, false, 0, false, 8],
    ];

    public function up(): void
    {
        Schema::create('fiscal_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique('uq_fdtype_code');
            $table->string('name', 64);
            $table->string('afip_code', 3)->nullable();
            $table->string('kind', 16);                        // INVOICE | CREDIT_NOTE | TICKET | DELIVERY_NOTE
            $table->string('letter', 1)->nullable();           // A | B | C | R
            $table->boolean('discriminates_vat');              // only letter A
            $table->boolean('carries_amounts');                // a delivery note has none
            $table->tinyInteger('sign');                       // +1 cost, -1 credit, 0 no amounts
            $table->boolean('is_active')->default(true);       // offered for loading
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE fiscal_document_types ADD CONSTRAINT chk_fdtype_kind
                CHECK (kind IN ('INVOICE','CREDIT_NOTE','TICKET','DELIVERY_NOTE'))");
            DB::statement('ALTER TABLE fiscal_document_types ADD CONSTRAINT chk_fdtype_sign
                CHECK (sign IN (-1, 0, 1))');
        }

        $now = now();
        foreach (self::TYPES as [$code, $name, $afip, $kind, $letter, $vat, $amounts, $sign, $active, $order]) {
            DB::table('fiscal_document_types')->insert([
                'code' => $code, 'name' => $name, 'afip_code' => $afip, 'kind' => $kind, 'letter' => $letter,
                'discriminates_vat' => $vat, 'carries_amounts' => $amounts, 'sign' => $sign,
                'is_active' => $active, 'display_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_types');
    }
};
