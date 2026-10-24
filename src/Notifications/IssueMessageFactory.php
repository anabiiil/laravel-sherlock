<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications;

use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Enums\Severity;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;
use Throwable;

/**
 * Builder: IssueRecord -> channel-agnostic NotificationMessage, in three variants
 * (new issue, regression, throttled repeat summary). Reads only the stored issue context,
 * which is already redacted at capture time; the dispatcher redacts the result again.
 */
final class IssueMessageFactory
{
    /** Dashboard route names tried, in order, for the issue link. */
    public const DASHBOARD_ROUTES = ['tracing.issues.show', 'tracing.issue'];

    private const MAX_METRICS = 4;

    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly Repository $config,
        private readonly ?Container $container = null,
    ) {}

    /** New issue, or regression when the issue has a regressed_at timestamp. */
    public function forIssue(IssueRecord $issue): NotificationMessage
    {
        return $issue->regressedAt !== null ? $this->regression($issue) : $this->newIssue($issue);
    }

    public function newIssue(IssueRecord $issue): NotificationMessage
    {
        $summary = $this->description($issue);

        return $this->make($issue, $issue->title, $summary !== '' ? $summary : $issue->type->label().' detected.', $issue->occurrences);
    }

    public function regression(IssueRecord $issue): NotificationMessage
    {
        $summary = 'This issue came back after being resolved'
            .($issue->resolvedAt !== null ? ' on '.$issue->resolvedAt->format('Y-m-d H:i').' UTC' : '').'.';

        $description = $this->description($issue);
        if ($description !== '') {
            $summary .= ' '.$description;
        }

        return $this->make($issue, 'Regression: '.$issue->title, $summary, $issue->occurrences);
    }

    /**
     * Throttled repeat summary: "occurred N more times in the last X minutes".
     */
    public function summary(IssueRecord $issue, int $count, int $minutes): NotificationMessage
    {
        $count = max(1, $count);
        $minutes = max(1, $minutes);

        $summary = sprintf(
            'Occurred %d more %s in the last %s (%d in total).',
            $count,
            $count === 1 ? 'time' : 'times',
            self::window($minutes),
            $issue->occurrences,
        );

        return $this->make($issue, 'Still happening: '.$issue->title, $summary, $count);
    }

    private function make(IssueRecord $issue, string $title, string $summary, int $occurrences): NotificationMessage
    {
        return new NotificationMessage(
            title: $title,
            severity: $issue->severity,
            summary: $summary,
            fields: $this->fields($issue),
            sql: $this->sql($issue),
            aiRecommendation: $issue->hasAiAnalysis() ? trim((string) $issue->aiAnalysis) : null,
            url: $this->dashboardUrl($issue->id),
            occurrences: $occurrences,
            footer: $this->footer(),
            issueId: $issue->id,
            fingerprint: $issue->fingerprint,
        );
    }

    /**
     * @return array<string, string>
     */
    private function fields(IssueRecord $issue): array
    {
        $context = $issue->context;
        $entry = self::arr($context['entry'] ?? null);
        $trace = self::arr($context['trace'] ?? null);
        $verdict = self::arr($context['verdict'] ?? null);

        $fields = ['Type' => $issue->type->label(), 'Severity' => $issue->severity->label()];

        $entryLabel = $this->entry($entry);
        if ($entryLabel !== null) {
            $fields['Entry'] = $entryLabel;
        }

        $location = $this->location(self::arr($context['location'] ?? null));
        if ($location !== null) {
            $fields['Location'] = $location;
        }

        $reason = self::str($verdict['reason'] ?? null);
        if ($reason !== null) {
            $fields['Verdict'] = $reason;
        }

        if (is_numeric($trace['duration_ms'] ?? null)) {
            $fields['Duration'] = self::ms((float) $trace['duration_ms']);
        }

        if (is_numeric($trace['db_count'] ?? null)) {
            $fields['Queries'] = (int) $trace['db_count']
                .(is_numeric($trace['db_ms'] ?? null) ? ' ('.self::ms((float) $trace['db_ms']).')' : '');
        }

        foreach (array_slice($this->metrics(self::arr($context['metrics'] ?? null)), 0, self::MAX_METRICS, true) as $label => $value) {
            $fields[$label] ??= $value;
        }

        $explain = $this->explain(self::arr($context['explain'] ?? null));
        if ($explain !== null) {
            $fields['EXPLAIN'] = $explain;
        }

        $exception = $this->exception(self::arr($context['exception'] ?? null));
        if ($exception !== null) {
            $fields['Exception'] = $exception;
        }

        $fields['Occurrences'] = (string) $issue->occurrences;
        $fields['First seen'] = $issue->firstSeenAt->format('Y-m-d H:i').' UTC';

        return $fields;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function entry(array $entry): ?string
    {
        $name = self::str($entry['route'] ?? null) ?? self::str($entry['name'] ?? null);
        if ($name === null) {
            return null;
        }

        $method = self::str($entry['method'] ?? null);
        $type = self::str($entry['type'] ?? null);

        if ($method !== null && ! str_starts_with($name, $method.' ')) {
            return $method.' '.$name;
        }

        return $type !== null && $type !== 'request' ? $type.': '.$name : $name;
    }

    /**
     * @param  array<array-key, mixed>  $location
     */
    private function location(array $location): ?string
    {
        $file = self::str($location['file'] ?? null);
        if ($file === null) {
            return null;
        }

        $result = $file.(is_numeric($location['line'] ?? null) ? ':'.(int) $location['line'] : '');

        $class = self::str($location['class'] ?? null);
        $function = self::str($location['function'] ?? null);
        if ($function !== null) {
            $result .= ' ('.($class !== null ? $class.'::' : '').$function.')';
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $metrics
     * @return array<string, string>
     */
    private function metrics(array $metrics): array
    {
        $result = [];

        foreach ($metrics as $key => $value) {
            if (! is_string($key) || ! (is_int($value) || is_float($value) || is_string($value))) {
                continue;
            }

            $isMs = str_ends_with($key, '_ms');
            $label = ucfirst(str_replace('_', ' ', $isMs ? substr($key, 0, -3) : $key));

            $result[$label] = $isMs && is_numeric($value)
                ? self::ms((float) $value)
                : (is_float($value) ? (string) round($value, 2) : (string) $value);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $explain
     */
    private function explain(array $explain): ?string
    {
        foreach (self::arr($explain['insights'] ?? null) as $insight) {
            $insight = self::arr($insight);
            $message = self::str($insight['message'] ?? null);
            if ($message === null) {
                continue;
            }

            $index = self::str($insight['suggested_index'] ?? null);

            return $message.($index !== null && ! str_contains($message, $index) ? ' (suggested index: '.$index.')' : '');
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $exception
     */
    private function exception(array $exception): ?string
    {
        $class = self::str($exception['class'] ?? null);
        $message = self::str($exception['message'] ?? null);

        if ($class === null && $message === null) {
            return null;
        }

        return trim(($class ?? '').($class !== null && $message !== null ? ': ' : '').($message ?? ''));
    }

    private function description(IssueRecord $issue): string
    {
        return self::str($issue->context['description'] ?? null) ?? '';
    }

    /** Normalized SQL only: the issue context never holds bindings. */
    private function sql(IssueRecord $issue): ?string
    {
        return self::str($issue->context['sql'] ?? null);
    }

    private function footer(): string
    {
        $app = self::str($this->config->get('app.name')) ?? 'Laravel';
        $env = self::str($this->config->get('app.env'));

        return 'Tracing · '.$app.($env !== null ? ' ('.$env.')' : '');
    }

    /**
     * Link to the issue page when the dashboard registers a known named route; null otherwise
     * (never a guessed URL that might 404). notifications.dashboard_url overrides the host.
     */
    public function dashboardUrl(int|string $issueId): ?string
    {
        if ($this->container === null || ! $this->policy->dashboardEnabled()
            || ! $this->container->bound('router') || ! $this->container->bound('url')) {
            return null;
        }

        try {
            /** @var Router $router */
            $router = $this->container->make('router');
            /** @var UrlGenerator $url */
            $url = $this->container->make('url');

            foreach (self::DASHBOARD_ROUTES as $name) {
                if (! $router->has($name)) {
                    continue;
                }

                $base = $this->policy->dashboardBaseUrl();

                return $base !== null
                    ? $base.'/'.ltrim($url->route($name, [$issueId], false), '/')
                    : $url->route($name, [$issueId]);
            }
        } catch (Throwable) {
            // A broken route definition must never prevent the notification.
        }

        return null;
    }

    /** Approximate, human window: "5 minutes", "hour", "3 hours", "2 days". */
    private static function window(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes === 1 ? 'minute' : $minutes.' minutes';
        }

        $hours = (int) round($minutes / 60);
        if ($hours < 48) {
            return $hours === 1 ? 'hour' : $hours.' hours';
        }

        return ((int) round($hours / 24)).' days';
    }

    public static function ms(float $ms): string
    {
        if ($ms >= 1000) {
            return round($ms / 1000, 2).' s';
        }

        return round($ms, $ms < 10 ? 2 : 1).' ms';
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Sample message for tracing:test-notification (critical, so every channel accepts it). */
    public function sample(): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Test notification from Tracing',
            severity: Severity::Critical,
            summary: 'If you can read this, the channel is configured correctly.',
            fields: [
                'Type' => 'Test',
                'Entry' => 'GET /orders',
                'Verdict' => '72% of 2.4 s spent in 38 queries',
                'Location' => 'app/Http/Controllers/OrderController.php:42',
                'Duration' => '2.4 s',
            ],
            sql: 'select * from `orders` where `user_id` = ?',
            aiRecommendation: null,
            url: null,
            occurrences: 1,
            footer: $this->footer(),
        );
    }
}
