<?php

declare(strict_types=1);

return [
    'devilsrage' => [
        'name' => "Devil's Rage",
        'icon' => 'fas fa-fire',
        'route_segment' => 'devilsrage',
        'entries' => [
            [
                'name' => 'My accounts',
                'icon' => 'fas fa-users',
                'route' => 'devilsrage.accounts.index',
            ],
            [
                'name' => 'Skill plans',
                'icon' => 'fas fa-graduation-cap',
                'route' => 'devilsrage.skillplans.index',
            ],
            [
                'name' => 'Corp layout',
                'icon' => 'fas fa-sitemap',
                'route' => 'devilsrage.layout',
                'permission' => 'devilsrage-leader',
            ],
            [
                'name' => 'FC planner',
                'icon' => 'fas fa-chess',
                'route' => 'devilsrage.planner',
                'permission' => 'devilsrage-leader',
            ],
        ],
    ],
];
