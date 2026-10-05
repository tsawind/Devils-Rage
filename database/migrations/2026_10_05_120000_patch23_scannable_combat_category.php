<?php

declare(strict_types=1);

use App\Enums\SignatureCategory as SignatureCategoryEnum;
use App\Models\SignatureCategory;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Patch 23: "Scannable Combat Site", combat sites that had to be probed down
     * (id 9, matching resources/js/data/signatures.json).
     */
    public function up(): void
    {
        $category = SignatureCategoryEnum::ScannableCombat;

        if (SignatureCategory::query()->where('code', $category->value)->exists()) {
            return;
        }

        $model = new SignatureCategory;
        $model->id = 9;
        $model->name = $category->name();
        $model->code = $category;
        $model->save();
    }

    public function down(): void
    {
        SignatureCategory::query()->where('code', SignatureCategoryEnum::ScannableCombat->value)->delete();
    }
};
