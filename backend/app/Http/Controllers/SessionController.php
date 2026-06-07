<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(Request $request)
    {
        $search = strtolower(trim((string) $request->query('search', '')));

        $users = User::query()
            ->select('id', 'email', 'is_admin', 'created_at', 'updated_at')
            ->when($search !== '', fn($q) => $q->where('email', 'like', '%' . $search . '%'))
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($request->email));

        $user = User::where('email', $email)->first();

        if (!$user) {
            $isAdmin = $email === 'admin@gmail.com';
            $user = User::create([
                'email' => $email,
                'is_admin' => $isAdmin
            ]);
        }

        return response()->json([
            'success'  => true,
            'email'    => $user->email,
            'is_admin' => $user->is_admin,
        ]);
    }
}
