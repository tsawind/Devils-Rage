<?php

declare(strict_types=1);

namespace App\Console\Commands\Signatures;

use App\Actions\Signatures\ArmSignatureAction;
use App\Console\Commands\AppCommand;
use Throwable;

/**
 * Patch 13: arms run out after 15 minutes without a jump; a number arming
 * took goes back to the pool.
 */
final class ReleaseExpiredArmsCommand extends AppCommand
{
    /** @var string */
    protected $signature = 'app:release-expired-arms';

    /** @var string */
    protected $description = 'Releases armed holes nobody jumped within 15 minutes';

    public function __construct(private readonly ArmSignatureAction $armSignatureAction)
    {
        parent::__construct();
    }

    /**
     * @throws Throwable
     */
    public function handle(): int
    {
        $this->armSignatureAction->releaseExpired();

        return self::SUCCESS;
    }
}
