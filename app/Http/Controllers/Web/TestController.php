<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class TestController extends Controller
{
    public function showRegisterForm()
    {
        return view('test-register');
    }
}
