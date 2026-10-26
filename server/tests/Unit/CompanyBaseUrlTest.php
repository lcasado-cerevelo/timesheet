<?php

namespace Tests\Unit;

use App\Models\Company;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyBaseUrlTest extends TestCase
{
    #[Test]
    public function a_site_in_a_folder_keeps_its_folder_in_the_links(): void
    {
        config(['app.url' => 'https://example.com/timesheet']);
        $this->assertSame('https://example.com/timesheet', (new Company(['domain' => 'example.com']))->baseUrl());
    }

    #[Test]
    public function a_company_domain_different_from_the_app_url_uses_its_own_domain(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->assertSame('https://tiempo.cerevelo.test', (new Company(['domain' => 'tiempo.cerevelo.test']))->baseUrl());
    }

    #[Test]
    public function without_a_domain_the_app_url_is_used(): void
    {
        config(['app.url' => 'https://example.com/timesheet/']);
        $this->assertSame('https://example.com/timesheet', (new Company())->baseUrl());
    }
}
