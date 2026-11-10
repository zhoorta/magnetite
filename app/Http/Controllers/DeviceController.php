<?php

namespace App\Http\Controllers;

use App\Support\DeviceGrants;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function create(Request $request): View
    {
        return view('pages.device', ['code' => $request->query('code'), 'approved' => false]);
    }

    /** The signed-in person types the code the TV shows; the TV then receives a token for this account. */
    public function store(Request $request): View
    {
        $data = $request->validate(['user_code' => ['required', 'string', 'max:20']]);

        if (! DeviceGrants::approve($data['user_code'], $request->user()->id)) {
            throw ValidationException::withMessages(['user_code' => 'That code is not valid or has expired. Check the TV for a new one.']);
        }

        return view('pages.device', ['code' => null, 'approved' => true]);
    }
}
