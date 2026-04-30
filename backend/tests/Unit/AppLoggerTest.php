<?php

use App\Support\AppLogger;

it('uses scan diagnostic default', function () {
    expect(AppLogger::shouldLogDiagnostics('scan'))->toBeTrue();
});
