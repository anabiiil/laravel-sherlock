<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Controllers;

use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\ReleaseRecord;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Http\Filters\Input;
use Anabil\Sherlock\Http\Support\Views;
use Anabil\Sherlock\Releases\ReleaseComparator;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Releases seen by stored traces and a before / after comparison of two of them (default: the
 * latest deploy vs the previous one). ?current= / ?previous= pick any two, ?type= narrows the
 * entries to requests, jobs or commands. Read-only: one releases query + ReleaseComparator.
 */
final class DeploysController
{
    private const RELEASES = 50;

    private const ENTRIES = 100;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly ReleaseComparator $comparator,
        private readonly Config $config,
        private readonly Views $views,
    ) {}

    public function __invoke(Request $request): View
    {
        $releases = $this->traces->releases(self::RELEASES);
        $names = array_map(static fn (ReleaseRecord $r): string => $r->release, $releases);
        $type = EntryType::tryFrom(Input::string($request, 'type'));

        $current = $this->known(Input::release($request, 'current'), $names) ?? ($names[0] ?? null);
        $previous = $this->known(Input::release($request, 'previous'), $names) ?? $this->before($current, $names);
        $same = $current !== null && $current === $previous;

        $comparison = $current !== null && $previous !== null && ! $same
            ? $this->comparator->compare($current, $previous, $type, self::ENTRIES)
            : null;

        $sampleRate = $this->config->get('sherlock.sample_rate', 0.05);
        $minSamples = $this->config->get('sherlock.releases.compare_min_samples', 20);
        $release = $this->config->get('sherlock.release');

        return $this->views->make('sherlock::dashboard.deploys.index', [
            'releases' => $releases,
            'comparison' => $comparison,
            'current' => $current,
            'previous' => $previous,
            'same' => $same,
            'type' => $type,
            'types' => EntryType::cases(),
            'appRelease' => is_string($release) && $release !== '' ? $release : null,
            'mode' => (string) $this->config->get('sherlock.mode', 'production'),
            'sampleRate' => is_numeric($sampleRate) ? (float) $sampleRate : 0.05,
            'minSamples' => is_numeric($minSamples) ? (int) $minSamples : 20,
        ]);
    }

    /**
     * A release from the query string, kept only when it exists (listed, or older than the list).
     *
     * @param  list<string>  $names
     */
    private function known(?string $release, array $names): ?string
    {
        if ($release === null) {
            return null;
        }

        return in_array($release, $names, true) || $this->traces->findRelease($release) !== null ? $release : null;
    }

    /**
     * The release deployed right before $release (the next one in the newest-first list).
     *
     * @param  list<string>  $names
     */
    private function before(?string $release, array $names): ?string
    {
        if ($release === null) {
            return null;
        }

        $index = array_search($release, $names, true);

        return $index === false ? null : ($names[$index + 1] ?? null);
    }
}
