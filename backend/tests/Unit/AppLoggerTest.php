<?php

use App\Support\AppLogger;

it('uses scan diagnostic default', function () {
    expect(AppLogger::shouldLogDiagnostics('scan'))->toBeTrue();
});

it('uses pretty as default log format', function () {
    expect(AppLogger::format())->toBe('pretty');
});
