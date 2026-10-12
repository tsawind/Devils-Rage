<?php

declare(strict_types=1);

namespace App\Services\Seat;

use RuntimeException;

/**
 * SeAT knows the pilot but won't show them this (e.g. structure fuel for non-leaders).
 */
final class SeatForbiddenException extends RuntimeException {}
