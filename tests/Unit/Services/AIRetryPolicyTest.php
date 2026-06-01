<?php

use App\Services\AIRetryPolicy;

it('calculates exponential backoff based on attempt', function () {
    $policy = new AIRetryPolicy(rateLimitBackoffBaseMs: 10);

    $attemptOne = $policy->calculateBackoff(1);
    $attemptTwo = $policy->calculateBackoff(2);
    $attemptThree = $policy->calculateBackoff(3);

    expect($attemptOne)->toBeGreaterThanOrEqual(10)
        ->and($attemptOne)->toBeLessThanOrEqual(1010)
        ->and($attemptTwo)->toBeGreaterThanOrEqual(20)
        ->and($attemptTwo)->toBeLessThanOrEqual(1020)
        ->and($attemptThree)->toBeGreaterThanOrEqual(40)
        ->and($attemptThree)->toBeLessThanOrEqual(1040);
});

it('retries on rate limit errors until success', function () {
    $sleepCalls = [];
    $policy = new AIRetryPolicy(
        rateLimitBackoffBaseMs: 10,
        connectionRetryBaseDelayMs: 5,
        sleepHandler: function (int $milliseconds) use (&$sleepCalls): void {
            $sleepCalls[] = $milliseconds;
        }
    );

    $attempts = 0;

    $result = $policy->execute(
        apiCall: function (int $attempt) use (&$attempts): string {
            $attempts = $attempt;

            if ($attempt < 3) {
                throw new Exception('429 too many requests');
            }

            return 'ok';
        },
        providerName: 'OpenRouter',
        maxRetries: 5
    );

    expect($result)->toBe('ok')
        ->and($attempts)->toBe(3)
        ->and($sleepCalls)->toHaveCount(2)
        ->and($sleepCalls[0])->toBeGreaterThanOrEqual(10)
        ->and($sleepCalls[0])->toBeLessThanOrEqual(1010)
        ->and($sleepCalls[1])->toBeGreaterThanOrEqual(20)
        ->and($sleepCalls[1])->toBeLessThanOrEqual(1020);
});

it('retries connection errors up to three attempts', function () {
    $sleepCalls = [];
    $policy = new AIRetryPolicy(
        rateLimitBackoffBaseMs: 10,
        connectionRetryBaseDelayMs: 5,
        sleepHandler: function (int $milliseconds) use (&$sleepCalls): void {
            $sleepCalls[] = $milliseconds;
        }
    );

    $attempts = 0;

    $result = $policy->execute(
        apiCall: function (int $attempt) use (&$attempts): string {
            $attempts = $attempt;

            if ($attempt < 3) {
                throw new Exception('connection timeout');
            }

            return 'done';
        },
        providerName: 'OpenRouter',
        maxRetries: 5
    );

    expect($result)->toBe('done')
        ->and($attempts)->toBe(3)
        ->and($sleepCalls)->toBe([5, 10]);
});

it('throws non-retryable errors immediately', function () {
    $policy = new AIRetryPolicy(rateLimitBackoffBaseMs: 10);

    $policy->execute(
        apiCall: function (): string {
            throw new Exception('invalid payload');
        },
        providerName: 'OpenRouter',
        maxRetries: 5
    );
})->throws(Exception::class, 'invalid payload');

it('detects rate limit errors', function () {
    $policy = new AIRetryPolicy();

    expect($policy->isRateLimitError(new Exception('429 Too Many Requests')))->toBeTrue()
        ->and($policy->isRateLimitError(new Exception('Rate limit exceeded')))->toBeTrue()
        ->and($policy->isRateLimitError(new Exception('general error')))->toBeFalse();
});

it('detects connection errors', function () {
    $policy = new AIRetryPolicy();

    expect($policy->isConnectionError(new Exception('Connection timeout')))->toBeTrue()
        ->and($policy->isConnectionError(new Exception('cURL error 28')))->toBeTrue()
        ->and($policy->isConnectionError(new Exception('HTTP 504')))->toBeTrue()
        ->and($policy->isConnectionError(new Exception('validation error')))->toBeFalse();
});
