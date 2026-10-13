<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

class EnforceCsrfToken extends VerifyCsrfToken
{
    /**
     * Enforce CSRF validation in feature tests instead of Laravel's test bypass.
     */
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
