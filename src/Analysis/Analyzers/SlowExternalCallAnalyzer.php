<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * Outgoing HTTP call >= warningMs (critical >= criticalMs). One finding per host.
 */
final class SlowExternalCallAnalyzer implements Analyzer
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly float $warningMs = 1000,
        private readonly float $criticalMs = 5000,
    ) {}

    public function analyze(TraceData $trace): array
    {
        /** @var array<string, array{call: HttpCallRecord, count: int, total: float, failed: int}> $hosts */
        $hosts = [];

        foreach ($trace->httpCalls as $call) {
            if ($call->durationMs < $this->warningMs) {
                continue;
            }

            $host = $call->host() !== '' ? strtolower($call->host()) : 'unknown host';
            if (! isset($hosts[$host])) {
                $hosts[$host] = ['call' => $call, 'count' => 0, 'total' => 0.0, 'failed' => 0];
            } elseif ($call->durationMs > $hosts[$host]['call']->durationMs) {
                $hosts[$host]['call'] = $call;
            }

            $hosts[$host]['count']++;
            $hosts[$host]['total'] += $call->durationMs;
            $hosts[$host]['failed'] += $call->failed() ? 1 : 0;
        }

        $entry = Describe::entry($trace);
        $findings = [];

        foreach ($hosts as $host => $group) {
            $slowest = $group['call'];

            $findings[] = new Finding(
                type: IssueType::SlowExternalCall,
                severity: $slowest->durationMs >= $this->criticalMs ? Severity::Critical : Severity::Warning,
                title: 'Slow external call to '.$host.' ('.Describe::duration($slowest->durationMs)
                    .($group['count'] > 1 ? ', '.$group['count'].'×' : '').')'.Describe::where($trace, $slowest->location),
                description: sprintf(
                    '%d call(s) to %s took longer than %s (slowest %s %s, %s in total)%s.',
                    $group['count'],
                    $host,
                    Describe::duration($this->warningMs),
                    strtoupper($slowest->method),
                    Describe::duration($slowest->durationMs),
                    Describe::duration($group['total']),
                    $group['failed'] > 0 ? sprintf('; %d of them failed', $group['failed']) : '',
                ),
                fingerprint: $this->fingerprinter->issue(IssueType::SlowExternalCall, null, null, $entry, $host),
                metrics: [
                    'host' => $host,
                    'time_ms' => round($slowest->durationMs, 2),
                    'total_ms' => round($group['total'], 2),
                    'count' => $group['count'],
                    'failed' => $group['failed'],
                    'status_code' => $slowest->statusCode,
                    'threshold_ms' => $this->warningMs,
                ],
                location: $slowest->location,
                recommendation: 'Set an explicit short timeout (Http::timeout()/connectTimeout()) and a bounded retry, '
                    .'cache responses that do not change per request, run independent calls concurrently with Http::pool(), '
                    .'and move non-essential calls (webhooks, notifications, syncs) to a queued job.',
            );
        }

        return $findings;
    }
}
