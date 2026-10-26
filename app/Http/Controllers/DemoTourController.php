<?php

namespace App\Http\Controllers;

use App\Services\DemoTourService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DemoTourController extends Controller
{
    public function act(Request $request, DemoTourService $tours)
    {
        $user = $request->user();
        abort_unless($user && $user->company && $user->company->is_demo, 403);

        $data = $request->validate([
            'action' => ['required', Rule::in(['choose', 'next', 'back', 'skip', 'pause', 'exit', 'restart', 'resume', 'seen', 'sync'])],
            'mode' => ['nullable', Rule::in(['guided', 'explore'])],
            'route' => ['nullable', 'string', 'max:80'],
        ]);

        if ($data['action'] === 'choose') {
            $payload = $tours->choose($user, $data['mode'] ?? 'explore');
        } else {
            $payload = $tours->act($user, $data['action'], $data['route'] ?? null);
        }

        return response()->json($payload);
    }
}
