<?php

declare(strict_types=1);

namespace App\Core\Catalogs;

use App\Core\Enums\UnitDimension;

/**
 * Units and categories every company starts with (D14). Pure data; migration M10 keeps its own
 * frozen copy so later edits here never change what it did.
 */
final class BaseItemCatalog
{
    /**
     * @return list<array{code:string,name:string,symbol:string,dimension:UnitDimension,factor:?string,is_base:bool,order:int}>
     */
    public function units(): array
    {
        return [
            ['code' => 'KG', 'name' => 'Kilogramo', 'symbol' => 'kg', 'dimension' => UnitDimension::MASS, 'factor' => '1', 'is_base' => true, 'order' => 1],
            ['code' => 'G', 'name' => 'Gramo', 'symbol' => 'g', 'dimension' => UnitDimension::MASS, 'factor' => '0.001', 'is_base' => false, 'order' => 2],
            ['code' => 'TN', 'name' => 'Tonelada', 'symbol' => 'tn', 'dimension' => UnitDimension::MASS, 'factor' => '1000', 'is_base' => false, 'order' => 3],
            ['code' => 'L', 'name' => 'Litro', 'symbol' => 'l', 'dimension' => UnitDimension::VOLUME, 'factor' => '1', 'is_base' => true, 'order' => 4],
            ['code' => 'ML', 'name' => 'Mililitro', 'symbol' => 'ml', 'dimension' => UnitDimension::VOLUME, 'factor' => '0.001', 'is_base' => false, 'order' => 5],
            ['code' => 'UNIT', 'name' => 'Unidad', 'symbol' => 'u', 'dimension' => UnitDimension::COUNT, 'factor' => '1', 'is_base' => true, 'order' => 6],
            ['code' => 'DOSE', 'name' => 'Dosis', 'symbol' => 'dosis', 'dimension' => UnitDimension::COUNT, 'factor' => null, 'is_base' => false, 'order' => 7],
            ['code' => 'HEAD', 'name' => 'Cabeza', 'symbol' => 'cab', 'dimension' => UnitDimension::COUNT, 'factor' => null, 'is_base' => false, 'order' => 8],
            ['code' => 'SERVICE', 'name' => 'Servicio', 'symbol' => 'serv', 'dimension' => UnitDimension::COUNT, 'factor' => null, 'is_base' => false, 'order' => 9],
            ['code' => 'HOUR', 'name' => 'Hora', 'symbol' => 'h', 'dimension' => UnitDimension::TIME, 'factor' => '1', 'is_base' => true, 'order' => 10],
            ['code' => 'WORKDAY', 'name' => 'Jornada', 'symbol' => 'jor', 'dimension' => UnitDimension::TIME, 'factor' => null, 'is_base' => false, 'order' => 11],
            ['code' => 'HA', 'name' => 'Hectárea', 'symbol' => 'ha', 'dimension' => UnitDimension::AREA, 'factor' => '1', 'is_base' => true, 'order' => 12],
            ['code' => 'M', 'name' => 'Metro', 'symbol' => 'm', 'dimension' => UnitDimension::LENGTH, 'factor' => '1', 'is_base' => true, 'order' => 13],
        ];
    }

    /**
     * @return list<array{code:string,name:string,order:int}>
     */
    public function categories(): array
    {
        return [
            ['code' => 'HEALTH', 'name' => 'Sanidad', 'order' => 1],
            ['code' => 'NUTRITION', 'name' => 'Nutrición', 'order' => 2],
            ['code' => 'REPRODUCTION', 'name' => 'Reproducción', 'order' => 3],
            ['code' => 'IDENTIFICATION', 'name' => 'Identificación', 'order' => 4],
            ['code' => 'FUEL', 'name' => 'Combustibles', 'order' => 5],
            ['code' => 'MAINTENANCE', 'name' => 'Mantenimiento', 'order' => 6],
            ['code' => 'FREIGHT', 'name' => 'Fletes', 'order' => 7],
            ['code' => 'VET_SERVICES', 'name' => 'Servicios veterinarios', 'order' => 8],
            ['code' => 'FIELD_SERVICES', 'name' => 'Servicios de campo', 'order' => 9],
        ];
    }
}
