<?php

namespace MaitreAntho\ResourceAdvisor\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hour of usage for one server.
 *
 * @property int $id
 * @property int $server_id
 * @property \Illuminate\Support\Carbon $hour
 * @property int $samples
 * @property int $running_samples
 * @property int $memory_max
 * @property int $memory_sum
 * @property float $cpu_max
 * @property float $cpu_sum
 * @property int $disk_max
 * @property int $disk_last
 */
class ResourceSample extends Model
{
    public $timestamps = false;

    protected $table = 'resource_advisor_samples';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hour' => 'datetime',
            'cpu_max' => 'float',
            'cpu_sum' => 'float',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
