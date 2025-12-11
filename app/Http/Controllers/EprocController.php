<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class EprocController extends Controller
{
    public function index() {

        return view('document_analysis.eproc.index', []);
    }
}
