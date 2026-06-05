<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Services\Rep01SelectionNormalizer;
use PHPUnit\Framework\TestCase;

final class Rep01SelectionNormalizerTest extends TestCase
{
    private Rep01SelectionNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new Rep01SelectionNormalizer();
    }

    public function test_can_extract_and_normalize_diagnosis_marker_after_text(): void
    {
        $raw = 'Preñada :selected: Vacía :unselected:';
        $result = $this->normalizer->normalizeDiagnosis($raw);

        $this->assertSame('PREGNANT', $result['value']);
        $this->assertSame('Preñada', $result['label']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function test_can_extract_and_normalize_diagnosis_marker_before_text(): void
    {
        $raw = ':selected: Preñada :unselected: Vacía';
        $result = $this->normalizer->normalizeDiagnosis($raw);

        $this->assertSame('PREGNANT', $result['value']);
        $this->assertSame('Preñada', $result['label']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function test_can_extract_and_normalize_diagnosis_vacía(): void
    {
        $raw = ':unselected: Preñada :selected: Vacía';
        $result = $this->normalizer->normalizeDiagnosis($raw);

        $this->assertSame('EMPTY', $result['value']);
        $this->assertSame('Vacía', $result['label']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function test_can_extract_and_normalize_gestation_stage_head(): void
    {
        $raw = ':selected: Cabeza :unselected: Cuerpo :unselected: Cola';
        $result = $this->normalizer->normalizeGestationStage($raw);

        $this->assertSame('head', $result['value']);
        $this->assertSame('Cabeza', $result['label']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function test_can_extract_and_normalize_gestation_stage_body(): void
    {
        $raw = ':unselected: Cabeza :selected: Cuerpo :unselected: Cola';
        $result = $this->normalizer->normalizeGestationStage($raw);

        $this->assertSame('body', $result['value']);
        $this->assertSame('Cuerpo', $result['label']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function test_can_extract_and_normalize_gestation_stage_tail(): void
    {
        $raw = ':unselected: Cabeza :unselected: Cuerpo :selected: Cola';
        $result = $this->normalizer->normalizeGestationStage($raw);

        $this->assertSame('tail', $result['value']);
        $this->assertSame('Cola', $result['label']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function test_field_detections(): void
    {
        $this->assertTrue($this->normalizer->isDiagnosisField('diagnsti_co'));
        $this->assertTrue($this->normalizer->isDiagnosisField('diagnostico'));
        $this->assertTrue($this->normalizer->isDiagnosisField('diagnosis'));

        $this->assertTrue($this->normalizer->isGestationStageField('estadio_estimado'));
        $this->assertTrue($this->normalizer->isGestationStageField('gestational_stage'));

        $this->assertTrue($this->normalizer->isCategoryField('categora'));
        $this->assertTrue($this->normalizer->isCategoryField('category'));

        $this->assertTrue($this->normalizer->isObservationsField('observaci_ones'));
        $this->assertTrue($this->normalizer->isObservationsField('observations'));

        $this->assertTrue($this->normalizer->isCaravanaField('caravana'));
        $this->assertTrue($this->normalizer->isCaravanaField('identification'));
    }
}
