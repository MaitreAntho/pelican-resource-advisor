<?php

namespace MaitreAntho\ResourceAdvisor\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server whose advice is hidden from the counters, the filter and the alerts.
 *
 * @property int $id
 * @property int $server_id
 * @property \Illuminate\Support\Carbon|null $until
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class IgnoredServer extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'resource_advisor_ignores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'until' => 'datetime',
        ];
    }

    /** @param Builder<IgnoredServer> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('until')->orWhere('until', '>', now()));
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
