<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;

abstract class Controller
{
    use ApiResponse; //Injeção do trait de tratamento de respostas de API
}
