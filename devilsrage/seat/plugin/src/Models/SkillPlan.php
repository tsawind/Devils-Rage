<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 */
class SkillPlan extends Model
{
    protected $table = 'devilsrage_skill_plans';

    protected $fillable = ['name', 'description', 'created_by'];

    public function skills(): HasMany
    {
        return $this->hasMany(SkillPlanSkill::class, 'skill_plan_id')->orderBy('position');
    }

    /**
     * @return array<int, int> skill ID => level
     */
    public function requirements(): array
    {
        return $this->skills->mapWithKeys(fn (SkillPlanSkill $skill): array => [$skill->skill_id => $skill->level])->all();
    }
}
