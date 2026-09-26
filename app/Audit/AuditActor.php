<?php

declare(strict_types=1);

namespace App\Audit;

use App\Enums\AuditSource;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Who is acting, for the audit log, carried the way the tenant is.
 *
 * Most audited actions are never handed an actor, and they run over HTTP, on
 * the queue and on the console alike, so the actor travels in hidden context:
 * set once where it is known, dehydrated into every queued job, and gone from a
 * job that carried none, because hydrating a job replaces the whole context.
 * Reading the session or the authenticated user inside an action would record
 * nobody for everything that runs off the request.
 */
final readonly class AuditActor
{
    public const string KEY = 'audit.actor';

    public function __construct(
        public ?int $userId,
        public ?int $impersonationId,
        public AuditSource $source,
    ) {}

    public static function user(User $user, ?int $impersonationId = null): self
    {
        return new self($user->id, $impersonationId, AuditSource::Web);
    }

    public static function source(AuditSource $source): self
    {
        return new self(null, null, $source);
    }

    /**
     * The actor for the current unit of work, or nobody in particular.
     */
    public static function current(): self
    {
        $stored = Context::getHidden(self::KEY);

        if (! is_array($stored)) {
            return self::source(AuditSource::System);
        }

        return new self(
            is_int($stored['user_id'] ?? null) ? $stored['user_id'] : null,
            is_int($stored['impersonation_id'] ?? null) ? $stored['impersonation_id'] : null,
            AuditSource::tryFrom((string) ($stored['source'] ?? '')) ?? AuditSource::System,
        );
    }

    /**
     * Run the callback as this actor, then restore whoever was acting before.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runAs(self $actor, Closure $callback): mixed
    {
        $previous = Context::getHidden(self::KEY);

        $actor->bind();

        try {
            return $callback();
        } finally {
            $previous === null
                ? Context::forgetHidden(self::KEY)
                : Context::addHidden(self::KEY, $previous);
        }
    }

    /**
     * Make this the actor for the rest of the current unit of work.
     */
    public function bind(): void
    {
        Context::addHidden(self::KEY, [
            'user_id' => $this->userId,
            'impersonation_id' => $this->impersonationId,
            'source' => $this->source->value,
        ]);
    }
}
