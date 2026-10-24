<?php

declare(strict_types=1);

arch('strict types everywhere')
    ->expect('Anabil\Sherlock')
    ->toUseStrictTypes();

arch('no debugging calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'var_export', 'error_log'])
    ->not->toBeUsed();

arch('contracts are interfaces')
    ->expect('Anabil\Sherlock\Contracts')
    ->toBeInterfaces();

arch('value objects are final and readonly')
    ->expect('Anabil\Sherlock\Data')
    ->toBeClasses()
    ->toBeFinal()
    ->toBeReadonly();

arch('enums are backed enums')
    ->expect('Anabil\Sherlock\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

arch('null objects are final and implement contracts')
    ->expect('Anabil\Sherlock\Support\Null')
    ->toBeFinal();

arch('core does not depend on the HTTP layer')
    ->expect(['Anabil\Sherlock\Contracts', 'Anabil\Sherlock\Data', 'Anabil\Sherlock\Enums', 'Anabil\Sherlock\Support', 'Anabil\Sherlock\Privacy', 'Anabil\Sherlock\Pipeline'])
    ->not->toUse(['Illuminate\Http', 'Illuminate\Routing', 'Symfony\Component\HttpFoundation', 'Anabil\Sherlock\Http']);

arch('core does not use facades')
    ->expect(['Anabil\Sherlock\Contracts', 'Anabil\Sherlock\Data', 'Anabil\Sherlock\Enums', 'Anabil\Sherlock\Support', 'Anabil\Sherlock\Privacy', 'Anabil\Sherlock\Pipeline'])
    ->not->toUse('Illuminate\Support\Facades');

arch('redaction rules implement the contract')
    ->expect('Anabil\Sherlock\Privacy\Rules')
    ->toBeFinal()
    ->toImplement('Anabil\Sherlock\Contracts\RedactionRule');

arch('feature providers are service providers')
    ->expect('Anabil\Sherlock\Providers')
    ->toExtend('Illuminate\Support\ServiceProvider');
