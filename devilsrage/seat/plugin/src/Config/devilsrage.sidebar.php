<?php

declare(strict_types=1);

return [
    'devilsrage' => [
        'name' => "Devil's Rage",
        'icon' => 'fas fa-fire',
        'route_segment' => 'devilsrage',
        'entries' => [
            [
                'name' => 'Home',
                'icon' => 'fas fa-home',
                'route' => 'devilsrage.home',
            ],
            [
                'name' => 'My stuff',
                'icon' => 'fas fa-box',
                'route' => 'devilsrage.stuff',
            ],
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
                'name' => 'SP farming',
                'icon' => 'fas fa-seedling',
                'route' => 'devilsrage.spfarm.index',
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
            [
                'name' => 'Suggestions',
                'icon' => 'fas fa-lightbulb',
                'route' => 'devilsrage.suggestions.index',
            ],
            [
                'name' => 'Reminders',
                'icon' => 'fas fa-bell',
                'route' => 'devilsrage.reminders.index',
                'permission' => 'devilsrage-leader',
            ],
            [
                'name' => 'Arrange menu',
                'icon' => 'fas fa-bars',
                'route' => 'devilsrage.menu',
            ],
        ],
    ],
];
