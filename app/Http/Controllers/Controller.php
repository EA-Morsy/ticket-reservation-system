<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Provides Laravel policy authorization to application controllers.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
