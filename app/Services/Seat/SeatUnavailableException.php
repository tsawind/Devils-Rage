<?php

declare(strict_types=1);

namespace App\Services\Seat;

use RuntimeException;

/**
 * SeAT could not be asked (not configured, down, or refused the key).
 */
class SeatUnavailableException extends RuntimeException {}
