<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Collectors;

use Anabil\Sherlock\Context\UnitLifecycle;
use Anabil\Sherlock\Contracts\Collector;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Contracts\WatchWindow;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Recording\Policies\IgnorePolicy;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HTTP requests, captured from framework events (no middleware to install):
 *
 *  - start: as early as possible. On FPM the provider starts the unit while booting, with
 *    LARAVEL_START as the start time (so bootstrap time is measured). Under Octane, on
 *    RequestReceived. Otherwise (tests, unusual kernels) on the Routing event.
 *  - RouteMatched: name becomes "METHOD /uri/{pattern}" (never the full URL), route name
 *    stored, ignore.routes applied.
 *  - RequestHandled: status code, duration and memory frozen (response is ready).
 *  - terminate (after the response was sent): redacted path, user id (only when a guard
 *    already resolved the user: never triggers a query), watch-window user filter re-check,
 *    then the trace is handed to the recorder.
 *
 * The ignore policy and watch window are resolved lazily from the container (only when a
 * route is matched / a watch reason exists), so booting the collector costs nothing.
 */
final class RequestCollector implements Collector
{
    public const OCTANE_REQUEST_RECEIVED = 'Laravel\Octane\Events\RequestReceived';

    public const OCTANE_REQUEST_TERMINATED = 'Laravel\Octane\Events\RequestTerminated';

    private bool $inFlight = false;

    private bool $routeResolved = false;

    private ?Request $request = null;

    /** @var list<string>|null memoized sensitive input values of the request in flight */
    private ?array $secrets = null;

    /** Shortest input value scrubbed from messages (shorter values would mangle normal text). */
    public const MIN_SECRET_LENGTH = 4;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly UnitLifecycle $lifecycle,
        private readonly Redactor $redactor,
        private readonly Container $container,
        private readonly ?string $triggerHeader = null,
    ) {}

    public function register(): void
    {
        $this->events->listen(Routing::class, $this->routing(...));
        $this->events->listen(RouteMatched::class, $this->routeMatched(...));
        $this->events->listen(RequestHandled::class, $this->handled(...));

        if (class_exists(self::OCTANE_REQUEST_RECEIVED)) {
            $this->events->listen(self::OCTANE_REQUEST_RECEIVED, $this->octaneReceived(...));
            $this->events->listen(self::OCTANE_REQUEST_TERMINATED, $this->octaneTerminated(...));
        }
    }

    public function isInFlight(): bool
    {
        return $this->inFlight;
    }

    /**
     * FPM: start while the framework boots. $startedAt = LARAVEL_START.
     */
    public function startFromBoot(Request $request, ?float $startedAt): void
    {
        $this->begin($request, $startedAt, false);
    }

    /** Time from LARAVEL_START until the application finished booting. */
    public function bootstrapped(float $startedAt): void
    {
        try {
            $context = $this->lifecycle->manager()->current();
            if ($this->inFlight && $context->isActive()) {
                $context->setBootstrapMs((microtime(true) - $startedAt) * 1000);
            }
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    /**
     * Values the request in flight sent under a sensitive key (password, token, ...), at any
     * depth of its query string / body. Computed lazily, only when an error log is captured,
     * so a value the redactor cannot recognize by shape still never reaches storage.
     *
     * @return list<string>
     */
    public function sensitiveInput(): array
    {
        if (! $this->inFlight || $this->request === null) {
            return [];
        }

        if ($this->secrets !== null) {
            return $this->secrets;
        }

        $secrets = [];
        try {
            $this->collectSecrets($this->request->all(), false, $secrets);
        } catch (Throwable) {
            // Unreadable body: nothing to add.
        }

        // Longest first, so a secret containing another one is replaced whole.
        $secrets = array_values(array_unique($secrets));
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->secrets = $secrets;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $secrets
     */
    private function collectSecrets(array $input, bool $sensitive, array &$secrets, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }

        foreach ($input as $key => $value) {
            $isSensitive = $sensitive || (is_string($key) && $this->redactor->isSensitiveKey($key));

            if (is_array($value)) {
                $this->collectSecrets($value, $isSensitive, $secrets, $depth + 1);
            } elseif ($isSensitive && (is_string($value) || is_int($value)) && strlen((string) $value) >= self::MIN_SECRET_LENGTH) {
                $secrets[] = (string) $value;
            }
        }
    }

    public function routing(Routing $event): void
    {
        $this->begin($event->request, null, true);
    }

    public function routeMatched(RouteMatched $event): void
    {
        try {
            if (! $this->inFlight || $this->routeResolved) {
                return;
            }
            $this->routeResolved = true;

            $manager = $this->lifecycle->manager();
            if ($manager->depth() !== 1 || ! $manager->current()->isActive()) {
                return;
            }

            $name = $event->route->getName();
            if ($name !== null && $this->container->make(IgnorePolicy::class)->ignoresRoute($name)) {
                $manager->discard();

                return;
            }

            $context = $manager->current();
            $context->setName($event->request->getMethod().' /'.ltrim($event->route->uri(), '/'));
            $context->setRoute($name);
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    public function handled(RequestHandled $event): void
    {
        $this->respond($event->response);
    }

    /**
     * Terminating callback (after the response was sent).
     */
    public function terminate(Container $app): void
    {
        if (! $this->inFlight) {
            return;
        }

        try {
            $this->finalize($app);
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        } finally {
            $this->inFlight = false;
            $this->routeResolved = false;
            $this->request = null;
            $this->secrets = null;
        }

        $this->lifecycle->end();
    }

    public function octaneReceived(object $event): void
    {
        try {
            $this->lifecycle->reset();
            $this->inFlight = false;
            $this->routeResolved = false;
            $this->request = null;
            $this->secrets = null;

            $request = property_exists($event, 'request') ? $event->request : null;
            if ($request instanceof Request) {
                $this->begin($request, null, true);
            }
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    public function octaneTerminated(object $event): void
    {
        try {
            $response = property_exists($event, 'response') ? $event->response : null;
            if ($response instanceof Response) {
                $this->respond($response);
            }

            $sandbox = property_exists($event, 'sandbox') ? $event->sandbox : null;
            if ($sandbox instanceof Container) {
                $this->terminate($sandbox);
            }

            $this->lifecycle->terminate();
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        } finally {
            $this->lifecycle->reset();
        }
    }

    private function begin(Request $request, ?float $startedAt, bool $resetPeakMemory): void
    {
        try {
            // Sub-requests (app()->handle() inside a unit) belong to the outer unit.
            if ($this->inFlight || $this->lifecycle->manager()->depth() > 0) {
                return;
            }

            $method = $request->getMethod();
            $path = $request->path();

            $this->inFlight = true;
            $this->routeResolved = false;
            $this->request = $request;
            $this->secrets = null;
            $this->lifecycle->deferUntilTerminate();

            $context = $this->lifecycle->begin(new EntryCandidate(
                EntryType::Request,
                $path,
                $method,
                $path,
                null,
                $this->triggerHeader !== null ? self::header($request, $this->triggerHeader) : null,
            ), $startedAt, $resetPeakMemory);

            if ($context->isActive()) {
                $context->setName($method.' '.($path === '/' ? '/' : '/'.$path));
                $context->setRequest($method, null);
            }
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    private function respond(Response $response): void
    {
        try {
            $manager = $this->lifecycle->manager();
            if (! $this->inFlight || $manager->depth() !== 1 || $manager->active() === null) {
                return;
            }

            $context = $manager->active();
            $status = $response->getStatusCode();
            $context->setStatusCode($status);
            if ($status >= 500) {
                $context->markFailed();
            }
            $context->freeze();
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    private function finalize(Container $app): void
    {
        $manager = $this->lifecycle->manager();
        $context = $manager->active();
        if ($manager->depth() !== 1 || $context === null) {
            return;
        }

        if ($this->request !== null) {
            $path = $this->request->path();
            $uri = $this->redactor->redactUrl($path === '/' ? '/' : '/'.$path);
            $context->setRequest($this->request->getMethod(), $uri);

            // No route matched (404, 405, an exception before routing): the name is still the
            // raw path, which may carry identifiers / tokens. Redacted here, after the response.
            if (! $this->routeResolved) {
                $context->setName($this->request->getMethod().' '.$uri);
            }
        }

        $userId = $this->resolvedUserId($app);
        $context->setUserId($userId);

        // Watch user filter: unknown at start, checked now that the user is known.
        if ($context->hasRecordReason(RecordReason::Watch)) {
            $state = $this->container->make(WatchWindow::class)->current();
            if ($state !== null && $state->userId !== null && (string) $state->userId !== (string) $userId) {
                $context->removeRecordReason(RecordReason::Watch);
            }
        }
    }

    /**
     * The authenticated user's id, only when a guard already holds the user: never
     * resolves a guard's user (no session read, no query).
     */
    private function resolvedUserId(Container $app): int|string|null
    {
        try {
            if (! $app->resolved('auth')) {
                return null;
            }

            $auth = $app->make('auth');
            if (! $auth instanceof AuthManager || ! $auth->hasResolvedGuards()) {
                return null;
            }

            $guard = $auth->guard();
            if (! $guard->hasUser()) {
                return null;
            }

            $id = $guard->user()?->getAuthIdentifier();

            return is_int($id) || is_string($id) ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function header(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
