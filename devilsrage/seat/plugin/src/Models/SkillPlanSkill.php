<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $skill_plan_id
 * @property int $skill_id
 * @property int $level
 * @property int $position
 */
class SkillPlanSkill extends Model
{
    public $timestamps = false;

    protected $table = 'devilsrage_skill_plan_skills';

    protected $fillable = ['skill_plan_id', 'skill_id', 'level', 'position'];

    /** @var array<string, string> */
    protected $casts = ['skill_id' => 'integer', 'level' => 'integer'];
}
