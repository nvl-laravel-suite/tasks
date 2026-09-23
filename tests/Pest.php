<?php

declare(strict_types=1);

use Nvl\Tasks\Tests\HttpTestCase;
use Nvl\Tasks\Tests\TenancyTestCase;
use Nvl\Tasks\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__.'/Feature');
pest()->extend(HttpTestCase::class)->in(__DIR__.'/Http');
pest()->extend(TenancyTestCase::class)->in(__DIR__.'/Tenancy');
