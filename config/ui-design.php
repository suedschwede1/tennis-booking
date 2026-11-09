<?php

declare(strict_types=1);

return [
    'baseline' => base_path('ui-design-baseline.json'),
    'roots' => array_merge([resource_path('views'), resource_path('css'), public_path('css')],
        glob(base_path('app/Modules/*/resources/views'), GLOB_ONLYDIR) ?: [],
        glob(base_path('Modules/*/resources/views'), GLOB_ONLYDIR) ?: []),
    // Migrated paths can never use legacy exceptions.
    'migrated' => ['resources/views/components/ui/', 'resources/views/design/', 'resources/css/design-components.css', 'resources/css/members-admin.css', 'app/Modules/Members/resources/views/layout.blade.php'],
];
