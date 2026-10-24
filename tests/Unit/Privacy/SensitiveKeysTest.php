<?php

declare(strict_types=1);

use Anabil\Tracing\Privacy\SensitiveKeys;

it('matches locked keys and keys containing them', function (string $key): void {
    expect((new SensitiveKeys)->matches($key))->toBeTrue();
})->with([
    'password', 'PASSWORD', 'password_confirmation', 'current_password', 'newPassword', 'user-password',
    'passwd', 'db_pwd', 'pwd', 'secret', 'client_secret', 'stripeSecretKey', 'token', 'access_token',
    'refresh_token', 'remember_token', 'X-Auth-Token', 'api_key', 'apiKey', 'API-KEY', 'apikey',
    'private_key', 'privateKey', 'authorization', 'credit_card', 'creditCard', 'card_number', 'cardNumber',
    'cvv', 'card_cvv', 'cvc', 'pin', 'user_pin', 'pinCode', 'pins', 'otp', 'otp_code', 'otpCode', 'ssn', 'user_ssn',
]);

it('does not over-match short words inside unrelated keys', function (string $key): void {
    expect((new SensitiveKeys)->matches($key))->toBeFalse();
})->with([
    'shipping', 'shipping_address', 'opinion', 'mapping', 'footprint', 'class_name', 'classname',
    'email', 'name', 'id', 'status', 'spinner', 'happiness', '',
]);

it('ignores integer keys', function (): void {
    expect((new SensitiveKeys)->matches(0))->toBeFalse();
});

it('adds user keys but can never remove locked ones', function (): void {
    $keys = new SensitiveKeys(['national_id', 'iban', '', 42, null]);

    expect($keys->matches('national_id'))->toBeTrue()
        ->and($keys->matches('user_national_id'))->toBeTrue()
        ->and($keys->matches('iban'))->toBeTrue()
        ->and($keys->matches('password'))->toBeTrue()
        ->and($keys->all())->toContain(...SensitiveKeys::LOCKED);
});

it('has the locked list from the plan', function (): void {
    expect(SensitiveKeys::LOCKED)->toBe([
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'access_token', 'refresh_token',
        'remember_token', 'private_key', 'client_secret', 'authorization', 'credit_card', 'card_number',
        'cvv', 'cvc', 'pin', 'otp', 'ssn',
    ]);
});
