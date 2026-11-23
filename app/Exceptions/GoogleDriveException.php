<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/** A Google Drive failure with a message meant for the user. */
class GoogleDriveException extends RuntimeException implements ShouldntReport {}
