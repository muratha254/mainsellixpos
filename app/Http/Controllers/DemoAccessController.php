<?php

namespace App\Http\Controllers;

use App\Services\DemoBusinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoAccessController extends Controller
{
    public function enter(Request $request, DemoBusinessService $demo)
    {
        $ready = $demo->ensure();
        Auth::login($ready['admin']);
        $request->session()->regenerate();
        $request->session()->put('current_branch_id', $ready['branch']->id);

        return redirect()->route('dashboard');
    }

    public function reset(Request $request, DemoBusinessService $demo)
    {
        $company = $request->user()->company;
        abort_unless($company && $company->is_demo, 403);
        $demo->reset($company);

        return redirect()->route('dashboard')->with('success', 'The demo shop was restored to its sample data. Records you added in this demo have been cleared.');
    }
}
