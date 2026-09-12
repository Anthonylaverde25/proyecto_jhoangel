<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-39 rev. — Migración W: el acta declara TAMBIÉN a dónde van los tubos.
 *
 * Hasta ahora `institution` era la única institución de un acta, y significa el centro DEL ACTA:
 * el remitente. Un acta que declaraba TO_BE_DERIVED decía que iba a derivar y no tenía dónde
 * escribir a dónde, así que el destino aparecía recién en el envío o en el informe — y el modal
 * de despacho precargaba el remitente en el casillero del destinatario, que es peor que no
 * precargar nada porque el error llega lleno y plausible.
 *
 * La columna se escribe al despachar, no al firmar, y por eso NO forma parte de lo que la firma
 * congela (ADR-38): tiene el mismo estatus que el envío que la origina, que es una declaración
 * posterior del profesional citando el acta.
 *
 * No hay backfill y no puede haberlo: no existe ningún dato del que se deduzca el destino
 * declarado sin inventarlo. Un acta vieja derivada tiene su institución en el envío y en el
 * informe, que es donde corresponde leerla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extraction_act_details', function (Blueprint $table): void {
            // Nullable y sin default: la ausencia significa "todavía no se declaró", que es la
            // verdad de toda acta anterior a esta migración y de toda acta que no se deriva.
            $table->json('destination_institution')->nullable()->after('institution');
        });
    }

    public function down(): void
    {
        Schema::table('extraction_act_details', function (Blueprint $table): void {
            $table->dropColumn('destination_institution');
        });
    }
};
