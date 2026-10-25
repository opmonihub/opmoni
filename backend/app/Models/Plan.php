<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'limits', 'erp_commercial'])]
class Plan extends Model
{
    /** @use HasFactory<Plan> */
    use HasFactory;

    public const DEFAULT_ERP_COMMERCIAL = [
        'enabled' => false,
        'licence_limit' => 0,
        'module_keys' => [],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limits' => 'array',
        ];
    }

    /** @return Attribute<array{enabled: bool, licence_limit: ?int, module_keys: list<string>}, array{enabled: bool, licence_limit: ?int, module_keys: list<string>}> */
    protected function erpCommercial(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? self::DEFAULT_ERP_COMMERCIAL : json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    public static function bySlug(string $slug): static
    {
        return static::where('slug', $slug)->firstOrFail();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
