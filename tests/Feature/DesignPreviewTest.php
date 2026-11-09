<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DesignPreviewTest extends TestCase
{
    #[Test]
    public function preview_uses_shared_controls_and_accessible_error_associations(): void
    {
        $this->withCookie('locale', 'de')->get('/__design')->assertOk()
            ->assertSee('tc-button--primary', false)
            ->assertSee('aria-describedby="partner-error"', false)
            ->assertSee('aria-labelledby="preview-dialog-title"', false)
            ->assertSee(__('booking.design.title'));
    }

    #[Test]
    public function preview_is_unavailable_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->get('/__design')->assertNotFound();
    }
}
