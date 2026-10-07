<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Group;
use App\Models\Type;


beforeEach(function () {
    Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    Group::query()->firstOrCreate(['id' => 27], ['name' => 'Battleship', 'category_id' => 6]);
    Group::query()->firstOrCreate(['id' => Type::HEAVY_INTERDICTOR_GROUP_ID], ['name' => 'Heavy Interdiction Cruiser', 'category_id' => 6]);
    Group::query()->firstOrCreate(['id' => 1201], ['name' => 'Combat Battlecruiser', 'category_id' => 6]);
});

it('patch 34: a HIC logs 20% of its hull (Zero-Point Mass Entangler on)', function () {
    Type::query()->create(['id' => 12013, 'name' => 'Broadsword', 'group_id' => Type::HEAVY_INTERDICTOR_GROUP_ID, 'mass' => 12_000_000]);

    expect(Type::loggedJumpMass(12013))->toBe(2_400_000);
});

it('patch 34: the Odysseus logs 20% of its hull', function () {
    Type::query()->create(['id' => 37480, 'name' => 'Odysseus', 'group_id' => 1201, 'mass' => 13_000_000]);

    expect(Type::loggedJumpMass(37480))->toBe(2_600_000);
});

it('patch 34: other ships log their hull, unknown ships nothing', function () {
    Type::query()->create(['id' => 641, 'name' => 'Megathron', 'group_id' => 27, 'mass' => 98_400_000]);

    expect(Type::loggedJumpMass(641))->toBe(98_400_000)
        ->and(Type::loggedJumpMass(null))->toBe(0)
        ->and(Type::loggedJumpMass(999_999))->toBe(0);
});
