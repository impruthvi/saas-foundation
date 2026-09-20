<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

/**
 * The tenant, data owner, subscription holder, and entitlement subject.
 * It is bounded by membership rather than a tenant scope of its own.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $personal
 * @property int $owner_id
 * @property OrganizationStatus $status
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $owner
 * @property-read Collection<int, Membership> $memberships
 * @property-read int|null $memberships_count
 * @property-read Collection<int, Project> $projects
 * @property-read int|null $projects_count
 * @property-read Collection<int, Subscription> $subscriptions
 * @property-read int|null $subscriptions_count
 *
 * @method static OrganizationFactory factory($count = null, $state = [])
 * @method static Builder<static>|Organization newModelQuery()
 * @method static Builder<static>|Organization newQuery()
 * @method static Builder<static>|Organization query()
 *
 * @mixin Model
 */
#[Fillable(['name', 'slug', 'personal', 'owner_id', 'status'])]
final class Organization extends Model
{
    use Billable;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The user who owns the organization and is billed for it.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isPersonal(): bool
    {
        return $this->personal;
    }

    /**
     * The address Stripe sends receipts to.
     *
     * An organization has no address of its own, and a customer created without
     * one is identifiable in Stripe only by its key. The owner is loaded rather
     * than read, because lazy loading raises everywhere in this application.
     */
    public function stripeEmail(): string
    {
        return $this->loadMissing('owner')->owner->email;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal' => 'boolean',
            'status' => OrganizationStatus::class,
            'trial_ends_at' => 'datetime',
        ];
    }
}
