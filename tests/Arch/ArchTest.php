<?php

declare(strict_types=1);

arch('strict types everywhere')
    ->expect('Anabil\Tracing')
    ->toUseStrictTypes();

arch('no debugging calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'var_export', 'error_log'])
    ->not->toBeUsed();

arch('contracts are interfaces')
    ->expect('Anabil\Tracing\Contracts')
    ->toBeInterfaces();

arch('value objects are final and readonly')
    ->expect('Anabil\Tracing\Data')
    ->toBeClasses()
    ->toBeFinal()
    ->toBeReadonly();

arch('enums are backed enums')
    ->expect('Anabil\Tracing\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

arch('null objects are final and implement contracts')
    ->expect('Anabil\Tracing\Support\Null')
    ->toBeFinal();

arch('core does not depend on the HTTP layer')
    ->expect(['Anabil\Tracing\Contracts', 'Anabil\Tracing\Data', 'Anabil\Tracing\Enums', 'Anabil\Tracing\Support', 'Anabil\Tracing\Privacy', 'Anabil\Tracing\Pipeline'])
    ->not->toUse(['Illuminate\Http', 'Illuminate\Routing', 'Symfony\Component\HttpFoundation', 'Anabil\Tracing\Http']);

arch('core does not use facades')
    ->expect(['Anabil\Tracing\Contracts', 'Anabil\Tracing\Data', 'Anabil\Tracing\Enums', 'Anabil\Tracing\Support', 'Anabil\Tracing\Privacy', 'Anabil\Tracing\Pipeline'])
    ->not->toUse('Illuminate\Support\Facades');

arch('redaction rules implement the contract')
    ->expect('Anabil\Tracing\Privacy\Rules')
    ->toBeFinal()
    ->toImplement('Anabil\Tracing\Contracts\RedactionRule');

arch('feature providers are service providers')
    ->expect('Anabil\Tracing\Providers')
    ->toExtend('Illuminate\Support\ServiceProvider');
