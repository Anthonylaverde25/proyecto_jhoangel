<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CastrationCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ageMonths = null;
        if (!empty($this['birth_date'])) {
            try {
                $birth = new \DateTime($this['birth_date']);
                $now = new \DateTime();
                $diff = $now->diff($birth);
                $ageMonths = ($diff->y * 12) + $diff->m;
            } catch (\Throwable) {
                $ageMonths = null;
            }
        }

        $categoryCode = strtoupper((string) ($this['category_code'] ?? ''));
        $isTorito = $categoryCode === 'TORITO';
        $isCalf = $categoryCode === 'TERNERO';
        $blocked = !empty($this['blocked_by']);

        $preselected = $isCalf && !$blocked;

        $targetCategoryOptions = [];
        $defaultTargetCategoryId = null;

        if ($isTorito) {
            $categories = \App\Models\AnimalCategory::whereIn('code', ['NOVILLITO', 'NOVILLO'])
                ->get(['id', 'code', 'name'])
                ->keyBy('code');

            if (isset($categories['NOVILLITO'])) {
                $targetCategoryOptions[] = [
                    'id'   => (int) $categories['NOVILLITO']->id,
                    'code' => $categories['NOVILLITO']->code,
                    'name' => $categories['NOVILLITO']->name,
                ];
            }
            if (isset($categories['NOVILLO'])) {
                $targetCategoryOptions[] = [
                    'id'   => (int) $categories['NOVILLO']->id,
                    'code' => $categories['NOVILLO']->code,
                    'name' => $categories['NOVILLO']->name,
                ];
            }

            $steerLimit = 20;

            if ($ageMonths !== null) {
                if ($ageMonths <= $steerLimit && isset($categories['NOVILLITO'])) {
                    $defaultTargetCategoryId = (int) $categories['NOVILLITO']->id;
                } elseif ($ageMonths > $steerLimit && isset($categories['NOVILLO'])) {
                    $defaultTargetCategoryId = (int) $categories['NOVILLO']->id;
                }
            }
        }

        return [
            'id'                         => $this['id'],
            'identification'             => $this['caravan_number'] ?? $this['tag_number'],
            'caravan_number'             => $this['caravan_number'],
            'tag_number'                 => $this['tag_number'],
            'visual_tag'                 => $this['tag_number'],
            'category_id'                => $this['category_id'],
            'category_code'              => $this['category_code'],
            'category_name'              => $this['category_name'],
            'birth_date'                 => $this['birth_date'],
            'age_months'                 => $ageMonths,
            'preselected'                => $preselected,
            'blocked_by'                 => $this['blocked_by'],
            'target_category_options'    => $targetCategoryOptions,
            'default_target_category_id' => $defaultTargetCategoryId,
        ];
    }
}
