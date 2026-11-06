<?php

namespace MaitreAntho\ResourceAdvisor\Services;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Number;
use MaitreAntho\ResourceAdvisor\Filament\Admin\Pages\ResourceAdvisorPage;
use MaitreAntho\ResourceAdvisor\Models\IgnoredServer;

/**
 * Tells the administrators when a server is killed for lack of memory, and once a day which
 * servers need more resources. Ignored servers are left out.
 */
class Notifier
{
    private const LAST_CRASH = 'resource-advisor.notify.last-crash';

    private const MIB = 1048576;

    public function __construct(private Advisor $advisor) {}

    public function enabled(): bool
    {
        return $this->webhook() !== null || (bool) config('resource-advisor.notify_admins', false);
    }

    /** @return int how many alerts were sent */
    public function oomKills(): int
    {
        if (!$this->enabled() || !config('resource-advisor.notify_oom', true)) {
            return 0;
        }

        $last = Cache::get(self::LAST_CRASH);
        $latest = (int) $this->advisor->crashes()->max('id');

        // First run: start from now, old crashes were already seen in the panel.
        if ($last === null) {
            Cache::forever(self::LAST_CRASH, $latest);

            return 0;
        }

        $morph = (new Server())->getMorphClass();
        $ignored = IgnoredServer::query()->active()->pluck('server_id')->all();
        $sent = 0;

        $this->advisor->crashes()
            ->where('id', '>', (int) $last)
            // Only fresh crashes: an old report imported or restored later is not news.
            ->where('timestamp', '>=', now()->subHour())
            ->orderBy('id')
            ->with('subjects')
            ->get()
            ->each(function (ActivityLog $log) use ($morph, $ignored, &$sent) {
                if (!$this->advisor->isOomKill($log)) {
                    return;
                }

                $ids = $log->subjects->where('subject_type', $morph)->pluck('subject_id')->all();
                foreach (Server::query()->whereIn('id', $ids)->whereNotIn('id', $ignored)->get() as $server) {
                    $this->send(
                        trans('resource-advisor::strings.alerts.oom_title', ['server' => $server->name]),
                        trans('resource-advisor::strings.alerts.oom_body', ['limit' => $server->memory > 0 ? Number::fileSize($server->memory * self::MIB, maxPrecision: 1) : '∞']),
                        'danger',
                    );
                    $sent++;
                }
            });

        Cache::forever(self::LAST_CRASH, $latest);

        return $sent;
    }

    /**
     * The daily summary, sent once at the configured hour when something needs attention.
     *
     * @return bool whether it was sent
     */
    public function digest(bool $force = false): bool
    {
        $hour = config('resource-advisor.digest_hour');
        if (!$this->enabled() || (!$force && ($hour === null || $hour === '' || (int) $hour !== (int) now()->format('G')))) {
            return false;
        }

        if (!$force && !Cache::add('resource-advisor.notify.digest.' . now()->toDateString(), true, now()->addDays(2))) {
            return false;
        }

        $results = collect($this->advisor->all())->reject(fn ($result) => $result['ignored']);
        $increase = $results->where('status', Advisor::INCREASE);
        $decrease = $results->where('status', Advisor::DECREASE);

        if ($increase->isEmpty() && $decrease->isEmpty()) {
            return false;
        }

        $names = Server::query()->whereIn('id', $increase->keys())->orderBy('name')->pluck('name');
        $lines = $names->take(15)->map(fn ($name) => '• ' . $name)->all();
        if ($names->count() > 15) {
            $lines[] = trans('resource-advisor::strings.alerts.and_more', ['count' => $names->count() - 15]);
        }

        $reclaimable = $decrease->sum(fn ($result) => $result['memory']['status'] === Advisor::DECREASE ? $result['memory']['limit'] - $result['memory']['suggested'] : 0);

        $body = trim(implode("\n", array_filter([
            $increase->isNotEmpty() ? trans_choice('resource-advisor::strings.alerts.digest_increase', $increase->count(), ['count' => $increase->count()]) : null,
            implode("\n", $lines),
            $decrease->isNotEmpty() ? trans_choice('resource-advisor::strings.alerts.digest_decrease', $decrease->count(), ['count' => $decrease->count(), 'memory' => Number::fileSize($reclaimable, maxPrecision: 1)]) : null,
        ])));

        $this->send(trans('resource-advisor::strings.alerts.digest_title'), $body, $increase->isNotEmpty() ? 'warning' : 'info');

        return true;
    }

    /** Sends a test message to every channel, and says what went wrong. */
    public function test(): ?string
    {
        if (!$this->enabled()) {
            return trans('resource-advisor::strings.alerts.no_channel');
        }

        try {
            $this->send(trans('resource-advisor::strings.alerts.test_title'), trans('resource-advisor::strings.alerts.test_body'), 'success', throw: true);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function send(string $title, string $body, string $level, bool $throw = false): void
    {
        $url = ResourceAdvisorPage::getUrl(['tableFilters' => ['advice' => ['value' => 'attention']]], panel: 'admin');

        if (config('resource-advisor.notify_admins', false)) {
            $admins = User::query()->get()->filter(fn (User $user) => $user->isRootAdmin());

            Notification::make()
                ->status($level)
                ->title($title)
                ->body(nl2br(e($body)))
                ->actions([Action::make('open')->label(trans('resource-advisor::strings.title'))->url($url)])
                ->sendToDatabase($admins);
        }

        if ($webhook = $this->webhook()) {
            try {
                Http::timeout(10)->withUserAgent('ResourceAdvisor (https://github.com/MaitreAntho/pelican-resource-advisor, 1.1.0)')->post($webhook, [
                    'username' => 'Resource Advisor',
                    'embeds' => [[
                        'title' => $title,
                        'description' => $body,
                        'url' => $url,
                        'color' => match ($level) {
                            'danger' => 0xEF4444,
                            'warning' => 0xF59E0B,
                            'success' => 0x22C55E,
                            default => 0x3B82F6,
                        },
                        'timestamp' => now()->toIso8601String(),
                    ]],
                    'allowed_mentions' => ['parse' => []],
                ])->throw();
            } catch (Exception $exception) {
                report($exception);
                throw_if($throw, $exception);
            }
        }
    }

    private function webhook(): ?string
    {
        $url = trim((string) config('resource-advisor.discord_webhook', ''));

        return str_starts_with($url, 'https://') ? $url : null;
    }
}
