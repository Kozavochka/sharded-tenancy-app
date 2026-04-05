<?php

return [
    'default' => 'shard_1',

    'shards' => [
        'shard_1' => [
            'connection' => 'tenant_shard_1',
            'label' => 'Shared small tenants',
            'accepting_new_tenants' => true,
        ],
        'shard_2' => [
            'connection' => 'tenant_shard_2',
            'label' => 'Large tenants / enterprise',
            'accepting_new_tenants' => true,
        ],
    ],
];
