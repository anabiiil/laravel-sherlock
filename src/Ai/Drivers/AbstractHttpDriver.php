<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Ai\Drivers;

use Anabil\Sherlock\Ai\AiProviderException;
use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Data\AiRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Shared HTTP plumbing for the drivers: resolved config (preset <- user overrides),
 * Laravel Http client with timeout / connect timeout, and error mapping to short
 * AiProviderException messages that never contain the API key.
 */
abstract class AbstractHttpDriver implements AiProvider
{
    private const DEFAULT_CONNECT_TIMEOUT = 10;

    /**
     * @param  array<string, mixed>  $config  resolved driver config (base_url, model, api_key, connect_timeout, ...)
     */
    public function __construct(
        protected readonly string $name,
        protected readonly array $config,
        protected readonly HttpFactory $http,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    protected function model(AiRequest $request): string
    {
        $model = $request->model ?? $this->string('model');

        if ($model === null || $model === '') {
            throw AiProviderException::misconfigured($this->name, 'no model configured (set sherlock.ai.model).');
        }

        return $model;
    }

    protected function baseUrl(): string
    {
        $url = $this->string('base_url');

        if ($url === null || $url === '') {
            throw AiProviderException::misconfigured($this->name, 'no base URL configured (set sherlock.ai.base_url).');
        }

        return rtrim($url, '/');
    }

    protected function apiKey(): ?string
    {
        $key = $this->string('api_key');

        return $key === '' ? null : $key;
    }

    protected function requireApiKey(): string
    {
        return $this->apiKey() ?? throw AiProviderException::misconfigured($this->name, 'no API key configured (set SHERLOCK_AI_KEY).');
    }

    protected function string(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) ? trim($value) : null;
    }

    /**
     * POST JSON and return the decoded body.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>
     */
    protected function post(string $url, array $body, array $headers, AiRequest $request): array
    {
        $timeout = max(1, $request->timeout);
        $connect = $this->config['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT;
        $connect = min($timeout, max(1, is_numeric($connect) ? (int) $connect : self::DEFAULT_CONNECT_TIMEOUT));

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->connectTimeout($connect)
                ->post($url, $body);
        } catch (ConnectionException) {
            // The underlying message can contain the URL / host details; keep ours short.
            throw AiProviderException::unreachable($this->name);
        }

        if ($response->failed()) {
            throw AiProviderException::http($this->name, $response->status(), $this->errorDetail($response));
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw AiProviderException::invalidResponse($this->name, 'body is not JSON.');
        }

        return $data;
    }

    protected function nonEmpty(string $content): string
    {
        if (trim($content) === '') {
            throw AiProviderException::invalidResponse($this->name, 'empty completion.');
        }

        return trim($content);
    }

    protected static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function errorDetail(Response $response): ?string
    {
        $data = $response->json();
        $detail = null;

        if (is_array($data)) {
            $error = $data['error'] ?? $data['message'] ?? null;
            $detail = match (true) {
                is_array($error) && is_string($error['message'] ?? null) => $error['message'],
                is_string($error) => $error,
                default => null,
            };
        }

        return $detail === null ? null : AiProviderException::scrub($detail, $this->apiKey());
    }
}
