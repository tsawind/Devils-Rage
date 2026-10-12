<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

final readonly class CommandRegistry
{
    public function __construct(
        private AccountCommand $account,
        private AlertCommand $alert,
        private AlertsCommand $alerts,
        private RouteCommand $route,
        private HelpCommand $help,
        private CapsCommand $caps,
        private AssetsCommand $assets,
        private FitCommand $fit,
        private CanFlyCommand $canFly,
    ) {}

    /** @return CommandDefinition[] */
    public function all(): array
    {
        return [$this->help, $this->account, $this->alert, $this->alerts, $this->route, $this->caps, $this->assets, $this->fit, $this->canFly];
    }
}
