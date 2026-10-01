<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Enums\ShipSize;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class SignatureData extends Data
{
    public function __construct(
        public string|Optional|null $signature_id,
        public int|Optional|null $signature_type_id,
        public int|Optional|null $signature_category_id,
        public int|Optional|null $map_connection_id,
        public MassStatus|Optional|null $mass_status,
        public ShipSize|Optional|null $ship_size,
        public LifetimeStatus|Optional $lifetime,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeImmutable|Optional|null $lifetime_updated_at,
        public string|Optional|null $raw_type_name,
        public string|Optional|null $alias = new Optional,
        public bool|Optional $is_static = new Optional,
        public bool|Optional $is_wandering = new Optional,
        /** Patch 14: the scanner confirmed renaming the system this hole leads to (rename popup). */
        public bool|Optional $rename_system = new Optional,
        /**
         * Patch 14: lock other unjumped holes in this system to the numbers they show now
         * (signature id => number), so a rename here doesn't shift their planned numbers.
         *
         * @var array<int|string, string>|Optional
         */
        public array|Optional $lock_others = new Optional,
    ) {}

    public static function rules(): array
    {
        return [
            'signature_id' => ['nullable', 'sometimes', 'string', 'max:7', 'min:7'],
            'signature_category_id' => ['nullable', 'sometimes', 'integer', 'exists:signature_categories,id'],
            'signature_type_id' => ['nullable', 'sometimes', 'integer', 'exists:signature_types,id'],
            'map_connection_id' => ['nullable', 'sometimes', 'integer', 'exists:map_connections,id'],
            'lifetime' => ['nullable', 'sometimes', Rule::enum(LifetimeStatus::class)],
            'lifetime_updated_at' => ['nullable', 'sometimes', "date_format:Y-m-d\TH:i:sP"],
            'mass_status' => ['nullable', 'sometimes', Rule::enum(MassStatus::class)],
            'ship_size' => ['nullable', 'sometimes', Rule::enum(ShipSize::class)],
            'raw_type_name' => ['nullable', 'sometimes', 'string', 'max:255'],
            'alias' => ['nullable', 'sometimes', 'string', 'max:32'],
            'is_static' => ['sometimes', 'boolean'],
            'is_wandering' => ['sometimes', 'boolean'],
            'rename_system' => ['sometimes', 'boolean'],
            'lock_others' => ['sometimes', 'array'],
            'lock_others.*' => ['string', 'max:32'],
        ];
    }

    public static function authorize(#[CurrentUser] User $user, #[RouteParameter('signature')] Signature $signature): bool
    {
        if (! $user->can('update', $signature)) {
            return false;
        }

        $map_connection = MapConnection::query()->find($signature->map_connection_id);

        if (! $map_connection instanceof MapConnection) {
            return true;
        }
        if ($map_connection->fromMapSolarsystem()->is($signature->mapSolarsystem)) {
            return true;
        }

        return $map_connection->toMapSolarsystem()->is($signature->mapSolarsystem);
    }
}
